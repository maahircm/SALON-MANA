<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use App\Services\AppointmentBookingService;
use App\Services\AppointmentStatusService;
use App\Services\ReportCatalog;
use App\Support\DateRange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\Concerns;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use Concerns, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeSalonTime();
        $this->admin = User::factory()->superAdmin()->create();
        $cashier = $this->cashier();
        $this->openDrawer($cashier);
        $stylist = $this->stylist();
        $customer = $this->customer(['name' => 'Alice Buyer', 'registered_on' => today()]);
        $product = $this->stockedProduct('3', '6.00', ['selling_price' => '15.00', 'tax_rate' => '0.00', 'reorder_level' => '5', 'name' => 'Shampoo']);
        $service = $this->service(60, '100.00', ['name' => 'Cut & style']);
        $this->issueInvoice($cashier, [['service_id' => $service->id, 'quantity' => 1, 'employee_id' => $stylist->id], ['product_id' => $product->id, 'quantity' => 1]], [$this->cashPayment('60.00')], ['customer_id' => $customer->id]);
        $expense = new Expense(['expense_category_id' => ExpenseCategory::where('name', 'Utilities')->value('id'), 'description' => 'Power', 'amount' => '25.50', 'expense_date' => today()]);
        $expense->forceFill(['expense_number' => 'EXP-R1', 'status' => Expense::APPROVED])->save();

        $booking = app(AppointmentBookingService::class);
        $appointment = $booking->create(['customer_id' => $customer->id, 'start_at' => '2026-10-20 10:00', 'lines' => [['service_id' => $service->id, 'employee_id' => $stylist->id]]], $this->admin);
        app(AppointmentStatusService::class)->transition($appointment, AppointmentStatus::Cancelled, $this->admin, '=HYPERLINK("http://evil","click")', true);
    }

    private function rows(string $key, string $range = 'today', string $period = 'day'): array
    {
        return app(ReportCatalog::class)->rows($key, DateRange::preset($range), $period)->all();
    }

    private function wide(string $key): array
    {
        return app(ReportCatalog::class)->rows($key, new DateRange(today()->toImmutable(), today()->addMonth()->endOfDay()->toImmutable()))->all();
    }

    public function test_sales_reports_add_up(): void
    {
        $sales = $this->rows('sales_summary');
        $this->assertCount(1, $sales);
        $this->assertSame(['period' => '2026-10-19', 'invoices' => 1, 'subtotal' => '115.00', 'discounts' => '0.00', 'tax' => '0.00', 'total' => '115.00'], $sales[0]);
        $this->assertSame('2026-10-19', $this->rows('sales_summary', 'today', 'week')[0]['period'], 'weeks are labelled by their Monday');
        $this->assertSame('2026-10', $this->rows('sales_summary', 'today', 'month')[0]['period']);

        $this->assertSame([['method' => 'Cash', 'payments' => 1, 'collected' => '60.00', 'refunds' => '0.00', 'net' => '60.00']], $this->rows('payments_by_method'));
        $this->assertSame([['item' => 'Cut & style', 'quantity' => 1, 'revenue' => '100.00']], $this->rows('sales_by_service'));
        $this->assertSame([['item' => 'Shampoo', 'quantity' => 1, 'revenue' => '15.00']], $this->rows('sales_by_product'));
        $outstanding = $this->rows('outstanding_invoices');
        $this->assertSame('55.00', $outstanding[0]['balance']);
        $this->assertSame('Alice Buyer', $outstanding[0]['customer']);
    }

    public function test_finance_staff_stock_appointment_and_customer_reports(): void
    {
        $this->assertSame([['category' => 'Utilities', 'count' => 1, 'total' => '25.50']], $this->rows('expenses_by_category'));
        $profit = $this->rows('profitability');
        $this->assertSame(['month' => '2026-10', 'revenue' => '115.00', 'cogs' => '6.00', 'gross' => '109.00', 'costs' => '25.50', 'net' => '83.50'], $profit[0]);

        $this->assertSame([['employee' => $this->rows('employee_productivity')[0]['employee'], 'items' => 1, 'revenue' => '100.00', 'average' => '100.00']], $this->rows('employee_productivity'));
        $this->assertSame([['status' => 'Cancelled', 'count' => 1, 'share' => 100.0]], $this->wide('appointment_status'));
        $cancel = $this->wide('cancellations');
        $this->assertCount(1, $cancel);
        $this->assertSame('Cancelled', $cancel[0]['status']);

        $this->assertSame([['month' => '2026-10', 'new' => 1, 'active' => 1, 'returning' => 0, 'share' => 0.0]], $this->rows('new_vs_returning'));
        $ltv = $this->rows('customer_ltv');
        $this->assertSame(['Alice Buyer', 1, '60.00'], [$ltv[0]['name'], $ltv[0]['visits'], $ltv[0]['value']]);

        $this->assertSame([['sku' => $this->rows('low_stock')[0]['sku'], 'product' => 'Shampoo', 'stock' => '2.000', 'reorder' => '5.000', 'short' => '3.000', 'supplier' => '—']], $this->rows('low_stock'));
        $this->assertSame('12.00', $this->rows('inventory_valuation')[0]['value']);
        $movements = collect($this->rows('stock_movements'))->keyBy('type');
        $this->assertSame('3.000', $movements['Adjustment (+)']['in']);
        $this->assertSame('1.000', $movements['Retail sale']['out']);
    }

    public function test_every_report_renders_prints_and_exports_for_an_administrator(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->actingAs($this->admin)->get('/reports')->assertOk()->assertSee('Sales summary');
        foreach (array_keys(app(ReportCatalog::class)->definitions()) as $key) {
            $this->assertNoNestedForms($this->actingAs($this->admin)->get("/reports/{$key}?from=2026-01-01&to=2026-12-31")->assertOk()->getContent(), $key);
            $this->actingAs($this->admin)->get("/reports/{$key}?from=2026-01-01&to=2026-12-31&print=1")->assertOk();
            $this->actingAs($this->admin)->get("/reports/{$key}/export?from=2026-01-01&to=2026-12-31")->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        }
        $this->actingAs($this->admin)->get('/reports/nope')->assertNotFound();
        $this->assertDatabaseHas('audit_logs', ['action' => 'report.exported']);
    }

    public function test_csv_cells_that_look_like_formulas_are_neutralised(): void
    {
        $csv = $this->actingAs($this->admin)->get('/reports/cancellations/export?from=2026-01-01&to=2026-12-31')->assertOk()->streamedContent();

        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString(',"=HYPERLINK', $csv);
    }

    public function test_each_report_needs_its_own_permission_and_export_needs_export_permission(): void
    {
        $operations = User::factory()->withPermissions('reports.view')->create();
        $accountant = User::factory()->withPermissions('reports.view', 'reports.financial', 'reports.export')->create();
        $cashierOnly = $this->cashier();

        $this->actingAs($operations)->get('/reports/appointment_status')->assertOk();
        $this->actingAs($operations)->get('/reports/sales_summary')->assertForbidden();
        $this->actingAs($operations)->get('/reports/profitability')->assertForbidden();
        $this->actingAs($operations)->get('/reports/inventory_valuation')->assertForbidden();
        $this->actingAs($operations)->get('/reports/appointment_status/export')->assertForbidden();
        $this->actingAs($operations)->get('/reports')->assertOk()->assertDontSee('Sales summary')->assertSee('Appointments by status');
        $this->actingAs($accountant)->get('/reports/sales_summary/export?range=today')->assertOk();
        $this->actingAs($accountant)->get('/reports/cashier_closing')->assertForbidden();
        $this->actingAs($cashierOnly)->get('/reports')->assertForbidden();
        $this->actingAs($cashierOnly)->get('/reports/sales_summary')->assertForbidden();
    }

    public function test_the_screen_paginates_and_totals_cover_every_row(): void
    {
        $service = $this->service(30, '10.00');
        $cashier = $this->cashier();
        $this->openDrawer($cashier);
        foreach (range(1, 30) as $i) {
            $this->issueInvoice($cashier, [['service_id' => $this->service(30, '10.00', ['name' => sprintf('Svc %02d', $i)])->id, 'quantity' => 1]], [$this->cashPayment('10.00')]);
        }
        $this->assertNotNull($service->id);

        $page1 = $this->actingAs($this->admin)->get('/reports/sales_by_service?range=today')->assertOk();
        $page1->assertSee('Showing 1–25 of 31')->assertSee('Total');
        $this->actingAs($this->admin)->get('/reports/sales_by_service?range=today&page=2')->assertOk()->assertSee('Showing 26–31 of 31');
        $this->assertSame(31, $this->actingAs($this->admin)->get('/reports/sales_by_service?range=today&print=1')->viewData('rows')->count());
        $this->assertSame('400.00', $this->actingAs($this->admin)->get('/reports/sales_by_service?range=today')->viewData('totals')['revenue']);
    }

    public function test_invalid_dates_are_rejected(): void
    {
        $this->actingAs($this->admin)->get('/reports/sales_summary?from=2026-10-10&to=2026-10-01')->assertSessionHasErrors('to');
        $this->actingAs($this->admin)->get('/reports/sales_summary?from=notadate')->assertSessionHasErrors('from');
    }
}
