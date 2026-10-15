<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Seeds reference data only (roles/permissions). It never creates users or passwords:
 * create the first administrator with `php artisan salon:create-admin`.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);
    }
}
