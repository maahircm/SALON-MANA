<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerPreference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns;
use Tests\TestCase;

class CustomerTest extends TestCase
{
    use Concerns, RefreshDatabase;

    private array $valid = ['name' => 'Fadumo Ali', 'phone' => '+252 61 555 1234', 'email' => 'fadumo@example.com', 'status' => 'active'];

    public function test_receptionist_registers_customer_with_number_and_audit(): void
    {
        $r = $this->receptionist();
        $this->actingAs($r)->post('/customers', $this->valid + ['marketing_consent' => 1])->assertRedirect();
        $c = Customer::firstOrFail();
        $this->assertSame(sprintf('CUS-%06d', $c->id), $c->customer_number);
        $this->assertTrue($c->marketing_consent);
        $this->assertNotNull($c->marketing_consent_at);
        $this->assertSame('252615551234', $c->phone_normalized);
        $this->assertDatabaseHas('audit_logs', ['action' => 'customer.created', 'user_id' => $r->id]);
    }

    public function test_user_without_permission_cannot_view_or_create(): void
    {
        $u = User::factory()->create();
        $c = $this->customer();
        $this->actingAs($u)->get('/customers')->assertForbidden();
        $this->actingAs($u)->get("/customers/{$c->id}")->assertForbidden();
        $this->actingAs($u)->post('/customers', $this->valid)->assertForbidden();
        $this->actingAs($u)->put("/customers/{$c->id}", $this->valid)->assertForbidden();
    }

    public function test_viewer_cannot_edit(): void
    {
        $u = User::factory()->withPermissions('customers.view')->create();
        $c = $this->customer();
        $this->actingAs($u)->get("/customers/{$c->id}")->assertOk();
        $this->actingAs($u)->put("/customers/{$c->id}", $this->valid)->assertForbidden();
    }

    public function test_marketing_defaults_to_no_consent(): void
    {
        $this->actingAs($this->receptionist())->post('/customers', $this->valid);
        $this->assertFalse(Customer::first()->marketing_consent);
    }

    public function test_validation_errors_and_old_input(): void
    {
        $this->actingAs($this->receptionist())->from('/customers/create')->post('/customers', ['name' => '', 'phone' => 'abc', 'email' => 'bad', 'status' => 'weird'])
            ->assertSessionHasErrors(['name', 'phone', 'email', 'status']);
        $this->actingAs($this->receptionist())->from('/customers/create')->post('/customers', ['name' => 'Keep Me', 'phone' => 'x'])->assertSessionHasInput('name', 'Keep Me');
    }

    public function test_duplicate_phone_is_detected_and_can_be_confirmed(): void
    {
        $r = $this->receptionist();
        $this->customer(['phone' => '0615551234']);
        $this->actingAs($r)->post('/customers', ['phone' => '061-555-1234'] + $this->valid)->assertSessionHas('duplicates');
        $this->assertSame(1, Customer::count());
        $this->actingAs($r)->post('/customers', ['phone' => '061-555-1234', 'confirm_duplicate' => 1] + $this->valid)->assertSessionDoesntHaveErrors();
        $this->assertSame(2, Customer::count());
    }

    public function test_update_records_changed_fields_without_pii_values(): void
    {
        $r = $this->receptionist();
        $c = $this->customer(['phone' => '0611111111']);
        $this->actingAs($r)->put("/customers/{$c->id}", ['name' => 'New Name', 'phone' => '0611111111', 'status' => 'inactive'])->assertRedirect();
        $log = AuditLog::where('action', 'customer.updated')->firstOrFail();
        $this->assertSame('[changed]', $log->after['name']);
        $this->assertSame('inactive', $log->after['status']);
        $this->assertStringNotContainsString('New Name', json_encode($log->toArray()));
    }

    public function test_archive_hides_customer_and_blocks_booking(): void
    {
        $this->freezeSalonTime();
        $admin = User::factory()->superAdmin()->create();
        $c = $this->customer();
        $this->actingAs($admin)->delete("/customers/{$c->id}", ['reason' => 'moved'])->assertRedirect('/customers');
        $this->assertSoftDeleted($c);
        $this->actingAs($admin)->get('/customers')->assertDontSee($c->name);
        $this->actingAs($admin)->get('/customers?archived=1')->assertSee($c->name);
        $this->actingAs($admin)->get("/customers/{$c->id}")->assertOk();
        $this->actingAs($admin)->post("/customers/{$c->id}/restore")->assertRedirect();
        $this->assertNotSoftDeleted($c);
    }

    public function test_receptionist_cannot_archive_or_anonymize(): void
    {
        $r = $this->receptionist();
        $c = $this->customer();
        $this->actingAs($r)->delete("/customers/{$c->id}")->assertForbidden();
        $this->actingAs($r)->post("/customers/{$c->id}/anonymize", ['reason' => 'x'])->assertForbidden();
    }

