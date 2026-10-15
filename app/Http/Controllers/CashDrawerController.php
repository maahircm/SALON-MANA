<?php

namespace App\Http\Controllers;

use App\Models\CashDrawer;
use App\Models\CashDrawerSession;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Refund;
use App\Services\AuditLogger;
use App\Services\CashDrawerService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CashDrawerController extends Controller
{
    public function __construct(private CashDrawerService $drawers, private AuditLogger $audit) {}

    public function index(Request $request): View
    {
        abort_unless($request->user()->canAny(['pos.drawer', 'pos.close']), 403);
        $user = $request->user();
        $sessions = CashDrawerSession::with('drawer:id,name', 'opener:id,name')
            ->when(! $user->can('pos.close'), fn ($q) => $q->where('opened_by', $user->id))
            ->latest('id')->paginate(15);

        return view('cash.index', [
            'sessions' => $sessions,
            'current' => $this->drawers->openSessionFor($user),
            'drawers' => CashDrawer::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function open(Request $request): RedirectResponse
    {
        $this->authorize('pos.drawer');
        $data = $request->validate([
            'cash_drawer_id' => ['required', 'integer', 'exists:cash_drawers,id'],
            'opening_cash' => ['required', 'numeric', 'min:0', 'max:9999999', 'regex:/^\d+(\.\d{1,2})?$/'],
        ]);
        $session = $this->drawers->open($request->user(), CashDrawer::findOrFail($data['cash_drawer_id']), (string) $data['opening_cash']);

        return redirect()->route('cash.show', $session)->with('success', 'Drawer session opened.');
    }

    public function show(Request $request, CashDrawerSession $session): View
    {
        $this->authorizeView($request, $session);
        $session->load('drawer', 'opener:id,name', 'closer:id,name');

        return view('cash.show', [
            'session' => $session,
            'recon' => $session->isOpen() ? $this->drawers->reconciliation($session) : null,
            'movements' => $session->movements()->with('creator:id,name')->get(),
            'payments' => $session->payments()->with('invoice:id,invoice_number', 'method:id,name')->get(),
            'refunds' => $session->refunds()->with('invoice:id,invoice_number', 'method:id,name')->get(),
        ]);
    }

    public function movement(Request $request, CashDrawerSession $session): RedirectResponse
    {
        $this->authorize('pos.drawer');
        $data = $request->validate([
            'type' => ['required', Rule::in(['paid_in', 'paid_out'])],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999', 'regex:/^\d+(\.\d{1,2})?$/'],
            'reason' => ['required', 'string', 'max:255'],
        ]);
        $this->drawers->addMovement($session, $data['type'], (string) $data['amount'], $data['reason'], $request->user());

        return back()->with('success', 'Cash movement recorded.');
    }

    public function close(Request $request, CashDrawerSession $session): RedirectResponse
    {
        abort_unless($request->user()->canAny(['pos.drawer', 'pos.close']), 403);
        $data = $request->validate([
            'counted_cash' => ['required', 'numeric', 'min:0', 'max:9999999', 'regex:/^\d+(\.\d{1,2})?$/'],
            'closing_notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $this->drawers->close($session, (string) $data['counted_cash'], $data['closing_notes'] ?? null, $request->user());

        return redirect()->route('cash.show', $session)->with('success', 'Drawer closed and reconciled.');
    }

    public function drawers(): View
    {
        $this->authorize('pos.close');

        return view('cash.drawers', ['drawers' => CashDrawer::withCount('sessions')->orderBy('name')->get()]);
    }

    public function storeDrawer(Request $request): RedirectResponse
    {
        $this->authorize('pos.close');
        $data = $request->validate(['name' => ['required', 'string', 'max:100', 'unique:cash_drawers,name']]);
        $drawer = CashDrawer::create($data + ['is_active' => true]);
        $this->audit->log('cash_drawer.created', $drawer, null, $data);

        return back()->with('success', 'Drawer added.');
    }

    public function toggleDrawer(CashDrawer $drawer): RedirectResponse
    {
        $this->authorize('pos.close');
        $drawer->update(['is_active' => ! $drawer->is_active]);
        $this->audit->log($drawer->is_active ? 'cash_drawer.activated' : 'cash_drawer.deactivated', $drawer);

        return back()->with('success', 'Drawer updated.');
    }

    /** End-of-day view: sales, collections by method, refunds, receivables and drawer variances. */
    public function daily(Request $request): View
    {
        abort_unless($request->user()->canAny(['pos.close', 'finance.view']), 403);
        $date = CarbonImmutable::parse($request->query('date', now()->toDateString()));
        $from = $date->startOfDay();
        $to = $date->endOfDay();

        $issued = Invoice::where('status', Invoice::STATUS_ISSUED)->whereBetween('issued_at', [$from, $to])->get();
        $payments = Payment::with('method')->whereBetween('received_at', [$from, $to])->get();
        $refunds = Refund::with('method')->whereBetween('refunded_at', [$from, $to])->get();
        $byMethod = fn ($rows) => $rows->groupBy(fn ($r) => $r->method->name)->map(fn ($g) => Money::add(...$g->pluck('amount')->all()))->all();
        $collected = Money::add(...$payments->pluck('amount')->all());
        $refunded = Money::add(...$refunds->pluck('amount')->all());

        return view('cash.daily', [
            'date' => $date,
            'sales' => ['count' => $issued->count(), 'subtotal' => Money::add(...$issued->pluck('subtotal')->all()), 'discounts' => Money::add(...$issued->pluck('discount_total')->all()),
                'tax' => Money::add(...$issued->pluck('tax_total')->all()), 'total' => Money::add(...$issued->pluck('total')->all())],
            'voided' => Invoice::where('status', Invoice::STATUS_VOIDED)->whereBetween('voided_at', [$from, $to])->count(),
            'paymentsByMethod' => $byMethod($payments),
            'refundsByMethod' => $byMethod($refunds),
            'collected' => $collected,
            'refunded' => $refunded,
            'netCollected' => Money::sub($collected, $refunded),
            'outstanding' => Money::add(...Invoice::where('status', Invoice::STATUS_ISSUED)->whereColumn('amount_paid', '<', 'total')->get(['total', 'amount_paid'])->map(fn ($i) => Money::sub((string) $i->total, (string) $i->amount_paid))->all()),
            'sessions' => CashDrawerSession::with('drawer:id,name', 'opener:id,name')->whereBetween('opened_at', [$from, $to])->orderBy('opened_at')->get(),
            'methods' => PaymentMethod::count(),
        ]);
    }

    private function authorizeView(Request $request, CashDrawerSession $session): void
    {
        abort_unless($session->opened_by === $request->user()->id ? $request->user()->canAny(['pos.drawer', 'pos.close']) : $request->user()->can('pos.close'), 403);
    }
}
