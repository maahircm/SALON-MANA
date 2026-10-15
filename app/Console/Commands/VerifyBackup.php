<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;

class VerifyBackup extends Command
{
    protected $signature = 'salon:verify-backup {file : Backup to test} {--scratch= : Scratch database name (MySQL; default <database>_verify_xxxxxx)} {--keep-scratch : Do not drop the scratch database afterwards}';

    protected $description = 'Prove a backup is restorable: load it into a scratch database, compare row counts and run the integrity check on it';

    public function handle(BackupService $backups): int
    {
        try {
            $result = $backups->verify($this->argument('file'), null, $this->option('scratch') ?: null, ! $this->option('keep-scratch'));
        } catch (\Throwable $e) {
            $this->error('Verification failed: '.$e->getMessage());

            return self::FAILURE;
        }
        $rows = [];
        $changed = 0;
        foreach ($result['tables'] as $table => $n) {
            $differs = $n['live'] !== null && $n['live'] !== $n['backup'];
            $changed += (int) $differs;
            $rows[] = [$table, $n['backup'], $n['live'] ?? 'n/a', $differs ? 'differs' : 'same'];
        }
        $this->table(['Table', 'Rows in backup', 'Rows now', ''], $rows);
        $this->line(count($rows).' tables restored into scratch database '.$result['scratch'].'.');
        $changed && $this->comment("{$changed} table(s) differ from the live database: expected when data changed after the backup was taken.");
        if ($result['integrity'] !== []) {
            $this->error('The restored copy FAILED the integrity check:');
            foreach ($result['integrity'] as $finding) {
                $this->line("  - {$finding['title']}: {$finding['count']}");
            }

            return self::FAILURE;
        }
        $this->info('Backup verified: it restores completely and passes the integrity check.');

        return self::SUCCESS;
    }
}
