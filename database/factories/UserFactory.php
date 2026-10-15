<?php

namespace Database\Factories;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'is_active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    /** Attach a role (creating it when absent) with the given permission keys. */
    public function withPermissions(string ...$keys): static
    {
        return $this->afterCreating(function (User $user) use ($keys) {
            $role = Role::firstOrCreate(['slug' => 'test-'.md5(implode(',', $keys))], ['name' => 'Test '.md5(implode(',', $keys))]);
            $ids = collect($keys)->map(fn ($k) => Permission::firstOrCreate(['key' => $k], ['label' => $k, 'group' => 'test'])->id);
            $role->permissions()->syncWithoutDetaching($ids->all());
            $user->roles()->syncWithoutDetaching([$role->id]);
            $user->flushPermissionCache();
        });
    }

    public function superAdmin(): static
    {
        return $this->afterCreating(function (User $user) {
            $role = Role::firstOrCreate(['slug' => Role::SUPER_ADMIN], ['name' => 'Super Administrator']);
            $user->roles()->syncWithoutDetaching([$role->id]);
            $user->flushPermissionCache();
        });
    }
}
