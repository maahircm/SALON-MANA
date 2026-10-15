<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Invoice;
use App\Models\User;
use App\Services\FinanceService;
use App\Support\DateRange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns;
use Tests\TestCase;

class FinanceTest extends TestCase
{
    use Concerns, RefreshDatabase;

    private User $cashier;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeSalonTime();
        $this->cashier = $this->cashier();
        $this->manager = $this->manager();
        $this->openDrawer($this->cashier);
        $this->openDrawer($this->manager, '0.00');
    }

    private function today(): DateRange
    {
        return DateRange::preset('today');
    }

    /** Sells 3 products (cost 6.00, price 15.00, 10% tax) paid in full and a 100.00 + 10% service paid 60.00, refunds 1 product unit, approves a 20.00 expense. */
    private function scenario(): Invoice
    {
        $product = $this->stockedProduct('10', '6.00', ['selling_price' => '15.00', 'tax_rate' => '10.00']);
        $retail = $this->issueInvoice($this->cashier, [['product_id' => $product->id, 'quantity' => 3]], [$this->cashPayment('49.50')]);
        $this->issueInvoice($this->cashier, [['service_id' => $this->service(60, '100.00', ['tax_rate' => '10.00'])->id, 'quantity' => 1]], [$this->cardPayment('60.00')], ['customer_id' => $this->customer()->id]);
        $this->actingAs($this->manager)->post("/invoices/{$retail->id}/refunds", ['idempotency_key' => (string) Str::uuid(), 'payment_method_id' => $this->paymentMethod('cash')->id,
            'reason' => 'Return', 'quantities' => [$retail->items->first()->id => 1]])->assertSessionDoesntHaveErrors();
        $expense = new Expense(['expense_category_id' => ExpenseCategory::where('name', 'Rent')->value('id'), 'description' => 'Rent', 'amount' => '20.00', 'expense_date' => today()]);
        $expense->forceFill(['expense_number' => 'EXP-T1', 'status' => Expense::APPROVED, 'payment_status' => 'paid', 'paid_on' => today()])->save();
        $declined = new Expense(['expense_category_id' => ExpenseCategory::where('name', 'Rent')->value('id'), 'description' => 'Nope', 'amount' => '999.00', 'expense_date' => today()]);
        $declined->forceFill(['expense_number' => 'EXP-T2', 'status' => Expense::REJECTED])->save();

        return $retail;
    }

    public function test_the_summary_separates_revenue_cash_receivables_cost_and_profit(): void
    {
        $this->scenario();

        $f = app(FinanceService::class)->summary($this->today());

        $this->assertSame(2, $f['invoice_count']);
        $this->assertSame('159.50', $f['gross_sales']);
        $this->assertSame('14.50', $f['tax_collected']);
        $this->assertSame('145.00', $f['net_sales']);
        $this->assertSame('15.00', $f['sales_returns'], 'a refund reverses the revenue value excluding tax');
        $this->assertSame('130.00', $f['net_revenue']);
        $this->assertSame('109.50', $f['payments_received']);
        $this->assertSame('16.50', $f['refunds_paid']);
        $this->assertSame('93.00', $f['net_cash_collected']);
        $this->assertSame('50.00', $f['receivables']);
        $this->assertSame(1, $f['receivable_count']);
        $this->assertSame('18.00', $f['cogs']);
        $this->assertSame('112.00', $f['gross_profit']);
        $this->assertSame('20.00', $f['expenses'], 'rejected expenses are never counted');
        $this->assertSame(['Rent' => '20.00'], $f['expenses_by_category']);
        $this->assertSame('92.00', $f['net_result']);
    }

    public function test_periods_outside_the_range_are_excluded_and_a_voided_invoice_leaves_revenue(): void
    {
        $invoice = $this->scenario();
        $service = $this->issueInvoice($this->cashier, [['service_id' => $this->service(30, '40.00')->id, 'quantity' => 1]], [], ['customer_id' => $this->customer()->id]);
        $this->actingAs($this->manager)->post("/invoices/{$service->id}/void", ['reason' => 'Mistake'])->assertSessionDoesntHaveErrors();

        $this->assertSame('130.00', app(FinanceService::class)->summary($this->today())['net_revenue']);
        $yesterday = new DateRange(today()->subDays(3)->toImmutable(), today()->subDay()->endOfDay()->toImmutable());
        $f = app(FinanceService::class)->summary($yesterday);
        $this->assertSame('0.00', $f['net_revenue']);
        $this->assertSame('0.00', $f['expenses']);
        $this->assertNotNull($invoice->id);
    }

    public function test_the_daily_series_covers_every_day_of_the_range(): void
    {
        $this->scenario();
        $series = app(FinanceService::class)->dailySeries(DateRange::preset('7d'));

        $this->assertCount(7, $series);
        $this->assertSame(['revenue' => '145.00', 'collected' => '109.50'], $series['2026-10-19']);
        $this->assertSame(['revenue' => '0.00', 'collected' => '0.00'], $series['2026-10-18']);
    }

    public function test_the_finance_pages_are_permission_gated_and_exports_are_audited(): void
    {
        $this->scenario();
        $viewer = User::factory()->withPermissions('finance.view')->create();
        $exporter = User::factory()->withPermissions('finance.view', 'reports.export')->create();

        $this->actingAs($this->cashier)->get('/finance')->assertForbidden();
        $this->actingAs($viewer)->get('/finance')->assertOk()->assertSee('130.00')->assertSee('Estimated net result');
        $this->actingAs($viewer)->get('/finance/export')->assertForbidden();
        $csv = $this->actingAs($exporter)->get('/finance/export?range=today')->assertOk()->streamedContent();

        $this->assertStringContainsString('"Net revenue",130.00', $csv);
        $this->assertDatabaseHas('audit_logs', ['action' => 'report.exported']);
        $this->actingAs($viewer)->get('/finance?from=2026-10-20&to=2026-10-01')->assertSessionHasErrors('to');
    }
}
