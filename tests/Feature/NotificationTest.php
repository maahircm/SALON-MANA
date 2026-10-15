<?php

namespace Tests\Feature;

use App\Contracts\SmsGateway;
use App\Enums\AppointmentStatus;
use App\Mail\SalonMessage;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\NotificationLog;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\AppointmentBookingService;
use App\Services\AppointmentStatusService;
use App\Services\AutomationService;
use App\Services\NotificationService;
use App\Services\SettingsService;
use App\Services\Sms\LogSmsGateway;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\Concerns;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use Concerns, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeSalonTime();
        Mail::fake();
        $this->admin = User::factory()->superAdmin()->create();
    }

    private function contact(array $attrs = []): Customer
    {
        return $this->customer($attrs + ['name' => 'Amina', 'email' => 'amina@example.test', 'phone' => '+252611111111', 'status' => 'active']);
    }

    private function book(Customer $customer, string $date = '2026-10-20', string $time = '10:00', array $extra = []): Appointment
    {
        $stylist = Employee::query()->first() ?? $this->stylist();
        $service = $this->service(60, '40.00', ['name' => 'Silk press']);

        return app(AppointmentBookingService::class)->create($extra + ['customer_id' => $customer->id, 'start_at' => "{$date} {$time}", 'lines' => [['service_id' => $service->id, 'employee_id' => $stylist->id]]], $this->admin);
    }

    public function test_a_booking_confirmation_is_emailed_and_logged_with_its_status(): void
    {
        $this->book($this->contact());

        $log = NotificationLog::firstOrFail();
        $this->assertSame(['email', 'booking_confirmation', 'sent', 'amina@example.test'], [$log->channel, $log->type, $log->status, $log->destination]);
        $this->assertSame(1, $log->attempts);
        $this->assertStringContainsString('Silk press', $log->body);
        $this->assertStringContainsString('2026-10-20 10:00', $log->body);
        Mail::assertSent(SalonMessage::class, fn (SalonMessage $m) => $m->hasTo('amina@example.test'));
    }

    public function test_sms_is_off_by_default_and_the_log_driver_never_claims_delivery(): void
    {
        $this->book($this->contact());
        $this->assertSame(0, NotificationLog::where('channel', 'sms')->count());

        $this->setting('notifications.channels.sms', true);
        $this->book($this->contact(['email' => null, 'phone' => '+252622222222']), '2026-10-21');

        $sms = NotificationLog::where('channel', 'sms')->firstOrFail();
        $this->assertSame('logged', $sms->status, 'the log driver does not deliver, so it must not be reported as sent');
        $this->assertSame('log', $sms->provider);
        $this->assertNotNull($sms->sent_at);
    }

    public function test_nothing_is_sent_without_an_address_when_the_event_is_off_or_for_walk_ins_and_anonymised_customers(): void
    {
        $this->book($this->contact(['email' => null, 'phone' => null]));
        $this->book($this->contact(), '2026-10-21', '10:00', ['source' => 'walk_in']);
        $this->assertSame(0, NotificationLog::count());

        $this->setting('notifications.events.booking_confirmation', false);
        $this->book($this->contact(), '2026-10-22');
        $this->setting('notifications.events.booking_confirmation', true);
        $notifications = app(NotificationService::class);
        foreach ([['anonymized_at' => now()], ['status' => 'blocked']] as $attrs) {
            $this->assertSame(0, $notifications->toCustomer('booking_confirmation', $this->contact($attrs), 'Subject', 'Body', 'key:'.json_encode(array_keys($attrs))));
        }
        $this->assertSame(0, NotificationLog::count());
    }

    public function test_cancelling_notifies_the_customer_once(): void
    {
        $appointment = $this->book($this->contact());
        NotificationLog::query()->delete();

        app(AppointmentStatusService::class)->transition($appointment, AppointmentStatus::Cancelled, $this->admin, 'Customer called', true);

        $log = NotificationLog::firstOrFail();
        $this->assertSame('booking_cancellation', $log->type);
        $this->assertStringContainsString('cancelled', $log->body);
        $this->assertSame(1, NotificationLog::count());
    }

    public function test_reminders_go_out_when_due_exactly_once_and_again_only_after_a_reschedule(): void
    {
        $appointment = $this->book($this->contact(), '2026-10-19', '15:00');
        NotificationLog::query()->delete();
        $appointment->forceFill(['reminder_at' => now()->addHour()])->save();

        $this->artisan('salon:appointment-reminders')->assertSuccessful();
        $this->assertSame(0, NotificationLog::count(), 'not due yet');

        $appointment->forceFill(['reminder_at' => now()->subMinute()])->save();
        $this->artisan('salon:appointment-reminders')->expectsOutputToContain('queued: 1')->assertSuccessful();
        $this->artisan('salon:appointment-reminders')->expectsOutputToContain('queued: 0')->assertSuccessful();
        $this->assertSame(1, NotificationLog::where('type', 'appointment_reminder')->count());

        $appointment->forceFill(['start_at' => '2026-10-19 16:00:00', 'end_at' => '2026-10-19 17:00:00'])->save();
        $this->artisan('salon:appointment-reminders')->assertSuccessful();
        $this->assertSame(2, NotificationLog::where('type', 'appointment_reminder')->count());
    }

    public function test_reminders_skip_cancelled_started_and_past_appointments(): void
    {
        $cancelled = $this->book($this->contact(), '2026-10-19', '15:00');
        $past = $this->book($this->contact(), '2026-10-20', '10:00');
        NotificationLog::query()->delete();
        $cancelled->forceFill(['status' => AppointmentStatus::Cancelled, 'reminder_at' => now()->subHour()])->save();
        $past->forceFill(['start_at' => now()->subHour(), 'end_at' => now(), 'reminder_at' => now()->subHours(2)])->save();

        $this->assertSame(0, app(AutomationService::class)->appointmentReminders());
    }

    public function test_a_failed_delivery_is_recorded_can_be_retried_once_and_never_breaks_the_booking(): void
    {
        $this->setting('notifications.channels.sms', true);
        $this->app->bind(SmsGateway::class, fn () => new class implements SmsGateway
        {
            public function name(): string
            {
                return 'broken';
            }

            public function delivers(): bool
            {
                return true;
            }

            public function send(string $to, string $message): ?string
            {
                throw new RuntimeException('Gateway timeout');
            }
        });

        $appointment = $this->book($this->contact(['email' => null]));

        $this->assertNotNull($appointment->id, 'the booking succeeded although the message could not be sent');
        $log = NotificationLog::where('channel', 'sms')->firstOrFail();
        $this->assertSame('failed', $log->status);
        $this->assertStringContainsString('Gateway timeout', $log->last_error);

        $this->app->bind(SmsGateway::class, fn () => new LogSmsGateway);
        $this->actingAs($this->admin)->post("/notification-logs/{$log->id}/retry")->assertSessionHas('success');
        $this->assertSame('logged', $log->fresh()->status);
        $this->actingAs($this->admin)->post("/notification-logs/{$log->id}/retry")->assertSessionHas('error');
        $this->assertSame(2, $log->fresh()->attempts);
    }

    public function test_an_unknown_sms_driver_fails_loudly_instead_of_pretending(): void
    {
        config(['salon.notifications.sms_driver' => 'acme']);
        $this->setting('notifications.channels.sms', true);

        $this->book($this->contact(['email' => null]));

        $log = NotificationLog::where('channel', 'sms')->firstOrFail();
        $this->assertSame('failed', $log->status);
        $this->assertStringContainsString('not installed', $log->last_error);
    }

    public function test_low_stock_alerts_reach_inventory_managers_once_a_day(): void
    {
        $manager = $this->stockKeeper();
        $other = User::factory()->withPermissions('dashboard.view')->create();
        $this->stockedProduct('2', '5.00', ['reorder_level' => '5', 'name' => 'Conditioner']);
        $this->stockedProduct('50', '5.00', ['reorder_level' => '5', 'name' => 'Plenty']);

        $this->artisan('salon:low-stock-alerts')->assertSuccessful();
        $this->artisan('salon:low-stock-alerts')->assertSuccessful();

        $mine = UserNotification::where('user_id', $manager->id)->get();
        $this->assertCount(1, $mine);
        $this->assertStringContainsString('Conditioner', $mine[0]->body);
        $this->assertStringNotContainsString('Plenty', $mine[0]->body);
        $this->assertSame(0, UserNotification::where('user_id', $other->id)->count());

        Carbon::setTestNow(now()->addDay());
        app(AutomationService::class)->lowStockAlerts();
        $this->assertSame(2, UserNotification::where('user_id', $manager->id)->count());
    }

    public function test_payment_reminders_are_off_by_default_then_follow_the_schedule_and_stop_at_the_maximum(): void
    {
        $cashier = $this->cashier();
        $this->openDrawer($cashier);
        $customer = $this->contact();
        $invoice = $this->issueInvoice($cashier, [['service_id' => $this->service(30, '50.00')->id, 'quantity' => 1]], [$this->cashPayment('20.00')], ['customer_id' => $customer->id]);
        $automation = app(AutomationService::class);
        $issue = fn (int $daysAgo) => DB::table('invoices')->where('id', $invoice->id)->update(['issued_at' => now()->subDays($daysAgo)]);

        $issue(10);
        $this->assertSame(0, $automation->paymentReminders(), 'event disabled by default');

        $this->setting('notifications.events.payment_reminder', true);
        $issue(2);
        $this->assertSame(0, $automation->paymentReminders(), 'not overdue yet');
        $issue(4);
        $this->assertSame(1, $automation->paymentReminders());
        $this->assertSame(0, $automation->paymentReminders(), 'same round: no repeat');
        $issue(11);
        $this->assertSame(1, $automation->paymentReminders());
        $issue(18);
        $this->assertSame(1, $automation->paymentReminders());
        $issue(25);
        $this->assertSame(0, $automation->paymentReminders(), 'three reminders is the maximum');
        $this->assertSame(3, NotificationLog::where('type', 'payment_reminder')->count());
        $this->assertStringContainsString('30.00', NotificationLog::first()->body);
    }

    public function test_paid_voided_and_walk_in_invoices_get_no_payment_reminder(): void
    {
        $this->setting('notifications.events.payment_reminder', true);
        $cashier = $this->cashier();
        $this->openDrawer($cashier);
        $customer = $this->contact();
        $paid = $this->issueInvoice($cashier, [['service_id' => $this->service(30, '10.00')->id, 'quantity' => 1]], [$this->cashPayment('10.00')], ['customer_id' => $customer->id]);
        $walkIn = $this->issueInvoice($cashier, [['service_id' => $this->service(30, '10.00')->id, 'quantity' => 1]], [$this->cashPayment('10.00')]);
        DB::table('invoices')->whereIn('id', [$paid->id, $walkIn->id])->update(['issued_at' => now()->subDays(20)]);

        $this->assertSame(0, app(AutomationService::class)->paymentReminders());
        $this->assertSame(0, Invoice::where('amount_paid', '<', DB::raw('total'))->count());
    }

    public function test_birthday_greetings_need_the_event_on_and_marketing_consent_and_go_once_a_year(): void
    {
        $dob = '1990-'.today()->format('m-d');
        $this->contact(['name' => 'Consented', 'date_of_birth' => $dob, 'marketing_consent' => true]);
        $this->contact(['name' => 'NoConsent', 'date_of_birth' => $dob, 'marketing_consent' => false, 'email' => 'no@example.test']);
        $this->contact(['name' => 'Other day', 'date_of_birth' => '1990-01-02', 'marketing_consent' => true, 'email' => 'other@example.test']);
        $automation = app(AutomationService::class);

        $this->assertSame(0, $automation->birthdayGreetings(), 'event disabled by default');
        $this->setting('notifications.events.birthday', true);
        $this->assertSame(1, $automation->birthdayGreetings());
        $this->assertSame(0, $automation->birthdayGreetings());

        $log = NotificationLog::firstOrFail();
        $this->assertSame('amina@example.test', $log->destination);
        $this->assertStringContainsString('Consented', $log->body);
    }

    public function test_the_in_app_bell_counts_unread_marks_read_and_isolates_users(): void
    {
        $user = User::factory()->withPermissions('dashboard.view')->create();
        $other = User::factory()->withPermissions('dashboard.view')->create();
        $mine = UserNotification::create(['user_id' => $user->id, 'type' => 'low_stock', 'title' => 'Restock now', 'body' => 'Body', 'url' => url('/reports/low_stock')]);
        $theirs = UserNotification::create(['user_id' => $other->id, 'type' => 'low_stock', 'title' => 'Secret', 'body' => 'Body']);

        $this->actingAs($user)->get('/dashboard')->assertSee('Restock now')->assertDontSee('Secret');
        $this->actingAs($user)->get('/dashboard')->assertSee('rounded-pill bg-danger', false);
        $this->actingAs($user)->post("/notifications/{$theirs->id}/read")->assertNotFound();
        $this->assertNull($theirs->fresh()->read_at);
        $this->actingAs($user)->post("/notifications/{$mine->id}/read")->assertRedirect(url('/reports/low_stock'));
        $this->assertNotNull($mine->fresh()->read_at);
        $this->assertSame(0, $this->actingAs($user)->get('/notifications')->assertOk()->viewData('notifications')->where('read_at', null)->count());

        UserNotification::create(['user_id' => $user->id, 'type' => 'x', 'title' => 'Again', 'body' => 'b']);
        $this->actingAs($user)->post('/notifications/read-all')->assertSessionHas('success');
        $this->assertSame(0, UserNotification::where('user_id', $user->id)->whereNull('read_at')->count());
        $this->assertNull($theirs->fresh()->read_at, 'mark-all only touches the signed-in user');
    }

    public function test_an_external_link_in_a_notification_is_never_followed(): void
    {
        $user = User::factory()->withPermissions('dashboard.view')->create();
        $n = UserNotification::create(['user_id' => $user->id, 'type' => 'x', 'title' => 'T', 'body' => 'b', 'url' => 'https://evil.example/phish']);

        $this->actingAs($user)->from('/dashboard')->post("/notifications/{$n->id}/read")->assertRedirect('/dashboard');
    }

    public function test_the_delivery_log_and_settings_need_the_notifications_permission_and_mask_addresses(): void
    {
        $this->book($this->contact());
        $manager = User::factory()->withPermissions('notifications.manage')->create();

        $this->actingAs(User::factory()->withPermissions('dashboard.view')->create())->get('/notification-logs')->assertForbidden();
        $this->actingAs(User::factory()->withPermissions('dashboard.view')->create())->get('/settings/notifications')->assertForbidden();
        $this->actingAs($manager)->get('/notification-logs')->assertOk()->assertSee('a••••')->assertDontSee('amina@example.test')->assertSee('Booking confirmation');
        $this->actingAs($manager)->get('/notification-logs?status=failed')->assertOk()->assertSee('No messages yet');
        $this->actingAs($manager)->get('/notification-logs?status=bogus')->assertSessionHasErrors('status');
    }

    public function test_notification_settings_are_validated_saved_and_audited(): void
    {
        $manager = User::factory()->withPermissions('notifications.manage')->create();
        $payload = ['channels' => ['email' => 1, 'sms' => 1], 'events' => ['birthday' => 1, 'appointment_reminder' => 1], 'reminder_hours_before' => 12,
            'payment_reminder_after_days' => 5, 'payment_reminder_repeat_days' => 3, 'payment_reminder_max' => 2];

        $this->actingAs($manager)->put('/settings/notifications', ['reminder_hours_before' => 0] + $payload)->assertSessionHasErrors('reminder_hours_before');
        $this->actingAs($manager)->put('/settings/notifications', $payload)->assertSessionDoesntHaveErrors();

        $this->assertTrue(app(SettingsService::class)->get('notifications.channels.sms'));
        $this->assertFalse(app(SettingsService::class)->get('notifications.channels.in_app'));
        $this->assertFalse(app(SettingsService::class)->get('notifications.events.booking_confirmation'));
        $this->assertSame(12, app(SettingsService::class)->get('booking.reminder_hours_before'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.notifications_updated']);
    }

    public function test_the_membership_expiry_command_is_scheduled_and_runs(): void
    {
        $this->artisan('salon:expire-memberships')->assertSuccessful();
        $scheduled = collect(app(Schedule::class)->events())->map(fn ($e) => $e->command)->implode(' ');
        foreach (['appointment-reminders', 'low-stock-alerts', 'payment-reminders', 'birthday-greetings', 'expire-memberships'] as $command) {
            $this->assertStringContainsString("salon:{$command}", $scheduled);
        }
    }
}
