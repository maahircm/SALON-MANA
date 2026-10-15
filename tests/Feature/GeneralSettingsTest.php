<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Promotion;
use App\Models\User;
use App\Services\SettingsService;
use App\Support\RuntimeSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns;
use Tests\TestCase;

class GeneralSettingsTest extends TestCase
{
    use Concerns, RefreshDatabase;

    private User $admin;

    private string $originalTimezone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalTimezone = date_default_timezone_get();
        Storage::fake('public');
        $this->admin = User::factory()->withPermissions('settings.manage')->create();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->originalTimezone);
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function payload(array $override = []): array
    {
        return $override + ['salon_name' => 'Glow Studio', 'phone' => '+252 61 000 000', 'email' => 'hello@glow.test', 'address' => "1 Main Street\nMogadishu", 'timezone' => 'Africa/Mogadishu',
            'currency' => 'USD', 'currency_symbol' => '$', 'date_format' => 'd/m/Y', 'time_format' => 'h:i A', 'tax_label' => 'VAT', 'tax_number' => 'TAX-123', 'tax_default_rate' => '5.00',
            'receipt_paper' => 'a4', 'receipt_show_logo' => 1, 'receipt_show_tax_number' => 1, 'receipt_show_cashier' => 0];
    }

    public function test_only_settings_managers_can_open_or_save_general_settings(): void
    {
        $this->actingAs(User::factory()->withPermissions('dashboard.view')->create())->get('/settings/general')->assertForbidden();
        $this->actingAs(User::factory()->withPermissions('dashboard.view')->create())->put('/settings/general', $this->payload())->assertForbidden();
        $this->actingAs($this->admin)->get('/settings/general')->assertOk()->assertSee('Salon profile');
    }

    public function test_saved_values_are_applied_at_runtime_and_audited(): void
    {
        $this->actingAs($this->admin)->put('/settings/general', $this->payload(['timezone' => 'Africa/Nairobi', 'currency' => 'KES', 'currency_symbol' => 'KSh ']))->assertSessionDoesntHaveErrors();

        $settings = app(SettingsService::class);
        $this->assertSame('Glow Studio', $settings->get('general.salon_name'));
        $this->assertTrue($settings->get('receipt.show_logo'));
        $this->assertFalse($settings->get('receipt.show_cashier'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.general_updated']);

        RuntimeSettings::apply($settings);   // what every later request does on boot
        $this->assertSame('Glow Studio', config('salon.name'));
        $this->assertSame('d/m/Y', config('salon.date_format'));
        $this->assertSame('Africa/Nairobi', config('app.timezone'));
        $this->assertSame('Africa/Nairobi', date_default_timezone_get());
        $this->assertSame('KSh12.50', money('12.5'));
        $this->actingAs($this->admin)->get('/dashboard')->assertSee('Glow Studio');
    }

    public function test_invalid_values_are_rejected(): void
    {
        foreach ([['timezone' => 'Mars/Base'], ['currency' => 'usd'], ['currency' => 'DOLLAR'], ['date_format' => 'Y/m/d'], ['time_format' => 'x'], ['email' => 'nope'], ['salon_name' => ''],
            ['tax_default_rate' => '120'], ['tax_default_rate' => '1.234'], ['receipt_paper' => 'letter']] as $override) {
            $this->actingAs($this->admin)->put('/settings/general', $this->payload($override))->assertSessionHasErrors(array_keys($override));
        }
    }

    public function test_the_timezone_and_currency_lock_once_business_data_exists(): void
    {
        $this->actingAs($this->admin)->put('/settings/general', $this->payload(['timezone' => 'Africa/Nairobi']))->assertSessionDoesntHaveErrors();

        $this->freezeSalonTime();
        $cashier = $this->cashier();
        $this->openDrawer($cashier);
        $this->issueInvoice($cashier, [['service_id' => $this->service(30, '10.00')->id, 'quantity' => 1]], [$this->cashPayment('10.00')]);

        $this->actingAs($this->admin)->put('/settings/general', $this->payload(['timezone' => 'Asia/Dubai']))->assertSessionHasErrors('timezone');
        $this->actingAs($this->admin)->put('/settings/general', $this->payload(['timezone' => 'Africa/Nairobi', 'currency' => 'EUR', 'currency_symbol' => '€']))->assertSessionHasErrors('currency');
        $this->actingAs($this->admin)->put('/settings/general', $this->payload(['timezone' => 'Africa/Nairobi', 'salon_name' => 'Renamed']))->assertSessionDoesntHaveErrors();
        $this->assertSame('Renamed', app(SettingsService::class)->get('general.salon_name'));
        $this->actingAs($this->admin)->get('/settings/general')->assertSee('Locked');
    }

    public function test_logo_uploads_are_validated_stored_privately_named_and_replaced(): void
    {
        $this->actingAs($this->admin)->put('/settings/general', $this->payload(['logo' => UploadedFile::fake()->create('logo.svg', 5, 'image/svg+xml')]))->assertSessionHasErrors('logo');
        $this->actingAs($this->admin)->put('/settings/general', $this->payload(['logo' => UploadedFile::fake()->create('shell.php', 5, 'text/x-php')]))->assertSessionHasErrors('logo');
        $this->actingAs($this->admin)->put('/settings/general', $this->payload(['logo' => UploadedFile::fake()->image('big.png', 3000, 100)]))->assertSessionHasErrors('logo');
        $this->actingAs($this->admin)->put('/settings/general', $this->payload(['logo' => UploadedFile::fake()->image('huge.png', 100, 100)->size(2048)]))->assertSessionHasErrors('logo');
        $this->assertSame([], Storage::disk('public')->allFiles());

        $this->actingAs($this->admin)->put('/settings/general', $this->payload(['logo' => UploadedFile::fake()->image('my logo ../x.png', 120, 80)]))->assertSessionDoesntHaveErrors();
        $first = app(SettingsService::class)->get('general.logo_path');
        $this->assertMatchesRegularExpression('#^logos/[a-f0-9]{24}\.png$#', $first);
        Storage::disk('public')->assertExists($first);

        $this->actingAs($this->admin)->put('/settings/general', $this->payload(['logo' => UploadedFile::fake()->image('second.jpg', 50, 50)]))->assertSessionDoesntHaveErrors();
        Storage::disk('public')->assertMissing($first);

        $second = app(SettingsService::class)->get('general.logo_path');
        $this->actingAs($this->admin)->delete('/settings/general/logo')->assertSessionHas('success');
        Storage::disk('public')->assertMissing($second);
        $this->assertNull(app(SettingsService::class)->get('general.logo_path'));
    }

    public function test_receipts_follow_the_salon_profile_and_always_show_coupon_discounts(): void
    {
        $this->freezeSalonTime();
        $this->actingAs($this->admin)->put('/settings/general', $this->payload(['logo' => UploadedFile::fake()->image('l.png', 50, 50)]))->assertSessionDoesntHaveErrors();
        $cashier = $this->cashier();
        $this->openDrawer($cashier);
        $invoice = $this->issueInvoice($cashier, [['service_id' => $this->service(30, '40.00', ['tax_rate' => '10.00'])->id, 'quantity' => 1]], [$this->cashPayment('44.00')]);

        $html = $this->actingAs($cashier)->get("/invoices/{$invoice->id}/receipt")->assertOk()->getContent();

        $this->assertStringContainsString('1 Main Street', $html);
        $this->assertStringContainsString('VAT no. TAX-123', $html);
        $this->assertStringContainsString('storage/logos/', $html);
        $this->assertStringContainsString('width:700px', $html);
        $this->assertStringNotContainsString('Served by', $html);

        $this->actingAs($this->admin)->put('/settings/general', $this->payload(['receipt_show_logo' => 0, 'receipt_show_tax_number' => 0, 'receipt_show_cashier' => 1, 'receipt_paper' => '80mm']))->assertSessionDoesntHaveErrors();
        $html = $this->actingAs($cashier)->get("/invoices/{$invoice->id}/receipt")->getContent();
        $this->assertStringNotContainsString('TAX-123', $html);
        $this->assertStringNotContainsString('storage/logos/', $html);
        $this->assertStringContainsString('Served by', $html);
        $this->assertStringContainsString('width:300px', $html);

        $promotion = Promotion::create(['name' => 'Ten', 'discount_type' => 'percent', 'discount_value' => '10.00', 'min_purchase' => '0.00', 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(), 'is_active' => true]);
        (new Coupon(['code' => 'SAVE10', 'is_active' => true]))->forceFill(['promotion_id' => $promotion->id, 'used_count' => 0])->save();
        $discounted = $this->issueInvoice($cashier, [['service_id' => $this->service(30, '40.00', ['tax_rate' => '10.00'])->id, 'quantity' => 1]], [$this->cashPayment('39.60')], ['coupon_code' => 'SAVE10']);
        $this->actingAs($cashier)->get("/invoices/{$discounted->id}/receipt")->assertSee('Coupon SAVE10')->assertSee('−$4.00', false)->assertSee('VAT');
    }

    public function test_the_default_tax_rate_prefills_new_services_and_products_only(): void
    {
        $this->actingAs($this->admin)->put('/settings/general', $this->payload(['tax_default_rate' => '7.50']))->assertSessionDoesntHaveErrors();
        $viewer = User::factory()->superAdmin()->create();

        $this->actingAs($viewer)->get('/services/create')->assertSee('value="7.50"', false);
        $this->actingAs($viewer)->get('/inventory/products/create')->assertSee('value="7.50"', false);
        $existing = $this->service(30, '10.00', ['tax_rate' => '2.00']);
        $this->actingAs($viewer)->get("/services/{$existing->id}/edit")->assertSee('value="2.00"', false);
    }
}
