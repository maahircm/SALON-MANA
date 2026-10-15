<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Models\Invoice;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\DateRange;
use App\Support\Money;
use App\Support\SqlDates;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The business reports. Every report is a permission-checked definition plus a row builder that returns plain arrays,
 * so the same rows feed the screen (paginated), the print view and the CSV export.
 * Money columns hold decimal strings; a report returns at most MAX_ROWS rows (narrow the period if it is hit).
 */
class ReportCatalog
{
    public const MAX_ROWS = 20000;

    public function __construct(private FinanceService $finance) {}

    /**
     * @return array<string, array{title: string, group: string, description: string, permission: string, dated: bool, period: bool, columns: array<string, array{0: string, 1: string}>}>
     */
    public function definitions(): array
    {
        $c = fn (array $cols) => $cols;

        return [
            'sales_summary' => ['title' => 'Sales summary', 'group' => 'Sales', 'description' => 'Invoiced sales by day, week or month.', 'permission' => 'reports.financial', 'dated' => true, 'period' => true,
                'columns' => $c(['period' => ['Period', 'text'], 'invoices' => ['Invoices', 'int'], 'subtotal' => ['Subtotal', 'money'], 'discounts' => ['Discounts', 'money'], 'tax' => ['Tax', 'money'], 'total' => ['Total', 'money']])],
            'payments_by_method' => ['title' => 'Payments by method', 'group' => 'Sales', 'description' => 'Money received and refunded per payment method.', 'permission' => 'reports.financial', 'dated' => true, 'period' => false,
                'columns' => $c(['method' => ['Method', 'text'], 'payments' => ['Payments', 'int'], 'collected' => ['Received', 'money'], 'refunds' => ['Refunded', 'money'], 'net' => ['Net', 'money']])],
            'sales_by_service' => ['title' => 'Sales by service', 'group' => 'Sales', 'description' => 'Revenue (excluding tax) per service sold.', 'permission' => 'reports.view', 'dated' => true, 'period' => false,
                'columns' => $c(['item' => ['Service', 'text'], 'quantity' => ['Sold', 'int'], 'revenue' => ['Revenue (excl. tax)', 'money']])],
            'sales_by_product' => ['title' => 'Sales by product', 'group' => 'Sales', 'description' => 'Revenue (excluding tax) per retail product sold.', 'permission' => 'reports.view', 'dated' => true, 'period' => false,
                'columns' => $c(['item' => ['Product', 'text'], 'quantity' => ['Sold', 'int'], 'revenue' => ['Revenue (excl. tax)', 'money']])],
            'refunds' => ['title' => 'Refunds', 'group' => 'Sales', 'description' => 'Every refund issued in the period.', 'permission' => 'reports.financial', 'dated' => true, 'period' => false,
                'columns' => $c(['number' => ['Refund', 'text'], 'date' => ['Date', 'datetime'], 'invoice' => ['Invoice', 'text'], 'method' => ['Method', 'text'], 'amount' => ['Amount', 'money'], 'reason' => ['Reason', 'text'], 'by' => ['By', 'text']])],
            'outstanding_invoices' => ['title' => 'Outstanding invoices', 'group' => 'Sales', 'description' => 'Issued invoices with a balance still owed (current position).', 'permission' => 'reports.financial', 'dated' => false, 'period' => false,
                'columns' => $c(['number' => ['Invoice', 'text'], 'customer' => ['Customer', 'text'], 'issued' => ['Issued', 'date'], 'total' => ['Total', 'money'], 'paid' => ['Paid', 'money'], 'balance' => ['Balance', 'money'], 'age' => ['Days outstanding', 'int']])],
            'cashier_closing' => ['title' => 'Cashier closing', 'group' => 'Sales', 'description' => 'Closed cash drawer sessions with expected, counted and variance.', 'permission' => 'pos.close', 'dated' => true, 'period' => false,
                'columns' => $c(['cashier' => ['Cashier', 'text'], 'opened' => ['Opened', 'datetime'], 'closed' => ['Closed', 'datetime'], 'opening' => ['Opening cash', 'money'], 'expected' => ['Expected', 'money'], 'counted' => ['Counted', 'money'], 'variance' => ['Variance', 'money']])],
            'appointment_status' => ['title' => 'Appointments by status', 'group' => 'Appointments', 'description' => 'How appointments scheduled in the period ended up.', 'permission' => 'reports.view', 'dated' => true, 'period' => false,
                'columns' => $c(['status' => ['Status', 'text'], 'count' => ['Appointments', 'int'], 'share' => ['Share', 'percent']])],
            'cancellations' => ['title' => 'Cancellations and no-shows', 'group' => 'Appointments', 'description' => 'Cancelled and no-show appointments with reasons.', 'permission' => 'reports.view', 'dated' => true, 'period' => false,
                'columns' => $c(['number' => ['Appointment', 'text'], 'start' => ['Scheduled', 'datetime'], 'status' => ['Status', 'text'], 'late' => ['Late cancellation', 'text'], 'reason' => ['Reason', 'text']])],
            'new_vs_returning' => ['title' => 'New vs returning customers', 'group' => 'Customers', 'description' => 'Monthly new registrations and how many paying customers were returning.', 'permission' => 'reports.view', 'dated' => true, 'period' => false,
                'columns' => $c(['month' => ['Month', 'text'], 'new' => ['New customers', 'int'], 'active' => ['Customers who bought', 'int'], 'returning' => ['Of whom returning', 'int'], 'share' => ['Returning share', 'percent']])],
            'customer_ltv' => ['title' => 'Customer lifetime value', 'group' => 'Customers', 'description' => 'Top customers by payments received minus refunds (all time).', 'permission' => 'reports.financial', 'dated' => false, 'period' => false,
                'columns' => $c(['number' => ['Customer no.', 'text'], 'name' => ['Customer', 'text'], 'visits' => ['Invoices', 'int'], 'value' => ['Lifetime value', 'money'], 'last' => ['Last invoice', 'date']])],
            'employee_productivity' => ['title' => 'Employee productivity', 'group' => 'Staff', 'description' => 'Services and revenue (excluding tax) attributed to each employee.', 'permission' => 'reports.view', 'dated' => true, 'period' => false,
                'columns' => $c(['employee' => ['Employee', 'text'], 'items' => ['Items sold', 'int'], 'revenue' => ['Revenue (excl. tax)', 'money'], 'average' => ['Average per item', 'money']])],
            'commissions' => ['title' => 'Commissions', 'group' => 'Staff', 'description' => 'Commission accrued and adjusted per employee.', 'permission' => 'employees.commissions', 'dated' => true, 'period' => false,
                'columns' => $c(['employee' => ['Employee', 'text'], 'accrued' => ['Accrued', 'money'], 'adjustments' => ['Adjustments', 'money'], 'net' => ['Net commission', 'money']])],
            'expenses_by_category' => ['title' => 'Expenses by category', 'group' => 'Finance', 'description' => 'Approved expenses per category.', 'permission' => 'reports.financial', 'dated' => true, 'period' => false,
                'columns' => $c(['category' => ['Category', 'text'], 'count' => ['Expenses', 'int'], 'total' => ['Total', 'money']])],
            'profitability' => ['title' => 'Profitability by month', 'group' => 'Finance', 'description' => 'Net revenue, cost of goods, expenses and estimated net result per month (up to 36 months).', 'permission' => 'reports.financial', 'dated' => true, 'period' => false,
                'columns' => $c(['month' => ['Month', 'text'], 'revenue' => ['Net revenue', 'money'], 'cogs' => ['Cost of goods', 'money'], 'gross' => ['Gross profit', 'money'], 'costs' => ['Expenses + commissions', 'money'], 'net' => ['Net result', 'money']])],
            'inventory_valuation' => ['title' => 'Inventory valuation', 'group' => 'Inventory', 'description' => 'Stock on hand at average cost (current position).', 'permission' => 'inventory.costs', 'dated' => false, 'period' => false,
                'columns' => $c(['sku' => ['SKU', 'text'], 'product' => ['Product', 'text'], 'stock' => ['On hand', 'qty'], 'cost' => ['Average cost', 'money'], 'value' => ['Value', 'money']])],
            'stock_movements' => ['title' => 'Stock movements', 'group' => 'Inventory', 'description' => 'Stock ledger totals by movement type.', 'permission' => 'inventory.view', 'dated' => true, 'period' => false,
                'columns' => $c(['type' => ['Movement', 'text'], 'count' => ['Movements', 'int'], 'in' => ['Quantity in', 'qty'], 'out' => ['Quantity out', 'qty']])],
            'low_stock' => ['title' => 'Low stock', 'group' => 'Inventory', 'description' => 'Active products at or below their reorder level (current position).', 'permission' => 'inventory.view', 'dated' => false, 'period' => false,
                'columns' => $c(['sku' => ['SKU', 'text'], 'product' => ['Product', 'text'], 'stock' => ['On hand', 'qty'], 'reorder' => ['Reorder level', 'qty'], 'short' => ['Shortfall', 'qty'], 'supplier' => ['Supplier', 'text']])],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public function visibleTo(User $user): array
    {
        return array_filter($this->definitions(), fn ($d) => $user->can($d['permission']));
    }

    /**
     * @param  'day'|'week'|'month'  $period
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(string $key, DateRange $range, string $period = 'day'): Collection
    {
        $rows = match ($key) {
            'sales_summary' => $this->salesSummary($range, $period),
            'payments_by_method' => $this->paymentsByMethod($range),
            'sales_by_service' => $this->salesByItem($range, 'service'),
            'sales_by_product' => $this->salesByItem($range, 'product'),
            'refunds' => $this->refunds($range),
            'outstanding_invoices' => $this->outstanding(),
            'cashier_closing' => $this->cashierClosing($range),
            'appointment_status' => $this->appointmentStatus($range),
            'cancellations' => $this->cancellations($range),
            'new_vs_returning' => $this->newVsReturning($range),
            'customer_ltv' => $this->customerLtv(),
            'employee_productivity' => $this->productivity($range),
            'commissions' => $this->commissions($range),
            'expenses_by_category' => $this->expensesByCategory($range),
            'profitability' => $this->profitability($range),
            'inventory_valuation' => $this->valuation(),
            'stock_movements' => $this->stockMovements($range),
            'low_stock' => $this->lowStock(),
        };

        return collect($rows);
    }

    private function m(mixed $value): string
    {
        return number_format(round((float) $value, 2), 2, '.', '');
    }

    private function issued(DateRange $range)
    {
        return DB::table('invoices')->where('invoices.status', Invoice::STATUS_ISSUED)->whereBetween('invoices.issued_at', [$range->from, $range->to]);
    }

    /** @return array<int, array<string, mixed>> */
    private function salesSummary(DateRange $range, string $period): array
    {
        $expr = SqlDates::period('issued_at', in_array($period, ['day', 'week', 'month'], true) ? $period : 'day');

        return $this->issued($range)->selectRaw("{$expr} as period, COUNT(*) as invoices, SUM(subtotal) as subtotal, SUM(discount_total + coupon_discount_total) as discounts, SUM(tax_total) as tax, SUM(total) as total")
            ->groupBy('period')->orderBy('period')->limit(self::MAX_ROWS)->get()
            ->map(fn ($r) => ['period' => $r->period, 'invoices' => (int) $r->invoices, 'subtotal' => $this->m($r->subtotal), 'discounts' => $this->m($r->discounts), 'tax' => $this->m($r->tax), 'total' => $this->m($r->total)])->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function paymentsByMethod(DateRange $range): array
    {
        $received = DB::table('payments')->whereBetween('received_at', [$range->from, $range->to])->groupBy('payment_method_id')
            ->selectRaw('payment_method_id, COUNT(*) as n, SUM(amount) as total')->get()->keyBy('payment_method_id');
        $refunded = DB::table('refunds')->whereBetween('refunded_at', [$range->from, $range->to])->groupBy('payment_method_id')
            ->selectRaw('payment_method_id, SUM(amount) as total')->pluck('total', 'payment_method_id');

        return DB::table('payment_methods')->orderBy('sort_order')->get(['id', 'name'])->map(function ($method) use ($received, $refunded) {
            $in = (float) ($received[$method->id]->total ?? 0);
            $out = (float) ($refunded[$method->id] ?? 0);

            return ['method' => $method->name, 'payments' => (int) ($received[$method->id]->n ?? 0), 'collected' => $this->m($in), 'refunds' => $this->m($out), 'net' => $this->m($in - $out)];
        })->filter(fn ($r) => $r['payments'] > 0 || (float) $r['refunds'] > 0)->values()->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function salesByItem(DateRange $range, string $type): array
    {
        return $this->issued($range)->join('invoice_items', 'invoice_items.invoice_id', '=', 'invoices.id')->where('invoice_items.item_type', $type)
            ->selectRaw('invoice_items.description as item, SUM(invoice_items.quantity) as quantity, SUM(invoice_items.line_total - invoice_items.tax_amount) as revenue')
            ->groupBy('invoice_items.description')->orderByDesc('revenue')->limit(self::MAX_ROWS)->get()
            ->map(fn ($r) => ['item' => $r->item, 'quantity' => (int) $r->quantity, 'revenue' => $this->m($r->revenue)])->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function refunds(DateRange $range): array
    {
        return DB::table('refunds')->join('invoices', 'invoices.id', '=', 'refunds.invoice_id')->join('payment_methods', 'payment_methods.id', '=', 'refunds.payment_method_id')
            ->leftJoin('users', 'users.id', '=', 'refunds.refunded_by')->whereBetween('refunds.refunded_at', [$range->from, $range->to])->orderByDesc('refunds.refunded_at')->limit(self::MAX_ROWS)
            ->get(['refunds.refund_number', 'refunds.refunded_at', 'invoices.invoice_number', 'payment_methods.name as method', 'refunds.amount', 'refunds.reason', 'users.name as by'])
            ->map(fn ($r) => ['number' => $r->refund_number, 'date' => $r->refunded_at, 'invoice' => $r->invoice_number, 'method' => $r->method, 'amount' => $this->m($r->amount), 'reason' => $r->reason, 'by' => $r->by])->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function outstanding(): array
    {
        $today = today();

        return DB::table('invoices')->leftJoin('customers', 'customers.id', '=', 'invoices.customer_id')->where('invoices.status', Invoice::STATUS_ISSUED)->whereColumn('invoices.amount_paid', '<', 'invoices.total')
            ->orderBy('invoices.issued_at')->limit(self::MAX_ROWS)->get(['invoices.invoice_number', 'customers.name as customer', 'invoices.issued_at', 'invoices.total', 'invoices.amount_paid'])
            ->map(fn ($r) => ['number' => $r->invoice_number, 'customer' => $r->customer ?? 'Walk-in', 'issued' => $r->issued_at, 'total' => $this->m($r->total), 'paid' => $this->m($r->amount_paid),
                'balance' => Money::sub((string) $this->m($r->total), (string) $this->m($r->amount_paid)), 'age' => (int) $today->diffInDays(Carbon::parse($r->issued_at)->startOfDay(), true)])->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function cashierClosing(DateRange $range): array
    {
        return DB::table('cash_drawer_sessions')->leftJoin('users', 'users.id', '=', 'cash_drawer_sessions.opened_by')->where('cash_drawer_sessions.status', 'closed')
            ->whereBetween('cash_drawer_sessions.closed_at', [$range->from, $range->to])->orderBy('cash_drawer_sessions.closed_at')->limit(self::MAX_ROWS)
            ->get(['users.name as cashier', 'opened_at', 'closed_at', 'opening_cash', 'expected_cash', 'counted_cash', 'variance'])
            ->map(fn ($r) => ['cashier' => $r->cashier, 'opened' => $r->opened_at, 'closed' => $r->closed_at, 'opening' => $this->m($r->opening_cash), 'expected' => $this->m($r->expected_cash), 'counted' => $this->m($r->counted_cash), 'variance' => $this->m($r->variance)])->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function appointmentStatus(DateRange $range): array
    {
        $counts = DB::table('appointments')->whereBetween('start_at', [$range->from, $range->to])->groupBy('status')->selectRaw('status, COUNT(*) as n')->pluck('n', 'status');
        $total = max(1, (int) $counts->sum());

        return collect(AppointmentStatus::cases())->map(fn ($s) => ['status' => $s->label(), 'count' => (int) ($counts[$s->value] ?? 0), 'share' => round(100 * (int) ($counts[$s->value] ?? 0) / $total, 1)])
            ->filter(fn ($r) => $r['count'] > 0)->values()->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function cancellations(DateRange $range): array
    {
        return DB::table('appointments')->whereIn('status', ['cancelled', 'no_show'])->whereBetween('start_at', [$range->from, $range->to])->orderByDesc('start_at')->limit(self::MAX_ROWS)
            ->get(['appointment_number', 'start_at', 'status', 'is_late_cancellation', 'cancellation_reason'])
            ->map(fn ($r) => ['number' => $r->appointment_number, 'start' => $r->start_at, 'status' => AppointmentStatus::from($r->status)->label(), 'late' => $r->status === 'cancelled' ? ($r->is_late_cancellation ? 'Yes' : 'No') : '—', 'reason' => $r->cancellation_reason])->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function newVsReturning(DateRange $range): array
    {
        $month = SqlDates::period('registered_on', 'month');
        $new = DB::table('customers')->whereNull('deleted_at')->whereDate('registered_on', '>=', $range->from->toDateString())->whereDate('registered_on', '<=', $range->to->toDateString())->selectRaw("{$month} as m, COUNT(*) as n")->groupBy('m')->pluck('n', 'm');
        $invoices = DB::table('invoices')->where('status', Invoice::STATUS_ISSUED)->whereNotNull('customer_id')->whereBetween('issued_at', [$range->from, $range->to])->get(['customer_id', 'issued_at']);
        $first = DB::table('invoices')->where('status', Invoice::STATUS_ISSUED)->whereIn('customer_id', $invoices->pluck('customer_id')->unique())->groupBy('customer_id')->selectRaw('customer_id, MIN(issued_at) as first_at')->pluck('first_at', 'customer_id');

        $byMonth = [];
        foreach ($invoices as $inv) {
            $m = substr((string) $inv->issued_at, 0, 7);
            $byMonth[$m][$inv->customer_id] = true;
        }
        $months = collect(array_keys($byMonth))->merge($new->keys())->unique()->sort()->values();

        return $months->map(function ($m) use ($new, $byMonth, $first) {
            $active = array_keys($byMonth[$m] ?? []);
            $returning = count(array_filter($active, fn ($id) => substr((string) $first[$id], 0, 7) < $m));

            return ['month' => $m, 'new' => (int) ($new[$m] ?? 0), 'active' => count($active), 'returning' => $returning, 'share' => count($active) ? round(100 * $returning / count($active), 1) : 0.0];
        })->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function customerLtv(): array
    {
        return DB::table('invoices')->join('customers', 'customers.id', '=', 'invoices.customer_id')->where('invoices.status', Invoice::STATUS_ISSUED)->whereNull('customers.anonymized_at')
            ->groupBy('customers.id', 'customers.customer_number', 'customers.name')
            ->selectRaw('customers.customer_number, customers.name, COUNT(*) as visits, SUM(invoices.amount_paid - invoices.refunded_total) as value, MAX(invoices.issued_at) as last_at')
            ->orderByDesc('value')->limit(500)->get()
            ->map(fn ($r) => ['number' => $r->customer_number, 'name' => $r->name, 'visits' => (int) $r->visits, 'value' => $this->m($r->value), 'last' => $r->last_at])->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function productivity(DateRange $range): array
    {
        return $this->issued($range)->join('invoice_items', 'invoice_items.invoice_id', '=', 'invoices.id')->join('employees', 'employees.id', '=', 'invoice_items.employee_id')
            ->groupBy('employees.id', 'employees.name')->selectRaw('employees.name as employee, SUM(invoice_items.quantity) as items, SUM(invoice_items.line_total - invoice_items.tax_amount) as revenue')
            ->orderByDesc('revenue')->limit(self::MAX_ROWS)->get()
            ->map(fn ($r) => ['employee' => $r->employee, 'items' => (int) $r->items, 'revenue' => $this->m($r->revenue), 'average' => $this->m($r->items > 0 ? $r->revenue / $r->items : 0)])->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function commissions(DateRange $range): array
    {
        $accrued = DB::table('commissions')->whereBetween('accrued_at', [$range->from, $range->to])->groupBy('employee_id')->selectRaw('employee_id, SUM(amount) as total')->pluck('total', 'employee_id');
        $adjusted = DB::table('commission_adjustments')->whereBetween('created_at', [$range->from, $range->to])->groupBy('employee_id')->selectRaw('employee_id, SUM(amount) as total')->pluck('total', 'employee_id');

        return DB::table('employees')->whereIn('id', $accrued->keys()->merge($adjusted->keys())->unique())->orderBy('name')->get(['id', 'name'])
            ->map(fn ($e) => ['employee' => $e->name, 'accrued' => $this->m($accrued[$e->id] ?? 0), 'adjustments' => $this->m($adjusted[$e->id] ?? 0), 'net' => $this->m(($accrued[$e->id] ?? 0) + ($adjusted[$e->id] ?? 0))])->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function expensesByCategory(DateRange $range): array
    {
        return DB::table('expenses')->join('expense_categories', 'expense_categories.id', '=', 'expenses.expense_category_id')->where('expenses.status', 'approved')
            ->whereDate('expenses.expense_date', '>=', $range->from->toDateString())->whereDate('expenses.expense_date', '<=', $range->to->toDateString())
            ->groupBy('expense_categories.name')->selectRaw('expense_categories.name as category, COUNT(*) as n, SUM(expenses.amount) as total')->orderByDesc('total')->get()
            ->map(fn ($r) => ['category' => $r->category, 'count' => (int) $r->n, 'total' => $this->m($r->total)])->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function profitability(DateRange $range): array
    {
        $rows = [];
        $cursor = $range->from->startOfMonth();
        while ($cursor <= $range->to && count($rows) < 36) {
            $slice = new DateRange(max($cursor, $range->from), min($cursor->endOfMonth(), $range->to));
            $f = $this->finance->summary($slice);
            $rows[] = ['month' => $cursor->format('Y-m'), 'revenue' => $f['net_revenue'], 'cogs' => $f['cogs'], 'gross' => $f['gross_profit'], 'costs' => $f['operating_costs'], 'net' => $f['net_result']];
            $cursor = $cursor->addMonthNoOverflow()->startOfMonth();
        }

        return $rows;
    }

    /** @return array<int, array<string, mixed>> */
    private function valuation(): array
    {
        return DB::table('products')->whereNull('deleted_at')->where('current_stock', '!=', 0)->orderBy('name')->limit(self::MAX_ROWS)->get(['sku', 'name', 'current_stock', 'cost_price'])
            ->map(fn ($r) => ['sku' => $r->sku, 'product' => $r->name, 'stock' => (string) $r->current_stock, 'cost' => $this->m($r->cost_price), 'value' => $this->m((float) $r->current_stock * (float) $r->cost_price)])->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function stockMovements(DateRange $range): array
    {
        return DB::table('stock_movements')->whereBetween('created_at', [$range->from, $range->to])->groupBy('type')
            ->selectRaw('type, COUNT(*) as n, SUM(CASE WHEN quantity > 0 THEN quantity ELSE 0 END) as qin, SUM(CASE WHEN quantity < 0 THEN -quantity ELSE 0 END) as qout')->orderBy('type')->get()
            ->map(fn ($r) => ['type' => StockMovement::LABELS[$r->type] ?? $r->type, 'count' => (int) $r->n, 'in' => number_format((float) $r->qin, 3, '.', ''), 'out' => number_format((float) $r->qout, 3, '.', '')])->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function lowStock(): array
    {
        return DB::table('products')->leftJoin('suppliers', 'suppliers.id', '=', 'products.supplier_id')->whereNull('products.deleted_at')->where('products.is_active', true)
            ->where('products.reorder_level', '>', 0)->whereColumn('products.current_stock', '<=', 'products.reorder_level')->orderBy('products.name')->limit(self::MAX_ROWS)
            ->get(['products.sku', 'products.name', 'products.current_stock', 'products.reorder_level', 'suppliers.name as supplier'])
            ->map(fn ($r) => ['sku' => $r->sku, 'product' => $r->name, 'stock' => number_format((float) $r->current_stock, 3, '.', ''), 'reorder' => number_format((float) $r->reorder_level, 3, '.', ''),
                'short' => number_format(max(0, (float) $r->reorder_level - (float) $r->current_stock), 3, '.', ''), 'supplier' => $r->supplier ?? '—'])->all();
    }
}
