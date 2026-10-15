<?php

namespace App\Console\Commands;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Command;

class SyncPermissions extends Command
{
    protected $signature = 'salon:sync-permissions';

    protected $description = 'Sync the permission catalogue from config/rbac.php (run after upgrades)';

    public function handle(): int
    {
        $this->call('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true]);

        return self::SUCCESS;
    }
}
