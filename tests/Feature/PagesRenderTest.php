<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\MembershipPlan;
use App\Models\NotificationLog;
use App\Models\ProductCategory;
use App\Models\Promotion;
use App\Models\Room;
use App\Models\ServiceConsumable;
use App\Models\ServicePackage;
use App\Models\Skill;
use App\Models\Supplier;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\AppointmentBookingService;
use App\Services\PurchasingService;
use App\Services\StockAdjustmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns;
use Tests\TestCase;

/** Every screen must render (HTTP 200) with realistic data; catches template errors the logic tests miss. */
class PagesRenderTest extends TestCase
{
    use Concerns, RefreshDatabase;

    public function test_every_page_renders_for_an_administrator(): void
    {
        $this->freezeSalonTime();
        $admin = User::factory()->superAdmin()->create();
        $skill = Skill::factory()->create();
        $stylist = $this->stylist();
        $stylist->skills()->attach($skill);
        $stylist->shifts()->create(['shift_date' => '2026-10-27', 'type' => 'work', 'start_time' => '10:00', 'end_time' => '14:00']);
        $service = $this->service(60, '40.00', ['required_skill_id' => $skill->id]);
        $service->addons()->create(['name' => 'Gloss', 'duration_minutes' => 5, 'price' => '3.00', 'is_active' => true]);
        $service->priceHistories()->create(['new_base_price' => '40.00']);
        $package = ServicePackage::create(['name' => 'Pkg', 'price' => '90', 'is_active' => true]);
        $package->items()->create(['service_id' => $service->id, 'quantity' => 2]);
        $room = Room::factory()->create();
        $customer = $this->customer(['date_of_birth' => '1991-02-03', 'marketing_consent' => true, 'preferred_employee_id' => $stylist->id]);
        $customer->preferences()->create(['type' => 'allergy', 'value' => 'Latex', 'is_sensitive' => true]);
        $appt = app(AppointmentBookingService::class)->create(['customer_id' => $customer->id, 'start_at' => '2026-10-20 10:00', 'notes' => 'Bring photo',
            'lines' => [['service_id' => $service->id, 'employee_id' => $stylist->id, 'room_id' => $room->id]]], $admin);

        $urls = [
            '/dashboard', '/profile', '/profile/password', '/users', '/roles', '/audit-log',
            '/customers', '/customers/create', "/customers/{$customer->id}", "/customers/{$customer->id}/edit", '/customers?q=a&birthday_month=2&consent=1',
            '/services', '/services/create', "/services/{$service->id}", "/services/{$service->id}/edit", '/services/categories', '/services/packages',
            '/services/packages/create', "/services/packages/{$package->id}/edit",
            '/employees', '/employees/create', "/employees/{$stylist->id}", "/employees/{$stylist->id}/edit", "/employees/{$stylist->id}/schedule", '/employees/skills', '/schedules', '/schedules?week=2026-10-26',
            '/appointments/calendar', '/appointments/list', '/appointments/list?status=pending&from=2026-10-01', '/appointments/create', '/appointments/create?walk_in=1',
            "/appointments/create?customer_id={$customer->id}&date=2026-10-21&time=11:00", "/appointments/{$appt->id}", "/appointments/{$appt->id}/edit", '/rooms', '/settings/booking',
        ];
        foreach ($urls as $url) {
            $this->assertNoNestedForms($this->actingAs($admin)->get($url)->assertOk()->getContent(), $url);
        }
        // sidebar exposes the new modules and every link target exists
        $html = $this->actingAs($admin)->get('/dashboard')->assertSee('Appointment Calendar')->assertSee('Customers')->assertSee('Staff Schedule')->getContent();
        preg_match_all('/class="nav-item[^"]*" href="([^"]+)"/', $html, $m);
        $this->assertGreaterThan(8, count($m[1]));
        foreach ($m[1] as $href) {
            $this->actingAs($admin)->get($href)->assertOk();
        }
    }

    public function test_sidebar_only_shows_modules_the_user_may_use(): void
    {
        $html = $this->actingAs($this->receptionist())->get('/dashboard')->assertOk()->getContent();
        $this->assertStringContainsString('Customers', $html);
        $this->assertStringNotContainsString('Roles &amp; Permissions', $html);
        $this->assertStringNotContainsString('Booking Settings', $html);
        $this->assertStringNotContainsString('Audit Log', $html);
    }

