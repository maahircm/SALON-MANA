<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserAdministrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_creates_user_with_roles_hashed_password_and_audit(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->superAdmin()->create();
        $role = Role::where('slug', 'receptionist')->first();
        $this->actingAs($admin)->post('/users', [
            'name' => 'Amina', 'email' => 'Amina@Example.com', 'password' => 'Passw0rdPassw0rd', 'password_confirmation' => 'Passw0rdPassw0rd', 'roles' => [$role->id],
        ])->assertRedirect('/users');
        $user = User::where('email', 'amina@example.com')->firstOrFail();
        $this->assertNotSame('Passw0rdPassw0rd', $user->password);
        $this->assertTrue($user->must_change_password);
        $this->assertTrue($user->roles->contains($role));
        $log = AuditLog::where('action', 'user.created')->firstOrFail();
        $this->assertSame($admin->id, $log->user_id);
        $this->assertStringNotContainsString('Passw0rd', json_encode($log->toArray()));
    }

    public function test_validation_errors_preserve_old_input(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $this->actingAs($admin)->from('/users/create')->post('/users', ['name' => 'Bob', 'email' => 'not-an-email', 'password' => 'x'])
            ->assertRedirect('/users/create')->assertSessionHasErrors(['email', 'password'])->assertSessionHasInput('name', 'Bob');
        $this->actingAs($admin)->get('/users/create')->assertOk();
    }

    public function test_email_must_be_unique(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $this->actingAs($admin)->post('/users', ['name' => 'D', 'email' => $admin->email, 'password' => 'Passw0rdPassw0rd', 'password_confirmation' => 'Passw0rdPassw0rd'])->assertSessionHasErrors('email');
    }

    public function test_mass_assignment_cannot_set_is_active_or_flags(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $this->actingAs($admin)->post('/users', [
            'name' => 'M', 'email' => 'm@example.com', 'password' => 'Passw0rdPassw0rd', 'password_confirmation' => 'Passw0rdPassw0rd',
            'is_active' => 0, 'two_factor_confirmed_at' => now(), 'id' => 9999,
        ]);
        $u = User::where('email', 'm@example.com')->firstOrFail();
        $this->assertTrue($u->is_active);
        $this->assertNotSame(9999, $u->id);
        $this->assertNull($u->two_factor_confirmed_at);
    }

    public function test_deactivation_audited_and_cannot_deactivate_self(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $target = User::factory()->create();
        $this->actingAs($admin)->post("/users/{$target->id}/toggle-active", ['reason' => 'Left the salon'])->assertRedirect();
        $this->assertFalse($target->fresh()->is_active);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.deactivated', 'entity_id' => (string) $target->id, 'reason' => 'Left the salon']);
        $this->actingAs($admin)->post("/users/{$admin->id}/toggle-active")->assertSessionHasErrors('user');
        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_last_super_admin_is_protected(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $only = User::factory()->superAdmin()->create();
        $manager = User::factory()->superAdmin()->create();
        // two admins: removing one's role is allowed, then the remaining one is protected
        $this->actingAs($only)->put("/users/{$manager->id}", ['name' => $manager->name, 'email' => $manager->email, 'roles' => []])->assertRedirect('/users');
        $this->actingAs($manager)->put("/users/{$only->id}", ['name' => $only->name, 'email' => $only->email, 'roles' => []])->assertForbidden();
        $this->actingAs($only)->put("/users/{$only->id}", ['name' => $only->name, 'email' => $only->email, 'roles' => []])->assertSessionHasErrors('roles');
        $this->assertTrue($only->fresh()->isSuperAdmin());
    }

    public function test_search_and_pagination(): void
    {
        $admin = User::factory()->superAdmin()->create();
        User::factory()->count(20)->create();
        User::factory()->create(['name' => 'Zainab Unique']);
        $this->actingAs($admin)->get('/users?q=Zainab')->assertOk()->assertSee('Zainab Unique')->assertDontSee('Next');
        $this->actingAs($admin)->get('/users')->assertOk()->assertSee('page=2', false);
    }

    public function test_create_admin_command(): void
    {
        putenv('SALON_ADMIN_PASSWORD=Sup3rSecretPass');
        $this->artisan('salon:create-admin', ['--name' => 'Root', '--email' => 'root@example.com'])->assertSuccessful();
        putenv('SALON_ADMIN_PASSWORD');
        $u = User::where('email', 'root@example.com')->firstOrFail();
        $this->assertTrue($u->isSuperAdmin());
        $this->assertTrue(Hash::check('Sup3rSecretPass', $u->password));
    }

    public function test_no_default_users_are_seeded(): void
    {
        $this->seed();
        $this->assertSame(0, User::count());
    }
}
