<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\Role;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RoleController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index()
    {
        $this->authorize('roles.manage');
        $roles = Role::withCount(['users', 'permissions'])->orderBy('name')->get();

        return view('roles.index', compact('roles'));
    }

    public function create()
    {
        $this->authorize('roles.manage');

        return view('roles.form', ['role' => new Role, 'groups' => $this->groups(), 'selected' => []]);
    }

    public function store(Request $request)
    {
        $this->authorize('roles.manage');
        $data = $this->validated($request);
        $ids = $this->permissionIds($request, $data['permissions'] ?? []);
        DB::transaction(function () use ($data, $ids) {
            $role = new Role(['name' => $data['name'], 'description' => $data['description'] ?? null, 'slug' => $this->uniqueSlug($data['name'])]);
            $role->forceFill(['is_system' => false])->save();
            $role->permissions()->sync($ids);
            $this->audit->log('role.created', $role, null, ['name' => $role->name, 'permissions' => $this->keys($ids)]);
        });

        return redirect()->route('roles.index')->with('success', 'Role created.');
    }

    public function edit(Role $role)
    {
        $this->authorize('roles.manage');
        abort_if($role->isSuperAdmin(), 403, 'The Super Administrator role is fixed.');

        return view('roles.form', ['role' => $role, 'groups' => $this->groups(), 'selected' => $role->permissions()->pluck('permissions.id')->all()]);
    }

    public function update(Request $request, Role $role)
    {
        $this->authorize('roles.manage');
        abort_if($role->isSuperAdmin(), 403, 'The Super Administrator role is fixed.');
        $data = $this->validated($request, $role);
        $old = $role->permissions()->pluck('permissions.key')->sort()->values()->all();
        $ids = $this->permissionIds($request, $data['permissions'] ?? [], $role);
        $before = ['name' => $role->name, 'permissions' => $old];

        DB::transaction(function () use ($role, $data, $ids, $before) {
            if (! $role->is_system) {
                $role->fill(['name' => $data['name']]);
            }
            $role->fill(['description' => $data['description'] ?? null])->save();
            $role->permissions()->sync($ids);
            $this->audit->log('role.updated', $role, $before, ['name' => $role->name, 'permissions' => $this->keys($ids)]);
        });

        // Role edits affect other users' effective permissions; the per-request cache makes this immediate.
        return redirect()->route('roles.index')->with('success', 'Role updated.');
    }

    public function destroy(Role $role)
    {
        $this->authorize('roles.manage');
        abort_if($role->is_system, 403, 'System roles cannot be deleted.');
        if ($role->users()->exists()) {
            throw ValidationException::withMessages(['role' => 'Reassign the users of this role before deleting it.']);
        }
        $this->audit->log('role.deleted', $role, ['name' => $role->name], null);
        $role->delete();

        return redirect()->route('roles.index')->with('success', 'Role deleted.');
    }

    private function validated(Request $request, ?Role $role = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('roles', 'name')->ignore($role?->id)],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['array'],
            'permissions.*' => ['integer', 'exists:permissions,id'],
        ]);
    }

    /** An editor can only grant permissions they hold themselves; existing grants on the role are kept. */
    private function permissionIds(Request $request, array $ids, ?Role $role = null): array
    {
        $actor = $request->user();
        $ids = array_map('intval', $ids);
        $existing = $role ? $role->permissions()->pluck('permissions.id')->all() : [];
        $perms = Permission::whereIn('id', $ids)->get();
        foreach ($perms as $p) {
            if (! in_array($p->id, $existing, true) && ! $actor->hasPermission($p->key)) {
                throw ValidationException::withMessages(['permissions' => "You cannot grant '{$p->key}' because you do not hold it."]);
            }
        }
        // Permissions the actor lacks and that are already on the role cannot be removed by them either.
        $locked = Permission::whereIn('id', $existing)->get()->filter(fn ($p) => ! $actor->hasPermission($p->key))->pluck('id')->all();

        return array_values(array_unique(array_merge($ids, $locked)));
    }

    private function keys(array $ids): array
    {
        return Permission::whereIn('id', $ids)->orderBy('key')->pluck('key')->all();
    }

    private function groups()
    {
        return Permission::orderBy('group')->orderBy('key')->get()->groupBy('group');
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'role';
        $slug = $base;
        $i = 2;
        while (Role::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
