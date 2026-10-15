<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;

class RestoreDatabase extends Command
{
    protected $signature = 'salon:restore {file : Path of a salon-*.sql.gz or salon-*.sqlite.gz backup} {--force : Do not ask for confirmation}';

    protected $description = 'Replace the current database with a backup (DESTRUCTIVE; take the site offline first)';

    public function handle(BackupService $backups): int
    {
        $this->warn('This REPLACES every table in the current database ('.config('database.default').') with the contents of the backup.');
        if (! $this->option('force') && ! $this->confirm('Continue?', false)) {
            $this->line('Cancelled; nothing was changed.');

            return self::FAILURE;
        }
        try {
            $backups->restore($this->argument('file'));
        } catch (\Throwable $e) {
            $this->error('Restore failed: '.$e->getMessage());

            return self::FAILURE;
        }
        $this->info('Restore complete. Run "php artisan migrate" if the backup is older than the code, then "php artisan salon:integrity-check", then bring the site back with "php artisan up".');

        return self::SUCCESS;
    }
}
