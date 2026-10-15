<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;

class BackupDatabase extends Command
{
    protected $signature = 'salon:backup {--files : Also archive uploaded receipts and the logo} {--keep= : Number of database backups to keep (default BACKUP_KEEP / 14)} {--verify : Restore the new backup into a scratch database and check it}';

    protected $description = 'Create a compressed, checksummed database backup (storage/app/backups) and prune old ones';

    public function handle(BackupService $backups): int
    {
        try {
            $result = $backups->backup(null, (bool) $this->option('files'), $this->option('keep') !== null ? (int) $this->option('keep') : null);
        } catch (\Throwable $e) {
            $this->error('Backup failed: '.$e->getMessage());

            return self::FAILURE;
        }
        $this->info("Backup written: {$result['path']} (".number_format($result['size'] / 1024, 1).' KB)');
        $this->line("SHA-256: {$result['sha256']}");
        $result['files'] && $this->info("Uploaded files archived: {$result['files']}");
        $result['pruned'] && $this->line("Old backups removed: {$result['pruned']}");
        $this->comment('Copy backups off this server (another disk or cloud storage); a backup on the same disk does not survive a disk failure.');

        return $this->option('verify') ? $this->call('salon:verify-backup', ['file' => $result['path']]) : self::SUCCESS;
    }
}
