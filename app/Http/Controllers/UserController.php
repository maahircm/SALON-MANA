<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(Request $request)
    {
        $this->authorize('users.view');
        $users = User::with('roles')
            ->when($request->query('q'), fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%")))
            ->when($request->query('status') === 'inactive', fn ($q) => $q->where('is_active', false))
            ->orderBy('name')->paginate(15)->withQueryString();

        return view('users.index', compact('users'));
    }

    public function create()
    {
        $this->authorize('users.manage');

        return view('users.form', ['user' => new User, 'roles' => $this->assignableRoles(), 'selected' => []]);
    }

    public function store(Request $request)
    {
        $this->authorize('users.manage');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190', Rule::unique('users', 'email')],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'roles' => ['array'],
            'roles.*' => ['integer', 'exists:roles,id'],
        ]);
        $roleIds = $this->validatedRoleIds($request, $data['roles'] ?? []);

        $user = DB::transaction(function () use ($data, $roleIds) {
            $user = new User(collect($data)->only(['name', 'email', 'phone', 'password'])->all());
            $user->email = strtolower($user->email);
            $user->forceFill(['must_change_password' => true, 'is_active' => true, 'password_changed_at' => now()])->save();
            $user->roles()->sync($roleIds);
            $this->audit->log('user.created', $user, null, ['name' => $user->name, 'email' => $user->email, 'roles' => $roleIds]);

            return $user;
        });

        return redirect()->route('users.index')->with('success', "User {$user->name} created. They must change the password at first login.");
    }

    public function edit(User $user)
    {
        $this->authorize('users.manage');
        $this->guardTarget($user);

        return view('users.form', ['user' => $user, 'roles' => $this->assignableRoles(), 'selected' => $user->roles()->pluck('roles.id')->all()]);
    }

    public function update(Request $request, User $user)
    {
        $this->authorize('users.manage');
        $this->guardTarget($user);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['nullable', 'confirmed', Password::defaults()],
            'roles' => ['array'],
            'roles.*' => ['integer', 'exists:roles,id'],
        ]);
        $newRoleIds = $this->validatedRoleIds($request, $data['roles'] ?? [], $user);
        $oldRoleIds = $user->roles()->pluck('roles.id')->sort()->values()->all();

        $this->ensureSuperAdminRetained($user, $newRoleIds);

        $original = $user->getOriginal();
        $user->fill(collect($data)->only(['name', 'email', 'phone'])->all());
        if (! empty($data['password'])) {
            $user->forceFill(['password' => $data['password'], 'must_change_password' => true, 'password_changed_at' => now()]);
        }
        $user->save();
        [$before, $after] = $this->audit->diff($user, $original);
        $user->roles()->sync($newRoleIds);
        sort($newRoleIds);
        if ($oldRoleIds !== array_map('intval', $newRoleIds)) {
            $before['roles'] = $oldRoleIds;
            $after['roles'] = array_map('intval', $newRoleIds);
        }
        $this->audit->log('user.updated', $user, $before, $after);

        return redirect()->route('users.index')->with('success', 'User updated.');
    }

    public function toggleActive(Request $request, User $user)
    {
        $this->authorize('users.manage');
        $this->guardTarget($user);
        if ($user->is($request->user())) {
            throw ValidationException::withMessages(['user' => 'You cannot deactivate your own account.']);
        }
        if ($user->is_active) {
            $this->ensureSuperAdminRetained($user, []);
        }
        $user->forceFill(['is_active' => ! $user->is_active])->save();
        $this->audit->log($user->is_active ? 'user.activated' : 'user.deactivated', $user, ['is_active' => ! $user->is_active], ['is_active' => $user->is_active], $request->input('reason'));

        return back()->with('success', 'User '.($user->is_active ? 'activated' : 'deactivated').'.');
    }

    /** @return Collection<int, Role> */
    private function assignableRoles()
    {
        $actor = auth()->user();

        return Role::with('permissions')->orderBy('name')->get()->filter(fn (Role $r) => $actor->canGrantRole($r))->values();
    }

    /** Roles a user already holds are preserved even if the actor could not grant them. */
    private function validatedRoleIds(Request $request, array $ids, ?User $target = null): array
    {
        $actor = $request->user();
        $existing = $target ? $target->roles()->pluck('roles.id')->all() : [];
        $ids = array_map('intval', $ids);
        $roles = Role::with('permissions')->whereIn('id', $ids)->get();
        foreach ($roles as $role) {
            if (! in_array($role->id, $existing, true) && ! $actor->canGrantRole($role)) {
                throw ValidationException::withMessages(['roles' => "You cannot assign the role '{$role->name}' because it grants permissions you do not hold."]);
            }
        }
        // Non-grantable roles that the target already has cannot be silently stripped either.
        $locked = Role::with('permissions')->whereIn('id', $existing)->get()->filter(fn ($r) => ! $actor->canGrantRole($r))->pluck('id')->all();

        return array_values(array_unique(array_merge($ids, $locked)));
    }

    /** Non-super-admins may not modify super administrators. */
    private function guardTarget(User $target): void
    {
        abort_if($target->isSuperAdmin() && ! auth()->user()->isSuperAdmin(), 403);
    }

    private function ensureSuperAdminRetained(User $user, array $newRoleIds): void
    {
        if (! $user->isSuperAdmin()) {
            return;
        }
        $superId = Role::where('slug', Role::SUPER_ADMIN)->value('id');
        $keeps = in_array($superId, array_map('intval', $newRoleIds), true);
        $others = User::where('is_active', true)->where('id', '!=', $user->id)->whereHas('roles', fn ($q) => $q->where('slug', Role::SUPER_ADMIN))->exists();
        if (! $keeps && ! $others) {
            throw ValidationException::withMessages(['roles' => 'The last active Super Administrator cannot be removed or deactivated.']);
        }
    }
}
