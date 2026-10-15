<?php

namespace Tests\Feature;

use App\Services\BackupService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;

/**
 * Real backup, restore and restore-test runs. The SQLite tests use a temporary database file; the MySQL test (group "mysql",
 * skipped unless the default connection is MySQL/MariaDB and mysqldump is installed) uses the configured test database.
 */
class BackupRestoreTest extends TestCase
{
    private string $dir;

    private string $database;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/salon-backup-test-'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0700, true);
        config(['salon.backup.directory' => $this->dir.'/backups']);
        $this->database = $this->dir.'/live.sqlite';
        touch($this->database);
        config(['database.connections.backup_sqlite' => ['driver' => 'sqlite', 'database' => $this->database, 'prefix' => '', 'foreign_key_constraints' => true]]);
        Artisan::call('migrate', ['--database' => 'backup_sqlite', '--force' => true]);
    }

    protected function tearDown(): void
    {
        DB::purge('backup_sqlite');
        foreach (glob($this->dir.'/{,backups/}*', GLOB_BRACE) ?: [] as $file) {
            is_file($file) && @unlink($file);
        }
        @rmdir($this->dir.'/backups');
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function live()
    {
        return DB::connection('backup_sqlite');
    }

    private function categories(): array
    {
        return $this->live()->table('expense_categories')->orderBy('name')->pluck('name')->all();
    }

    public function test_a_sqlite_backup_is_compressed_checksummed_private_and_restorable(): void
    {
        $this->live()->table('expense_categories')->insert(['name' => 'Before backup', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $result = app(BackupService::class)->backup('backup_sqlite');

        $this->assertFileExists($result['path']);
        $this->assertSame(0600, fileperms($result['path']) & 0777, 'backups are readable by the owner only');
        $this->assertSame(hash_file('sha256', $result['path']), $result['sha256']);
        $this->assertFileExists($result['path'].'.sha256');
        $this->assertStringStartsWith('SQLite format 3', (string) gzfile($result['path'])[0]);

        $this->live()->table('expense_categories')->where('name', 'Before backup')->delete();
        $this->live()->table('expense_categories')->insert(['name' => 'After backup', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        app(BackupService::class)->restore($result['path'], 'backup_sqlite');

        $this->assertContains('Before backup', $this->categories());
        $this->assertNotContains('After backup', $this->categories(), 'the restore returned the database to the backed-up state');
    }

    public function test_verification_restores_into_a_scratch_copy_compares_rows_and_runs_the_integrity_check(): void
    {
        $result = app(BackupService::class)->backup('backup_sqlite');
        $this->live()->table('expense_categories')->insert(['name' => 'Added later', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        $verification = app(BackupService::class)->verify($result['path'], 'backup_sqlite');

        $this->assertSame([], $verification['integrity']);
        $this->assertSame(8, $verification['tables']['expense_categories']['backup']);
        $this->assertSame(9, $verification['tables']['expense_categories']['live'], 'the live database moved on after the backup');
        $this->assertArrayHasKey('invoices', $verification['tables']);
        $this->assertFileDoesNotExist($verification['scratch'], 'the scratch copy is removed');
        $this->assertSame(config('database.default'), DB::getDefaultConnection(), 'the default connection is restored');
    }

    public function test_uploaded_receipts_are_archived_with_the_backup_when_requested(): void
    {
        $dir = storage_path('app/private/expense-receipts');
        $receipt = $dir.'/backup-test-'.bin2hex(random_bytes(3)).'.pdf';
        @mkdir($dir, 0775, true);
        file_put_contents($receipt, '%PDF-1.4 receipt');

        try {
            $result = app(BackupService::class)->backup('backup_sqlite', true);
            $this->assertNotNull($result['files']);
            $zip = new \ZipArchive;
            $zip->open($result['files']);
            $this->assertNotFalse($zip->locateName('private/expense-receipts/'.basename($receipt)));
            $this->assertSame('%PDF-1.4 receipt', $zip->getFromName('private/expense-receipts/'.basename($receipt)));
            $zip->close();
        } finally {
            @unlink($receipt);
        }
    }

    public function test_a_damaged_or_tampered_backup_is_refused(): void
    {
        $result = app(BackupService::class)->backup('backup_sqlite');
        file_put_contents($result['path'], 'garbage', FILE_APPEND);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not match its checksum');
        app(BackupService::class)->restore($result['path'], 'backup_sqlite');
    }

    public function test_only_the_newest_backups_are_kept_and_a_missing_file_is_reported(): void
    {
        $service = app(BackupService::class);
        mkdir(config('salon.backup.directory'), 0700, true);
        foreach (['20250101-000000', '20250102-000000', '20250103-000000'] as $stamp) {
            file_put_contents(config('salon.backup.directory')."/salon-sqlite-{$stamp}.sqlite.gz", 'old');
        }

        $result = $service->backup('backup_sqlite', false, 2);

        $this->assertSame(2, $result['pruned']);
        $this->assertCount(2, glob(config('salon.backup.directory').'/salon-*.sqlite.gz'));
        $this->assertFileExists($result['path'], 'the newest backup always survives');
        $this->expectException(RuntimeException::class);
        $service->restore('/nonexistent/backup.sql.gz', 'backup_sqlite');
    }

    public function test_the_commands_report_success_and_failure_with_exit_codes(): void
    {
        config(['database.default' => 'backup_sqlite']);

        $this->artisan('salon:backup --keep=3 --verify')->expectsOutputToContain('Backup written')->expectsOutputToContain('Backup verified')->assertSuccessful();
        $this->artisan('salon:verify-backup /nonexistent.sql.gz')->expectsOutputToContain('Verification failed')->assertFailed();
        $this->artisan('salon:restore /nonexistent.sql.gz --force')->expectsOutputToContain('Restore failed')->assertFailed();
        $this->artisan('salon:restore '.(glob(config('salon.backup.directory').'/salon-*.gz')[0]))->expectsConfirmation('Continue?', 'no')->expectsOutputToContain('nothing was changed')->assertFailed();
    }

    #[Group('mysql')]
    public function test_a_real_mysql_dump_restores_and_verifies_into_a_scratch_database(): void
    {
        $default = config('database.default');
        if (! in_array(config("database.connections.{$default}.driver"), ['mysql', 'mariadb'], true) || trim((string) shell_exec('command -v '.escapeshellarg(config('salon.backup.mysqldump')).' 2>/dev/null')) === '') {
            $this->markTestSkipped('Needs a MySQL/MariaDB default connection and mysqldump.');
        }
        $name = 'Backup probe '.bin2hex(random_bytes(3));
        $other = 'Changed after '.bin2hex(random_bytes(3));
        $service = app(BackupService::class);
        DB::table('expense_categories')->insert(['name' => $name, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        try {
            $result = $service->backup();
            $this->assertStringContainsString('Dump completed', implode('', array_slice(gzfile($result['path']), -3)));

            DB::table('expense_categories')->where('name', $name)->delete();
            DB::table('expense_categories')->insert(['name' => $other, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            try {
                $verification = $service->verify($result['path']);
                $this->assertSame([], $verification['integrity']);
                $this->assertGreaterThan(30, count($verification['tables']));
            } catch (\Throwable $e) {
                if (! str_contains($e->getMessage(), 'denied')) {
                    throw $e;
                }
                // The test database user cannot CREATE DATABASE; the scratch verification is covered by the SQLite tests and the root-user run.
            }

            $service->restore($result['path']);
            $this->assertTrue(DB::table('expense_categories')->where('name', $name)->exists());
            $this->assertFalse(DB::table('expense_categories')->where('name', $other)->exists());
        } finally {
            DB::table('expense_categories')->whereIn('name', [$name, $other])->delete();
        }
    }
}
