<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use Concerns, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeSalonTime();
        $cashier = $this->cashier();
        $this->openDrawer($cashier);
        $this->issueInvoice($cashier, [['service_id' => $this->service(60, '80.00')->id, 'quantity' => 1]], [$this->cashPayment('50.00')], ['customer_id' => $this->customer()->id]);
        $expense = new Expense(['expense_category_id' => ExpenseCategory::first()->id, 'description' => 'Soap', 'amount' => '10.00', 'expense_date' => today()]);
        $expense->forceFill(['expense_number' => 'EXP-D1', 'status' => Expense::APPROVED])->save();
        $pending = new Expense(['expense_category_id' => ExpenseCategory::first()->id, 'description' => 'Pending', 'amount' => '5.00', 'expense_date' => today()]);
        $pending->forceFill(['expense_number' => 'EXP-D2', 'status' => Expense::PENDING])->save();
    }

    public function test_financial_widgets_distinguish_revenue_cash_and_profit_and_use_the_selected_range(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($admin)->get('/dashboard?range=today')->assertOk()
            ->assertSee('Revenue')->assertSee('Cash collected')->assertSee('Estimated profit')->assertSee('chart.umd.min.js', false);

        $finance = $response->viewData('finance');
        $this->assertSame('80.00', $finance['net_revenue']);
        $this->assertSame('50.00', $finance['net_cash_collected']);
        $this->assertSame('10.00', $finance['operating_costs']);
        $this->assertSame('70.00', $finance['net_result']);
        $this->assertSame('2026-10-19', $response->viewData('charts')['daily']['labels'][0]);
        $this->assertSame(1, $response->viewData('operations')['pending_expenses']);

        $this->actingAs($admin)->get('/dashboard?from=2026-09-01&to=2026-09-30')->assertOk()->assertViewHas('finance', fn ($f) => $f['net_revenue'] === '0.00');
        $this->actingAs($admin)->get('/dashboard?from=2026-09-30&to=2026-09-01')->assertSessionHasErrors('to');
    }

    public function test_users_only_see_the_widgets_their_permissions_allow(): void
    {
        $front = User::factory()->withPermissions('dashboard.view', 'appointments.view', 'customers.view')->create();
        $owner = User::factory()->withPermissions('dashboard.view', 'dashboard.financials')->create();
        $bare = User::factory()->withPermissions('dashboard.view')->create();

        $this->actingAs($front)->get('/dashboard')->assertOk()->assertSee('Appointments today')->assertDontSee('Business performance')->assertDontSee('Estimated profit')->assertDontSee('Low-stock')->assertViewHas('finance', null);
        $this->actingAs($owner)->get('/dashboard')->assertOk()->assertSee('Business performance')->assertDontSee('Appointments today');
        $this->actingAs($bare)->get('/dashboard')->assertOk()->assertSee('Signed in as')->assertDontSee('Business performance');
    }

    public function test_the_sidebar_links_finance_and_reports_by_permission(): void
    {
        $viewer = User::factory()->withPermissions('dashboard.view', 'reports.view')->create();

        $this->actingAs($viewer)->get('/dashboard')->assertSee('Reports')->assertDontSee('Financial Summary')->assertDontSee('>Expenses<', false);
        $this->actingAs(User::factory()->withPermissions('dashboard.view', 'expenses.view', 'finance.view')->create())->get('/dashboard')->assertSee('Financial Summary')->assertSee('Expenses');
    }
}
