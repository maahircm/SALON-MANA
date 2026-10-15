<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Idempotent: syncs the permission catalogue and creates predefined roles.
 * Existing role->permission assignments are never overwritten once a role exists
 * (administrators may have customised them); only missing roles are populated.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $all = [];
        foreach (config('rbac.catalog') as $group => $perms) {
            foreach ($perms as $key => $label) {
                Permission::updateOrCreate(['key' => $key], ['label' => $label, 'group' => $group]);
                $all[] = $key;
            }
        }

        foreach (config('rbac.roles') as $slug => $def) {
            $role = Role::firstOrNew(['slug' => $slug]);
            $isNew = ! $role->exists;
            $role->name = $def['name'];
            $role->forceFill(['is_system' => true])->save();
            if ($isNew) {
                $role->permissions()->sync(Permission::whereIn('key', $this->expand($def['permissions'], $all))->pluck('id'));
            }
        }
    }

    private function expand(array $patterns, array $all): array
    {
        $keys = [];
        foreach ($patterns as $p) {
            foreach ($all as $key) {
                if ($p === $key || (Str::endsWith($p, '.*') && Str::startsWith($key, Str::beforeLast($p, '*')))) {
                    $keys[] = $key;
                }
            }
        }

        return array_values(array_unique($keys));
    }
}
