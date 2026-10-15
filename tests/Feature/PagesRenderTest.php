<?php

namespace Tests\Feature;

use App\Models\Room;
use App\Models\ServicePackage;
use App\Models\Skill;
use App\Models\User;
use App\Services\AppointmentBookingService;
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
}
