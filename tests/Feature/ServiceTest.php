<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\AppointmentBookingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns;
use Tests\TestCase;

class ServiceTest extends TestCase
{
    use Concerns, RefreshDatabase;

    private function payload(ServiceCategory $cat, array $over = []): array
    {
        return array_merge(['service_category_id' => $cat->id, 'name' => 'Haircut', 'duration_minutes' => 45, 'tax_rate' => 5, 'base_price' => '30.00', 'is_active' => 1], $over);
    }

    public function test_manager_creates_service_with_price_history_and_audit(): void
    {
        $m = User::factory()->withPermissions('services.view', 'services.manage', 'services.price')->create();
        $cat = ServiceCategory::factory()->create();
        $this->actingAs($m)->post('/services', $this->payload($cat, ['promo_price' => '25.00']))->assertRedirect();
        $s = Service::firstOrFail();
        $this->assertSame('30.00', $s->base_price);
        $this->assertSame('25.00', $s->promo_price);
        $this->assertDatabaseHas('service_price_histories', ['service_id' => $s->id, 'new_base_price' => '30.00', 'changed_by' => $m->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'service.created']);
    }

    public function test_promo_cannot_exceed_base_price_and_validation(): void
    {
        $m = User::factory()->superAdmin()->create();
        $cat = ServiceCategory::factory()->create();
        $this->actingAs($m)->post('/services', $this->payload($cat, ['promo_price' => '40.00']))->assertSessionHasErrors('promo_price');
        $this->actingAs($m)->post('/services', $this->payload($cat, ['base_price' => '-5']))->assertSessionHasErrors('base_price');
        $this->actingAs($m)->post('/services', $this->payload($cat, ['base_price' => '10.999']))->assertSessionHasErrors('base_price');
        $this->actingAs($m)->post('/services', $this->payload($cat, ['duration_minutes' => 1]))->assertSessionHasErrors('duration_minutes');
    }

    public function test_user_without_price_permission_cannot_change_price(): void
    {
        $s = $this->service(60, '40.00');
        $u = User::factory()->withPermissions('services.view', 'services.manage')->create();
        $this->actingAs($u)->put("/services/{$s->id}", ['service_category_id' => $s->service_category_id, 'name' => 'Renamed', 'duration_minutes' => 60, 'tax_rate' => 0,
            'base_price' => '1.00', 'is_active' => 1])->assertRedirect();
        $s->refresh();
        $this->assertSame('Renamed', $s->name);
        $this->assertSame('40.00', $s->base_price);
        $this->assertDatabaseCount('service_price_histories', 0);
    }

    public function test_price_change_is_recorded_and_audited(): void
    {
        $s = $this->service(60, '40.00');
        $u = User::factory()->withPermissions('services.view', 'services.manage', 'services.price')->create();
        $this->actingAs($u)->put("/services/{$s->id}", $this->payload($s->category, ['name' => $s->name, 'base_price' => '50.00']))->assertRedirect();
        $this->assertDatabaseHas('service_price_histories', ['service_id' => $s->id, 'old_base_price' => '40.00', 'new_base_price' => '50.00']);
        $log = AuditLog::where('action', 'service.price_changed')->firstOrFail();
        $this->assertSame('40.00', $log->before['base_price']);
        $this->assertSame('50.00', $log->after['base_price']);
    }

    public function test_viewer_cannot_manage_services(): void
    {
        $s = $this->service();
        $u = User::factory()->withPermissions('services.view')->create();
        $this->actingAs($u)->get('/services')->assertOk();
        $this->actingAs($u)->get('/services/create')->assertForbidden();
        $this->actingAs($u)->delete("/services/{$s->id}")->assertForbidden();
        $this->actingAs($u)->post('/services/categories', ['name' => 'X'])->assertForbidden();
    }

    public function test_promotional_price_applies_only_within_dates(): void
    {
        $s = $this->service(60, '40.00');
        $s->forceFill(['promo_price' => '30.00', 'promo_starts_on' => '2026-10-20', 'promo_ends_on' => '2026-10-25'])->save();
        $this->assertSame('40.00', $s->currentPrice(Carbon::parse('2026-10-19')));
        $this->assertSame('30.00', $s->currentPrice(Carbon::parse('2026-10-22')));
        $this->assertSame('40.00', $s->currentPrice(Carbon::parse('2026-10-26')));
    }

    public function test_category_with_services_cannot_be_deleted_and_names_are_unique(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $s = $this->service();
        $this->actingAs($admin)->delete("/services/categories/{$s->service_category_id}")->assertSessionHasErrors('category');
        $this->actingAs($admin)->post('/services/categories', ['name' => $s->category->name])->assertSessionHasErrors('name');
    }

    public function test_addons_and_packages(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $s1 = $this->service();
        $s2 = $this->service();
        $this->actingAs($admin)->post("/services/{$s1->id}/addons", ['name' => 'Deep conditioning', 'duration_minutes' => 10, 'price' => '5.50'])->assertRedirect();
        $this->assertDatabaseHas('service_addons', ['service_id' => $s1->id, 'price' => '5.50']);
        $this->actingAs($admin)->post('/services/packages', ['name' => 'Bridal', 'price' => '120', 'items' => [$s1->id => 1, $s2->id => 2]])->assertRedirect('/services/packages');
        $this->assertDatabaseCount('service_package_items', 2);
        $this->actingAs($admin)->post('/services/packages', ['name' => 'Empty', 'price' => '10', 'items' => [$s1->id => 0]])->assertSessionHasErrors('items');
    }

    public function test_soft_deleted_service_keeps_booked_history(): void
    {
        $this->freezeSalonTime();
        $s = $this->service(60, '40.00');
        $e = $this->stylist();
        $c = $this->customer();
        $admin = User::factory()->superAdmin()->create();
        $appt = app(AppointmentBookingService::class)->create(['customer_id' => $c->id, 'start_at' => '2026-10-20 10:00', 'lines' => [['service_id' => $s->id, 'employee_id' => $e->id]]], $admin);
        $this->actingAs($admin)->delete("/services/{$s->id}")->assertRedirect();
        $this->assertSoftDeleted($s);
        $this->assertSame('40.00', $appt->fresh()->lines->first()->unit_price);
        $this->actingAs($admin)->get("/appointments/{$appt->id}")->assertOk()->assertSee($s->name);
    }
}
