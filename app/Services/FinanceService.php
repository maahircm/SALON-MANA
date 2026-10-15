<?php

namespace App\Services;

use App\Models\Commission;
use App\Models\CommissionAdjustment;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\StockMovement;
use App\Support\DateRange;
use App\Support\Money;
use App\Support\SqlDates;
use Illuminate\Support\Facades\DB;

/**
 * Operating financial summary for a period. This is a management view derived from invoices, payments, refunds,
 * the stock ledger, approved expenses and accrued commissions; it is not a double-entry general ledger.
 *
 * Definitions: revenue is invoiced value excluding tax (issued invoices, by issue date) less the revenue value of
 * refunds on issued invoices; cash collected is money received less money refunded (loyalty-point settlements are
 * shown separately); cost of goods sold is the ledger cost of stock consumed (sales and service usage, net of returns).
 */
class FinanceService
{
    /**
     * @return array{
     *   invoice_count: int, gross_sales: string, tax_collected: string, net_sales: string, sales_returns: string, net_revenue: string,
     *   payments_received: string, refunds_paid: string, net_cash_collected: string, points_settled: string, receivables: string, receivable_count: int,
     *   cogs: string, gross_profit: string, expenses: string, expenses_by_category: array<string, string>, expenses_paid_out: string,
     *   commissions: string, operating_costs: string, net_result: string
     * }
     */
    public function summary(DateRange $range): array
    {
        $issued = fn () => Invoice::query()->where('status', Invoice::STATUS_ISSUED)->whereBetween('issued_at', [$range->from, $range->to]);
        $grossSales = $this->sum($issued(), 'total');
        $tax = $this->sum($issued(), 'tax_total');
        $netSales = Money::sub($grossSales, $tax);
        $returns = $this->salesReturns($range);
        $netRevenue = Money::sub($netSales, $returns);

        $payments = fn () => Payment::query()->whereBetween('received_at', [$range->from, $range->to])->join('payment_methods', 'payment_methods.id', '=', 'payments.payment_method_id');
        $refunds = fn () => Refund::query()->whereBetween('refunded_at', [$range->from, $range->to])->join('payment_methods', 'payment_methods.id', '=', 'refunds.payment_method_id');
        $received = $this->sum($payments()->where('payment_methods.type', '!=', 'loyalty'), 'payments.amount');
        $refundsPaid = $this->sum($refunds()->where('payment_methods.type', '!=', 'loyalty'), 'refunds.amount');

        $receivables = Invoice::query()->where('status', Invoice::STATUS_ISSUED)->whereColumn('amount_paid', '<', 'total');
        $cogs = $this->money((float) StockMovement::query()->whereIn('type', [StockMovement::SALE, StockMovement::SERVICE_USAGE, StockMovement::SALE_RETURN, StockMovement::VOID_REVERSAL])
            ->whereBetween('created_at', [$range->from, $range->to])->sum(DB::raw('-quantity * unit_cost')));

        $approved = fn () => Expense::query()->where('status', Expense::APPROVED)->whereDate('expense_date', '>=', $range->from->toDateString())->whereDate('expense_date', '<=', $range->to->toDateString());
        $byCategory = $approved()->join('expense_categories', 'expense_categories.id', '=', 'expenses.expense_category_id')
            ->groupBy('expense_categories.name')->orderBy('expense_categories.name')->selectRaw('expense_categories.name as name, SUM(expenses.amount) as total')->pluck('total', 'name')
            ->map(fn ($v) => $this->money((float) $v))->all();
        $expenses = $this->sum($approved(), 'amount');
        $commissions = Money::add(
            $this->sum(Commission::query()->whereBetween('accrued_at', [$range->from, $range->to]), 'amount'),
            $this->sum(CommissionAdjustment::query()->whereBetween('created_at', [$range->from, $range->to]), 'amount'),
        );
        $grossProfit = Money::sub($netRevenue, $cogs);
        $operating = Money::add($expenses, $commissions);

        return [
            'invoice_count' => $issued()->count(), 'gross_sales' => $grossSales, 'tax_collected' => $tax, 'net_sales' => $netSales, 'sales_returns' => $returns, 'net_revenue' => $netRevenue,
            'payments_received' => $received, 'refunds_paid' => $refundsPaid, 'net_cash_collected' => Money::sub($received, $refundsPaid),
            'points_settled' => $this->sum($payments()->where('payment_methods.type', 'loyalty'), 'payments.amount'),
            'receivables' => $this->sum(clone $receivables, DB::raw('total - amount_paid')), 'receivable_count' => $receivables->count(),
            'cogs' => $cogs, 'gross_profit' => $grossProfit,
            'expenses' => $expenses, 'expenses_by_category' => $byCategory,
            'expenses_paid_out' => $this->sum(Expense::query()->where('status', Expense::APPROVED)->where('payment_status', 'paid')->whereDate('paid_on', '>=', $range->from->toDateString())->whereDate('paid_on', '<=', $range->to->toDateString()), 'amount'),
            'commissions' => $commissions, 'operating_costs' => $operating, 'net_result' => Money::sub($grossProfit, $operating),
        ];
    }

