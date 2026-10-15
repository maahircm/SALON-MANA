<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_entries_are_immutable(): void
    {
        $log = app(AuditLogger::class)->log('test.action');
        $this->expectException(LogicException::class);
        $log->update(['action' => 'tampered']);
    }

    public function test_audit_entries_cannot_be_deleted(): void
    {
        $log = app(AuditLogger::class)->log('test.action');
        $this->expectException(LogicException::class);
        $log->delete();
    }

    public function test_secrets_are_redacted(): void
    {
        $log = app(AuditLogger::class)->log('test.redact', null, ['password' => 'hunter2'], ['nested' => ['token' => 'abc', 'name' => 'ok']]);
        $this->assertSame('[redacted]', $log->before['password']);
        $this->assertSame('[redacted]', $log->after['nested']['token']);
        $this->assertSame('ok', $log->after['nested']['name']);
    }

    public function test_request_records_correlation_id_header_and_audit_value(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $resp = $this->actingAs($admin)->post('/users', ['name' => 'C', 'email' => 'c@example.com', 'password' => 'Passw0rdPassw0rd', 'password_confirmation' => 'Passw0rdPassw0rd']);
        $this->assertSame($resp->headers->get('X-Correlation-Id'), AuditLog::where('action', 'user.created')->value('correlation_id'));
    }

    public function test_audit_viewer_is_restricted_and_has_no_write_routes(): void
    {
        $this->actingAs(User::factory()->create())->get('/audit-log')->assertForbidden();
        $viewer = User::factory()->withPermissions('audit.view')->create();
        $log = app(AuditLogger::class)->log('x.y');
        $this->actingAs($viewer)->get('/audit-log')->assertOk();
        $this->actingAs($viewer)->get("/audit-log/{$log->id}")->assertOk();
        $this->actingAs($viewer)->delete("/audit-log/{$log->id}")->assertStatus(405);
        $this->actingAs($viewer)->put("/audit-log/{$log->id}")->assertStatus(405);
    }

    public function test_role_change_is_audited_with_before_and_after(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->superAdmin()->create();
        $role = Role::where('slug', 'cashier')->first();
        $perm = Permission::where('key', 'customers.view')->first();
        $this->actingAs($admin)->put("/roles/{$role->id}", ['name' => 'Cashier', 'permissions' => [$perm->id]]);
        $log = AuditLog::where('action', 'role.updated')->firstOrFail();
        $this->assertContains('pos.use', $log->before['permissions']);
        $this->assertSame(['customers.view'], $log->after['permissions']);
    }
}
