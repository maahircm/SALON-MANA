<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

// is_active / must_change_password / 2FA columns are deliberately NOT fillable (mass-assignment protection).
#[Fillable(['name', 'email', 'phone', 'password', 'locale'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** @var array<string, bool>|null */
    private ?array $permissionCache = null;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password_changed_at' => 'datetime',
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    public function loginActivities(): HasMany
    {
        return $this->hasMany(LoginActivity::class);
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null && $this->two_factor_secret !== null;
    }

    public function isSuperAdmin(): bool
    {
        return $this->roles->contains(fn (Role $r) => $r->slug === Role::SUPER_ADMIN);
    }

    /** All permission keys granted through roles (cached per instance). */
    public function permissionKeys(): array
    {
        if ($this->permissionCache === null) {
            $this->permissionCache = $this->roles()->with('permissions:id,key')->get()
                ->flatMap(fn (Role $r) => $r->permissions->pluck('key'))
                ->flip()->map(fn () => true)->all();
        }

        return array_keys($this->permissionCache);
    }

    public function hasPermission(string $key): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }
        $this->permissionKeys();

        return isset($this->permissionCache[$key]);
    }

    public function flushPermissionCache(): void
    {
        $this->permissionCache = null;
        $this->unsetRelation('roles');
    }

    /** A role may only be granted by a user who already holds every permission in it. */
    public function canGrantRole(Role $role): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }
        if ($role->isSuperAdmin()) {
            return false;
        }

        return $role->permissions->every(fn (Permission $p) => $this->hasPermission($p->key));
    }
}