    /** Revenue value (excluding tax) of refunds made in the period against invoices that are still issued. */
    public function salesReturns(DateRange $range): string
    {
        $rows = DB::table('refund_items')
            ->join('refunds', 'refunds.id', '=', 'refund_items.refund_id')
            ->join('invoices', 'invoices.id', '=', 'refunds.invoice_id')
            ->join('invoice_items', 'invoice_items.id', '=', 'refund_items.invoice_item_id')
            ->where('invoices.status', Invoice::STATUS_ISSUED)
            ->whereBetween('refunds.refunded_at', [$range->from, $range->to])
            ->get(['refund_items.amount', 'invoice_items.line_total', 'invoice_items.tax_amount']);

        $total = 0;
        foreach ($rows as $row) {
            $line = Money::toMinor((string) $row->line_total);
            $refunded = Money::toMinor((string) $row->amount);
            $net = $line - Money::toMinor((string) $row->tax_amount);
            $total += $line === 0 ? 0 : intdiv(2 * $refunded * $net + $line, 2 * $line);
        }

        return Money::fromMinor($total);
    }

    /**
     * Net revenue per day (for dashboard charts): invoiced ex-tax and cash collected net of cash refunds.
     *
     * @return array<string, array{revenue: string, collected: string}>
     */
    public function dailySeries(DateRange $range): array
    {
        $dayInvoice = SqlDates::period('issued_at', 'day');
        $revenue = Invoice::query()->where('status', Invoice::STATUS_ISSUED)->whereBetween('issued_at', [$range->from, $range->to])
            ->selectRaw("{$dayInvoice} as day, SUM(total - tax_total) as amount")->groupBy('day')->pluck('amount', 'day');
        $dayPay = SqlDates::period('payments.received_at', 'day');
        $collected = Payment::query()->join('payment_methods', 'payment_methods.id', '=', 'payments.payment_method_id')->where('payment_methods.type', '!=', 'loyalty')
            ->whereBetween('received_at', [$range->from, $range->to])->selectRaw("{$dayPay} as day, SUM(payments.amount) as amount")->groupBy('day')->pluck('amount', 'day');

        $series = [];
        for ($d = $range->from->startOfDay(); $d <= $range->to; $d = $d->addDay()) {
            $key = $d->toDateString();
            $series[$key] = ['revenue' => $this->money((float) ($revenue[$key] ?? 0)), 'collected' => $this->money((float) ($collected[$key] ?? 0))];
        }

        return $series;
    }

    private function sum($query, $column): string
    {
        return $this->money((float) $query->sum($column));
    }

    private function money(float $value): string
    {
        return number_format(round($value, 2), 2, '.', '');
    }
}
