<?php

namespace App\Http\Controllers;

use App\Http\Requests\CheckoutRequest;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Service;
use App\Services\CashDrawerService;
use App\Services\CheckoutService;
use App\Services\InvoiceLifecycleService;
use App\Services\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PosController extends Controller
{
    public function __construct(private CheckoutService $checkout, private InvoiceLifecycleService $lifecycle) {}

    public function index(Request $request, CashDrawerService $drawers, SettingsService $settings): View
    {
        $this->authorize('pos.use');
        $user = $request->user();
        $cart = ['items' => [], 'customer' => null, 'draft_id' => null, 'notes' => null];

        if ($request->query('draft')) {
            $draft = Invoice::with('items', 'customer')->where('status', Invoice::STATUS_DRAFT)->findOrFail($request->query('draft'));
            abort_unless($draft->created_by === $user->id || $user->can('pos.void'), 403);
            $cart = [
                'draft_id' => $draft->id, 'customer' => $draft->customer, 'notes' => $draft->notes,
                'items' => $draft->items->map(fn (InvoiceItem $i) => [
                    'service_id' => $i->service_id, 'product_id' => $i->product_id, 'appointment_service_id' => $i->appointment_service_id, 'quantity' => $i->quantity, 'employee_id' => $i->employee_id,
                    'discount_type' => $i->discount_type, 'discount_value' => (string) $i->discount_value,
                ])->all(),
            ];
        } elseif ($request->query('appointment')) {
            $appointment = Appointment::with('customer', 'lines')->findOrFail($request->query('appointment'));
            $this->authorize('view', $appointment);
            $billed = InvoiceItem::whereIn('appointment_service_id', $appointment->lines->pluck('id'))
                ->whereHas('invoice', fn ($q) => $q->where('status', Invoice::STATUS_ISSUED))->pluck('appointment_service_id')->all();
            $cart['customer'] = $appointment->customer;
            $cart['items'] = $appointment->lines->reject(fn ($l) => in_array($l->id, $billed, true))->map(fn ($l) => [
                'service_id' => $l->service_id, 'appointment_service_id' => $l->id, 'quantity' => 1, 'employee_id' => $l->employee_id, 'discount_type' => 'none', 'discount_value' => '0',
            ])->values()->all();
        } elseif ($request->query('customer_id')) {
            $cart['customer'] = Customer::find($request->query('customer_id'));
        }

        return view('pos.index', [
            'cart' => $cart,
            'services' => Service::with('category:id,name')->where('is_active', true)->orderBy('name')->get(),
            'products' => Product::where('is_active', true)->where('is_sellable', true)->orderBy('name')->get(['id', 'sku', 'barcode', 'name', 'selling_price', 'tax_rate', 'current_stock', 'unit']),
            'employees' => Employee::where('status', '!=', 'terminated')->orderBy('name')->get(['id', 'name']),
            'methods' => PaymentMethod::where('is_active', true)->orderBy('sort_order')->get(),
            'session' => $drawers->openSessionFor($user),
            'drafts' => Invoice::with('customer:id,name')->where('status', Invoice::STATUS_DRAFT)->where('created_by', $user->id)->latest()->limit(10)->get(),
            'maxDiscount' => $settings->get('pos.max_discount_percent', '10'),
            'canDiscount' => $user->can('pos.discount'),
        ]);
    }

    public function store(CheckoutRequest $request): RedirectResponse
    {
        $data = $request->validated();

        if ($data['action'] === 'save_draft') {
            $draft = $this->checkout->saveDraft($data, $request->user());

            return redirect()->route('pos.index', ['draft' => $draft->id])->with('success', 'Draft saved. You can resume it from the POS screen.');
        }

        $result = $this->checkout->checkout($data, $request->user());
        $invoice = $result['invoice'];
        $message = $result['replay']
            ? "Invoice {$invoice->invoice_number} was already processed — nothing was charged twice."
            : "Invoice {$invoice->invoice_number} issued.";

        return redirect()->route('invoices.show', $invoice)->with('success', $message);
    }

    public function destroyDraft(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->authorize('pos.use');
        $this->lifecycle->discardDraft($invoice, $request->user());

        return redirect()->route('pos.index')->with('success', 'Draft discarded.');
    }
}