    public function test_every_inventory_screen_renders_with_realistic_data(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $supplier = Supplier::factory()->create();
        $category = ProductCategory::factory()->create();
        $product = $this->stockedProduct('10', '5.00', ['product_category_id' => $category->id, 'supplier_id' => $supplier->id, 'reorder_level' => '20', 'barcode' => '5901234123457', 'expiry_date' => now()->addDays(5)->toDateString()]);
        $dye = $this->stockedProduct('50', '0.10', ['unit' => 'ml', 'is_sellable' => false]);
        $service = $this->service(60, '30.00');
        ServiceConsumable::create(['service_id' => $service->id, 'product_id' => $dye->id, 'quantity' => '20']);
        $purchasing = app(PurchasingService::class);
        $draft = $purchasing->saveDraft(null, ['supplier_id' => $supplier->id], [$product->id => ['quantity' => '5', 'unit_cost' => '4.00']], $admin);
        $placed = $purchasing->place($purchasing->saveDraft(null, ['supplier_id' => $supplier->id], [$product->id => ['quantity' => '8', 'unit_cost' => '4.50']], $admin), $admin);
        $purchasing->receive($placed, [$placed->items->first()->id => ['quantity' => '3', 'expiry_date' => '2027-01-01']], 'DN-1', null, $admin);
        app(StockAdjustmentService::class)->adjust($product, 'damaged', '1', 'Dropped', $admin);
        app(StockAdjustmentService::class)->returnToSupplier($supplier, $product, '1', 'Faulty', $admin);

        $urls = ['/inventory/products', '/inventory/products?status=low', '/inventory/products?status=expiring&q=SKU', '/inventory/products/create', "/inventory/products/{$product->id}", "/inventory/products/{$product->id}/edit",
            '/inventory/categories', '/inventory/suppliers', '/inventory/suppliers/create', "/inventory/suppliers/{$supplier->id}", "/inventory/suppliers/{$supplier->id}/edit",
            '/inventory/purchase-orders', '/inventory/purchase-orders?status=ordered', '/inventory/purchase-orders/create', "/inventory/purchase-orders/create?supplier={$supplier->id}",
            "/inventory/purchase-orders/{$draft->id}", "/inventory/purchase-orders/{$draft->id}/edit", "/inventory/purchase-orders/{$placed->id}",
            '/inventory/movements', "/inventory/movements?product={$product->id}&type=damage&from=2026-01-01", '/inventory/valuation', "/inventory/adjustments?product={$product->id}", '/inventory/returns',
            '/settings/inventory', "/services/{$service->id}", '/pos'];
        foreach ($urls as $url) {
            $this->assertNoNestedForms($this->actingAs($admin)->get($url)->assertOk()->getContent(), $url);
        }
        $html = $this->actingAs($admin)->get('/dashboard')->getContent();
        preg_match_all('/class="nav-item[^"]*" href="([^"]+)"/', $html, $links);
        foreach ($links[1] as $href) {
            $this->actingAs($admin)->get($href)->assertOk();
        }
        $this->assertStringContainsString('Stock Ledger', $html);
    }

    public function test_the_sidebar_shows_inventory_screens_by_permission(): void
    {
        $plain = $this->actingAs(User::factory()->withPermissions('inventory.view')->create())->get('/dashboard');
        $plain->assertDontSee('Valuation')->assertDontSee('Purchase Orders')->assertDontSee('Stock Adjustments');
        $this->actingAs($this->stockKeeper('dashboard.view'))->get('/dashboard')->assertSee('Valuation')->assertSee('Purchase Orders')->assertSee('Stock Adjustments');
    }

