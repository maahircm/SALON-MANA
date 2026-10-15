<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Refund;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Payment and refund history. */
class PaymentController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('pos.invoices');
        $range = fn ($q, string $column) => $q
            ->when($request->query('from'), fn ($w, $v) => $w->where($column, '>=', $v.' 00:00:00'))
            ->when($request->query('to'), fn ($w, $v) => $w->where($column, '<=', $v.' 23:59:59'));

        $payments = $range(Payment::with('invoice:id,invoice_number,customer_id', 'invoice.customer:id,name', 'method:id,name', 'receiver:id,name')
            ->when($request->query('method'), fn ($q, $v) => $q->where('payment_method_id', $v)), 'received_at')
            ->latest('id')->paginate(15, ['*'], 'payments_page')->withQueryString();
        $refunds = $range(Refund::with('invoice:id,invoice_number', 'method:id,name', 'refunder:id,name')
            ->when($request->query('method'), fn ($q, $v) => $q->where('payment_method_id', $v)), 'refunded_at')
            ->latest('id')->paginate(15, ['*'], 'refunds_page')->withQueryString();

        return view('payments.index', ['payments' => $payments, 'refunds' => $refunds, 'methods' => PaymentMethod::orderBy('sort_order')->get()]);
    }
}
