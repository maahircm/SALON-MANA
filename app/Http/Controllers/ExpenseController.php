<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExpenseRequest;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Models\Supplier;
use App\Services\ExpenseService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExpenseController extends Controller
{
    public function __construct(private ExpenseService $expenses) {}

    public function index(Request $request): View
    {
        $this->authorize('expenses.view');
        $filters = $request->validate([
            'status' => ['nullable', 'in:'.implode(',', array_keys(Expense::STATUSES))],
            'category' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'q' => ['nullable', 'string', 'max:100'],
            'payment' => ['nullable', 'in:paid,unpaid'],
        ]);
        $query = Expense::query()->with('category:id,name', 'creator:id,name')
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['category'] ?? null, fn ($q, $v) => $q->where('expense_category_id', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('expense_date', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('expense_date', '<=', $v))
            ->when($filters['payment'] ?? null, fn ($q, $v) => $q->where('payment_status', $v))
            ->when($filters['q'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('description', 'like', "%{$s}%")->orWhere('expense_number', 'like', "%{$s}%")));
        $total = Money::add(...(clone $query)->where('status', Expense::APPROVED)->pluck('amount')->map(fn ($a) => (string) $a)->all());

        return view('expenses.index', [
            'expenses' => $query->latest('expense_date')->latest('id')->paginate(20)->withQueryString(),
            'categories' => ExpenseCategory::orderBy('name')->get(),
            'approvedTotal' => $total,
            'pendingCount' => Expense::where('status', Expense::PENDING)->count(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('expenses.create');

        return view('expenses.form', ['expense' => new Expense(['expense_date' => today()]), ...$this->lookups()]);
    }

    public function store(ExpenseRequest $request): RedirectResponse
    {
        $expense = $this->expenses->submit($request->safe()->except('receipt'), $request->user(), $request->file('receipt'));

        return redirect()->route('expenses.show', $expense)->with('success', "Expense {$expense->expense_number} submitted for approval.");
    }

    public function show(Expense $expense): View
    {
        $this->authorize('expenses.view');
        $expense->load('category', 'supplier', 'paymentMethod', 'creator', 'decider', 'events.user');

        return view('expenses.show', [
            'expense' => $expense,
            'canDecide' => $expense->isPending() && $this->expenses->canDecide($expense, auth()->user()),
            'paymentMethods' => PaymentMethod::where('is_active', true)->where('type', '!=', 'loyalty')->orderBy('sort_order')->get(),
        ]);
    }

    public function edit(Expense $expense): View|RedirectResponse
    {
        $this->authorize('expenses.create');
        if (! $expense->isPending()) {
            return redirect()->route('expenses.show', $expense)->with('error', 'Only a pending expense can be edited.');
        }

        return view('expenses.form', ['expense' => $expense, ...$this->lookups()]);
    }

    public function update(ExpenseRequest $request, Expense $expense): RedirectResponse
    {
        $this->expenses->update($expense, $request->safe()->except('receipt'), $request->user(), $request->file('receipt'));

        return redirect()->route('expenses.show', $expense)->with('success', 'Expense updated.');
    }

    public function approve(Request $request, Expense $expense): RedirectResponse
    {
        $this->authorize('expenses.approve');
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $this->expenses->approve($expense, $request->user(), $data['note'] ?? null);

        return back()->with('success', 'Expense approved.');
    }

    public function reject(Request $request, Expense $expense): RedirectResponse
    {
        $this->authorize('expenses.approve');
        $data = $request->validate(['note' => ['required', 'string', 'max:500']]);
        $this->expenses->reject($expense, $request->user(), $data['note']);

        return back()->with('success', 'Expense rejected.');
    }

    public function cancel(Request $request, Expense $expense): RedirectResponse
    {
        abort_unless($request->user()->can('expenses.approve') || ($request->user()->can('expenses.create') && $expense->created_by === $request->user()->id), 403);
        $this->expenses->cancel($expense, $request->user(), $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null);

        return back()->with('success', 'Expense cancelled.');
    }

    public function pay(Request $request, Expense $expense): RedirectResponse
    {
        $this->authorize('expenses.approve');
        $data = $request->validate([
            'payment_method_id' => ['required', 'exists:payment_methods,id'],
            'paid_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'payment_reference' => ['nullable', 'string', 'max:100'],
        ]);
        $this->expenses->markPaid($expense, PaymentMethod::where('type', '!=', 'loyalty')->findOrFail($data['payment_method_id']), $data['paid_on'], $data['payment_reference'] ?? null, $request->user());

        return back()->with('success', 'Expense marked as paid.');
    }

    /** Receipts live on a private disk and are only served through this authorised route. */
    public function receipt(Expense $expense): StreamedResponse
    {
        $this->authorize('expenses.view');
        abort_unless($expense->hasReceipt() && Storage::disk(ExpenseService::RECEIPT_DISK)->exists($expense->receipt_path), 404);

        return Storage::disk(ExpenseService::RECEIPT_DISK)->response($expense->receipt_path, $expense->receipt_name, [
            'Content-Type' => $expense->receipt_mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'",
            'Cache-Control' => 'private, no-store',
        ], 'inline');
    }

    /** @return array<string, mixed> */
    private function lookups(): array
    {
        return ['categories' => ExpenseCategory::where('is_active', true)->orderBy('name')->get(), 'suppliers' => Supplier::where('is_active', true)->orderBy('name')->get(['id', 'name'])];
    }
}