    public function test_every_hr_and_loyalty_screen_renders_with_realistic_data(): void
    {
        $this->freezeSalonTime();
        $admin = User::factory()->superAdmin()->create();
        $cashier = $this->cashier();
        $this->openDrawer($cashier);
        $stylist = $this->stylist();
        $customer = $this->customer();
        $plan = MembershipPlan::create(['name' => 'Gold', 'price' => '50.00', 'duration_days' => 30, 'discount_percent' => '10.00', 'points_multiplier' => '2.00', 'is_active' => true]);
        $promotion = Promotion::create(['name' => 'Autumn', 'discount_type' => 'percent', 'discount_value' => '10.00', 'min_purchase' => '0.00', 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(), 'is_active' => true]);
        (new Coupon(['code' => 'AUTUMN', 'is_active' => true]))->forceFill(['promotion_id' => $promotion->id, 'used_count' => 0])->save();
        $this->issueInvoice($cashier, [['service_id' => $this->service(60, '40.00')->id, 'quantity' => 1]], [$this->cashPayment('40.00')], ['customer_id' => $customer->id, 'employee_id' => $stylist->id]);
        $stylist->leaveRequests()->create(['leave_type' => 'annual', 'starts_on' => '2026-11-02', 'ends_on' => '2026-11-03', 'days' => 2, 'status' => 'pending', 'reason' => 'Trip']);

        $urls = ['/attendance', '/attendance/summary', '/leave', '/commissions', '/commissions?employee='.$stylist->id, '/loyalty', '/loyalty/settings', "/loyalty/customers/{$customer->id}",
            '/memberships', '/promotions', '/promotions/create', "/promotions/{$promotion->id}", "/promotions/{$promotion->id}/edit", '/settings/hr', '/pos',
            '/customers?segment=vip', '/customers?segment=members', "/customers/{$customer->id}"];
        foreach ($urls as $url) {
            $this->assertNoNestedForms($this->actingAs($admin)->get($url)->assertOk()->getContent(), $url);
        }
        $html = $this->actingAs($admin)->get('/dashboard')->getContent();
        preg_match_all('/class="nav-item[^"]*" href="([^"]+)"/', $html, $links);
        foreach ($links[1] as $href) {
            $this->actingAs($admin)->get($href)->assertOk();
        }
        $this->assertStringContainsString('Commissions', $html);
        $this->assertNotNull($plan->id);
    }

    public function test_every_finance_reporting_and_settings_screen_renders_with_realistic_data(): void
    {
        $this->freezeSalonTime();
        $admin = User::factory()->superAdmin()->create();
        $cashier = $this->cashier();
        $this->openDrawer($cashier);
        $customer = $this->customer(['email' => 'c@example.test']);
        $invoice = $this->issueInvoice($cashier, [['service_id' => $this->service(60, '40.00')->id, 'quantity' => 1]], [$this->cashPayment('15.00')], ['customer_id' => $customer->id]);
        $expense = (new Expense(['expense_category_id' => ExpenseCategory::first()->id, 'description' => 'Rent', 'amount' => '20.00', 'expense_date' => today()]))->forceFill(['expense_number' => 'EXP-P1', 'status' => Expense::PENDING, 'created_by' => $admin->id]);
        $expense->save();
        $notification = UserNotification::create(['user_id' => $admin->id, 'type' => 'low_stock', 'title' => 'Low stock', 'body' => 'Body']);
        NotificationLog::create(['channel' => 'email', 'type' => 'booking_confirmation', 'recipient_type' => 'customer', 'recipient_id' => $customer->id, 'destination' => 'c@example.test', 'subject' => 'S', 'body' => 'B', 'status' => 'failed', 'last_error' => 'boom', 'dedupe_key' => 'k1']);

        $urls = ['/dashboard', '/dashboard?range=year', '/expenses', '/expenses?status=pending&payment=unpaid', '/expenses/create', "/expenses/{$expense->id}", "/expenses/{$expense->id}/edit", '/expenses/categories',
            '/finance', '/finance?range=month', '/reports', '/notifications', '/notification-logs', '/notification-logs?status=failed&type=booking_confirmation', '/settings/general', '/settings/notifications', "/invoices/{$invoice->id}/receipt"];
        foreach ($urls as $url) {
            $this->assertNoNestedForms($this->actingAs($admin)->get($url)->assertOk()->getContent(), $url);
        }
        $html = $this->actingAs($admin)->get('/dashboard')->getContent();
        preg_match_all('/class="nav-item[^"]*" href="([^"]+)"/', $html, $links);
        foreach ($links[1] as $href) {
            $this->actingAs($admin)->get($href)->assertOk();
        }
        foreach (['Expenses', 'Financial Summary', 'Reports', 'General Settings', 'Message Delivery Log'] as $label) {
            $this->assertStringContainsString($label, $html);
        }
        $this->assertNotNull($notification->id);
    }
}
