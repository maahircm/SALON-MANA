<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Services\AuditLogger;
use App\Services\InvoiceLifecycleService;
use App\Services\PaymentService;
use App\Services\RefundService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class InvoiceController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('pos.invoices');
        $invoices = Invoice::with('customer:id,name,customer_number')
            ->where('status', '!=', Invoice::STATUS_DRAFT)
            ->when($request->query('q'), fn ($q, $s) => $q->where(fn ($w) => $w->where('invoice_number', 'like', "%{$s}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$s}%")->orWhere('customer_number', 'like', "%{$s}%"))))
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('payment_status'), fn ($q, $v) => $q->where('payment_status', $v))
            ->when($request->query('customer_id'), fn ($q, $v) => $q->where('customer_id', $v))
            ->when($request->query('from'), fn ($q, $v) => $q->where('issued_at', '>=', $v.' 00:00:00'))
            ->when($request->query('to'), fn ($q, $v) => $q->where('issued_at', '<=', $v.' 23:59:59'))
            ->latest('id')->paginate(20)->withQueryString();

        return view('invoices.index', compact('invoices'));
    }

    public function show(Invoice $invoice): View
    {
        $this->authorize('pos.invoices');
        abort_if($invoice->isDraft(), 404);
        $invoice->load('customer', 'items.employee:id,name', 'payments.method', 'payments.receiver:id,name', 'refunds.method', 'refunds.refunder:id,name', 'refunds.items', 'issuer:id,name');

        return view('invoices.show', [
            'invoice' => $invoice,
            'methods' => PaymentMethod::where('is_active', true)->orderBy('sort_order')->get(),
            'refundedQty' => $invoice->refunds->flatMap->items->groupBy('invoice_item_id')->map(fn ($rows) => (int) $rows->sum('quantity')),
        ]);
    }

    /** Printable receipt. The first print is the original; later prints are marked as duplicates and audited. */
    public function receipt(Request $request, Invoice $invoice, AuditLogger $audit): View
    {
        $this->authorize('pos.invoices');
        abort_unless($invoice->isIssued() || $invoice->isVoided(), 404);
        $isDuplicate = $invoice->print_count > 0;
        $invoice->forceFill(['print_count' => $invoice->print_count + 1, 'last_printed_at' => now()])->save();
        if ($isDuplicate) {
            $audit->log('invoice.receipt_reprinted', $invoice, null, ['invoice_number' => $invoice->invoice_number, 'print_count' => $invoice->print_count]);
        }
        $invoice->load('customer', 'coupon', 'items', 'payments.method', 'refunds', 'issuer:id,name');

        return view('invoices.receipt', ['invoice' => $invoice, 'isDuplicate' => $isDuplicate]);
    }

    public function storePayment(Request $request, Invoice $invoice, PaymentService $payments): RedirectResponse
    {
        $this->authorize('pos.use');
        $data = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'payments' => ['required', 'array', 'min:1', 'max:6'],
            'payments.*.payment_method_id' => ['required', 'integer'],
            'payments.*.amount' => ['required', 'numeric', 'min:0.01', 'max:9999999'],
            'payments.*.reference' => ['nullable', 'string', 'max:100'],
            'payments.*.tendered' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
        ]);
        $payments->addPayments($invoice, $data['payments'], $request->user(), $data['idempotency_key']);

        return redirect()->route('invoices.show', $invoice)->with('success', 'Payment recorded.');
    }

    public function storeRefund(Request $request, Invoice $invoice, RefundService $refunds): RedirectResponse
    {
        $this->authorize('pos.refund');
        $data = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'payment_method_id' => ['required', 'integer', Rule::exists('payment_methods', 'id')],
            'reason' => ['required', 'string', 'max:500'],
            'reference' => ['nullable', 'string', 'max:100'],
            'quantities' => ['required', 'array'],
            'quantities.*' => ['nullable', 'integer', 'min:0', 'max:99'],
            'restock' => ['nullable', 'array'],
            'restock.*' => ['nullable', 'boolean'],
        ]);
        $refund = $refunds->refund($invoice, $data['quantities'], PaymentMethod::findOrFail($data['payment_method_id']), $data['reason'], $data['reference'] ?? null, $data['idempotency_key'], $request->user(), $data['restock'] ?? []);

        return redirect()->route('invoices.show', $invoice)->with('success', "Refund {$refund->refund_number} of ".money($refund->amount).' recorded.');
    }

    public function void(Request $request, Invoice $invoice, InvoiceLifecycleService $lifecycle): RedirectResponse
    {
        $this->authorize('pos.void');
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $lifecycle->void($invoice, $data['reason'], $request->user());

        return redirect()->route('invoices.show', $invoice)->with('success', 'Invoice voided.');
    }

    public static function newKey(): string
    {
        return (string) Str::uuid();
    }
}