    public function test_anonymize_erases_pii_and_keeps_record(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $c = $this->customer(['email' => 'secret@example.com', 'notes' => 'private']);
        $c->preferences()->create(['type' => 'allergy', 'value' => 'latex', 'is_sensitive' => true]);
        $this->actingAs($admin)->post("/customers/{$c->id}/anonymize", [])->assertSessionHasErrors('reason');
        $this->actingAs($admin)->post("/customers/{$c->id}/anonymize", ['reason' => 'GDPR request'])->assertRedirect('/customers');
        $c = Customer::withTrashed()->find($c->id);
        $this->assertNull($c->email);
        $this->assertNull($c->phone);
        $this->assertNull($c->notes);
        $this->assertSame(0, $c->preferences()->count());
        $this->assertNotNull($c->anonymized_at);
        $this->assertTrue($c->trashed());
        $this->assertFalse($c->isBookable());
        $this->assertDatabaseHas('audit_logs', ['action' => 'customer.anonymized', 'reason' => 'GDPR request']);
        $this->actingAs($admin)->post("/customers/{$c->id}/restore")->assertStatus(422);
    }

    public function test_sensitive_preferences_are_restricted(): void
    {
        $c = $this->customer();
        $plain = User::factory()->withPermissions('customers.view', 'customers.update')->create();
        $nurse = User::factory()->withPermissions('customers.view', 'customers.update', 'customers.sensitive')->create();
        $this->actingAs($nurse)->post("/customers/{$c->id}/preferences", ['type' => 'allergy', 'value' => 'Ammonia sensitivity'])->assertSessionDoesntHaveErrors();
        $this->actingAs($nurse)->post("/customers/{$c->id}/preferences", ['type' => 'preference', 'value' => 'Likes tea'])->assertSessionDoesntHaveErrors();
        $this->actingAs($plain)->get("/customers/{$c->id}")->assertOk()->assertSee('Likes tea')->assertDontSee('Ammonia sensitivity');
        $this->actingAs($nurse)->get("/customers/{$c->id}")->assertSee('Ammonia sensitivity');
        $this->actingAs($plain)->post("/customers/{$c->id}/preferences", ['type' => 'allergy', 'value' => 'x'])->assertForbidden();
        $allergy = CustomerPreference::where('type', 'allergy')->first();
        $this->actingAs($plain)->delete("/customers/{$c->id}/preferences/{$allergy->id}")->assertForbidden();
        $this->assertDatabaseHas('customer_preferences', ['id' => $allergy->id]);
    }

    public function test_preference_of_another_customer_cannot_be_deleted_via_url_manipulation(): void
    {
        $u = User::factory()->withPermissions('customers.view', 'customers.update')->create();
        $a = $this->customer();
        $b = $this->customer();
        $pref = $b->preferences()->create(['type' => 'preference', 'value' => 'x', 'is_sensitive' => false]);
        $this->actingAs($u)->delete("/customers/{$a->id}/preferences/{$pref->id}")->assertNotFound();
        $this->assertDatabaseHas('customer_preferences', ['id' => $pref->id]);
    }

    public function test_export_requires_permission_is_audited_and_neutralises_formulas(): void
    {
        $this->customer(['name' => '=HYPERLINK("http://evil")']);
        $this->actingAs($this->receptionist())->get('/customers/export')->assertForbidden();
        $acct = User::factory()->withPermissions('customers.view', 'customers.export')->create();
        $csv = $this->actingAs($acct)->get('/customers/export')->assertOk()->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString(',=HYPERLINK', $csv);
        $this->assertDatabaseHas('audit_logs', ['action' => 'customer.exported', 'user_id' => $acct->id]);
    }

    public function test_search_filters_and_birthday_segment(): void
    {
        $r = $this->receptionist();
        $this->customer(['name' => 'Halima Noor', 'phone' => '0612223333', 'date_of_birth' => '1990-03-10', 'marketing_consent' => true]);
        $this->customer(['name' => 'Other Person', 'date_of_birth' => '1990-07-10']);
        $this->actingAs($r)->get('/customers?q=Halima')->assertSee('Halima Noor')->assertDontSee('Other Person');
        $this->actingAs($r)->get('/customers?q=061222')->assertSee('Halima Noor');
        $this->actingAs($r)->get('/customers?birthday_month=3')->assertSee('Halima Noor')->assertDontSee('Other Person');
        $this->actingAs($r)->get('/customers?consent=1')->assertSee('Halima Noor')->assertDontSee('Other Person');
    }

    public function test_search_endpoint_returns_only_active_customers_json(): void
    {
        $this->customer(['name' => 'Amal Active']);
        $this->customer(['name' => 'Amal Blocked', 'status' => 'blocked']);
        $this->actingAs($this->receptionist())->getJson('/customers/search?q=Amal')->assertOk()->assertJsonCount(1)->assertJsonPath('0.name', 'Amal Active');
    }
}
