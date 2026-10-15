<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_all_predefined_roles_and_is_idempotent(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->assertSame(count(config('rbac.roles')), Role::count());
        foreach (['Super Administrator', 'Salon Owner', 'Salon Manager', 'Receptionist', 'Stylist / Beautician', 'Cashier', 'Inventory Manager', 'Accountant', 'HR Officer'] as $name) {
            $this->assertDatabaseHas('roles', ['name' => $name]);
        }
    }

    public function test_least_privilege_defaults(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $make = fn (string $slug) => tap(User::factory()->create(), fn ($u) => $u->roles()->attach(Role::where('slug', $slug)->first()));
        $receptionist = $make('receptionist');
        $this->assertTrue($receptionist->hasPermission('customers.create'));
        $this->assertFalse($receptionist->hasPermission('pos.refund'));
        $this->assertFalse($receptionist->hasPermission('users.manage'));
        $cashier = $make('cashier');
        $this->assertTrue($cashier->hasPermission('pos.use'));
        $this->assertFalse($cashier->hasPermission('pos.refund'));
        $this->assertFalse($cashier->hasPermission('inventory.adjust'));
        $this->assertFalse($make('stylist')->hasPermission('customers.export'));
        $this->assertFalse($make('owner')->hasPermission('roles.manage'));
    }

    public function test_user_without_permission_gets_403_everywhere_in_admin(): void
    {
        $u = User::factory()->create();
        foreach (['/users', '/users/create', '/roles', '/roles/create', '/audit-log'] as $url) {
            $this->actingAs($u)->get($url)->assertForbidden();
        }
        $this->actingAs($u)->post('/users', [])->assertForbidden();
    }

    public function test_permission_grants_access_and_super_admin_bypasses(): void
    {
        $this->actingAs(User::factory()->withPermissions('users.view')->create())->get('/users')->assertOk();
        $this->actingAs(User::factory()->withPermissions('users.view')->create())->get('/users/create')->assertForbidden();
        $this->actingAs(User::factory()->superAdmin()->create())->get('/roles')->assertOk();
    }

    public function test_cannot_assign_role_exceeding_own_permissions(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $actor = User::factory()->withPermissions('users.manage', 'users.view')->create();
        $owner = Role::where('slug', 'owner')->first();
        $this->actingAs($actor)->post('/users', [
            'name' => 'X', 'email' => 'x@example.com', 'password' => 'Passw0rdPassw0rd', 'password_confirmation' => 'Passw0rdPassw0rd', 'roles' => [$owner->id],
        ])->assertSessionHasErrors('roles');
        $this->assertDatabaseMissing('users', ['email' => 'x@example.com']);
    }

    public function test_non_super_admin_cannot_assign_or_edit_super_admin(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $super = User::factory()->superAdmin()->create();
        $actor = User::factory()->withPermissions('users.manage', 'users.view', 'roles.manage')->create();
        $this->actingAs($actor)->get("/users/{$super->id}/edit")->assertForbidden();
        $this->actingAs($actor)->post("/users/{$super->id}/toggle-active")->assertForbidden();
        $role = Role::where('slug', Role::SUPER_ADMIN)->first();
        $this->actingAs($actor)->get("/roles/{$role->id}/edit")->assertForbidden();
    }

    public function test_role_editor_cannot_grant_permissions_they_lack(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $actor = User::factory()->withPermissions('roles.manage', 'customers.view')->create();
        $refund = Permission::where('key', 'pos.refund')->first();
        $this->actingAs($actor)->post('/roles', ['name' => 'Sneaky', 'permissions' => [$refund->id]])->assertSessionHasErrors('permissions');
        $this->assertDatabaseMissing('roles', ['name' => 'Sneaky']);
    }

    public function test_custom_role_crud_and_system_role_protection(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->superAdmin()->create();
        $perm = Permission::where('key', 'customers.view')->first();
        $this->actingAs($admin)->post('/roles', ['name' => 'Front Desk Lead', 'permissions' => [$perm->id]])->assertRedirect('/roles');
        $role = Role::where('name', 'Front Desk Lead')->first();
        $this->assertFalse($role->is_system);
        $this->assertTrue($role->permissions->contains($perm));
        $this->actingAs($admin)->delete("/roles/{$role->id}")->assertRedirect('/roles');
        $this->assertDatabaseMissing('roles', ['name' => 'Front Desk Lead']);
        $system = Role::where('slug', 'cashier')->first();
        $this->actingAs($admin)->delete("/roles/{$system->id}")->assertForbidden();
        $this->assertDatabaseHas('audit_logs', ['action' => 'role.created']);
    }

    public function test_role_with_users_cannot_be_deleted(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $role = Role::create(['slug' => 'temp', 'name' => 'Temp']);
        $u = User::factory()->create();
        $u->roles()->attach($role);
        $this->actingAs($admin)->delete("/roles/{$role->id}")->assertSessionHasErrors('role');
        $this->assertDatabaseHas('roles', ['id' => $role->id]);
    }

    public function test_deactivated_user_loses_gate_access(): void
    {
        $u = User::factory()->withPermissions('users.view')->inactive()->create();
        $this->assertFalse($u->can('users.view'));
    }
}
