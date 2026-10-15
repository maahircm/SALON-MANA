<?php

namespace App\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Symfony\Component\Process\Process;
use ZipArchive;

/**
 * Database backups (compressed SQL dump for MySQL/MariaDB, consistent snapshot for SQLite), optional archive of uploaded
 * files, restore, and a restore test into a scratch database. Credentials are handed to the MySQL tools through a
 * temporary 0600 option file, never on the command line or in the process list.
 */
class BackupService
{
    /**
     * @return array{path: string, size: int, sha256: string, files: ?string, pruned: int}
     */
    public function backup(?string $connection = null, bool $withFiles = false, ?int $keep = null): array
    {
        [$name, $config] = $this->connection($connection);
        $dir = $this->directory();
        $stamp = now()->format('Ymd-His');

        if ($this->isMysql($config)) {
            $path = "{$dir}/salon-{$config['database']}-{$stamp}.sql.gz";
            $this->dumpMysql($config, $path);
        } elseif ($config['driver'] === 'sqlite') {
            $path = "{$dir}/salon-sqlite-{$stamp}.sqlite.gz";
            $this->snapshotSqlite($name, $config, $path);
        } else {
            throw new RuntimeException("Backups are not supported for the [{$config['driver']}] database driver.");
        }
        @chmod($path, 0600);
        $this->assertReadable($path, $config['driver']);
        $sha = hash_file('sha256', $path);
        file_put_contents($path.'.sha256', $sha.'  '.basename($path).PHP_EOL);

        return ['path' => $path, 'size' => (int) filesize($path), 'sha256' => $sha, 'files' => $withFiles ? $this->archiveFiles($dir, $stamp) : null, 'pruned' => $this->prune($dir, $keep ?? (int) config('salon.backup.keep', 14))];
    }

    public function restore(string $file, ?string $connection = null): void
    {
        [$name, $config] = $this->connection($connection);
        $this->verifyChecksum($file);

        if ($this->isMysql($config)) {
            $this->loadMysqlDump($config, $file);
        } elseif ($config['driver'] === 'sqlite') {
            $target = (string) $config['database'];
            if ($target === ':memory:' || $target === '') {
                throw new RuntimeException('An in-memory SQLite database cannot be restored.');
            }
            DB::purge($name);
            $this->gunzipTo($file, $target);
            DB::reconnect($name);
        } else {
            throw new RuntimeException("Restore is not supported for the [{$config['driver']}] database driver.");
        }
    }

    /**
     * Restore a backup into a scratch database and report whether it is complete and internally consistent.
     *
     * @return array{tables: array<string, array{backup: int, live: int|null}>, integrity: array<string, mixed>, scratch: string}
     */
    public function verify(string $file, ?string $connection = null, ?string $scratch = null, bool $dropScratch = true): array
    {
        [$name, $config] = $this->connection($connection);
        $this->verifyChecksum($file);
        $scratchConfig = $config;
        $provided = $scratch !== null;
        if ($this->isMysql($config)) {
            $scratch ??= $config['database'].'_verify_'.bin2hex(random_bytes(3));
            if ($scratch === $config['database']) {
                throw new RuntimeException('The scratch database must be different from the live database.');
            }
            $quoted = '`'.str_replace('`', '``', $scratch).'`';
            if ($provided) {
                // A database the administrator created for this purpose: it must exist and be empty; it is emptied again afterwards.
                try {
                    $existing = DB::connection($name)->select('SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = ?', [$scratch]);
                } catch (\Throwable) {
                    $existing = [];
                }
                if (! DB::connection($name)->select('SELECT 1 FROM information_schema.schemata WHERE schema_name = ?', [$scratch])) {
                    throw new RuntimeException("The scratch database {$scratch} does not exist (or the database user cannot see it). Create it empty and grant this user all privileges on it.");
                }
                if (($existing[0]->n ?? 1) > 0) {
                    throw new RuntimeException("The scratch database {$scratch} is not empty; refusing to overwrite it.");
                }
            } else {
                try {
                    DB::connection($name)->statement("CREATE DATABASE IF NOT EXISTS {$quoted} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                } catch (QueryException $e) {
                    throw new RuntimeException("The database user may not create a scratch database. Create an empty database yourself, grant this user all privileges on it, and run again with --scratch=<name>. ({$e->errorInfo[2]})");
                }
            }
            $scratchConfig['database'] = $scratch;
            $this->loadMysqlDump($scratchConfig, $file);
        } elseif ($config['driver'] === 'sqlite') {
            $scratch = sys_get_temp_dir().'/salon-verify-'.bin2hex(random_bytes(4)).'.sqlite';
            $scratchConfig['database'] = $scratch;
            $this->gunzipTo($file, $scratch);
        } else {
            throw new RuntimeException("Verification is not supported for the [{$config['driver']}] database driver.");
        }

        config(['database.connections.salon_verify' => $scratchConfig]);
        DB::purge('salon_verify');
        $default = config('database.default');
        try {
            $tables = [];
            foreach (array_column(Schema::connection('salon_verify')->getTables(), 'name') as $table) {
                $tables[$table] = ['backup' => DB::connection('salon_verify')->table($table)->count(), 'live' => Schema::connection($name)->hasTable($table) ? DB::connection($name)->table($table)->count() : null];
            }
            DB::setDefaultConnection('salon_verify');
            app(SettingsService::class)->flush();
            $integrity = app(IntegrityChecker::class)->run();
        } finally {
            DB::setDefaultConnection($default);
            app(SettingsService::class)->flush();
            DB::purge('salon_verify');
            if ($dropScratch) {
                $this->dropScratch($name, $config, $scratch, $provided);
            }
        }

        return ['tables' => $tables, 'integrity' => $integrity, 'scratch' => $scratch];
    }

    private function dropScratch(string $name, array $config, string $scratch, bool $provided): void
    {
        if ($this->isMysql($config) && $provided) {
            // Empty the administrator's database but leave the database itself.
            $scratchConfig = ['database' => $scratch] + $config;
            config(['database.connections.salon_verify' => $scratchConfig]);
            DB::purge('salon_verify');
            $connection = DB::connection('salon_verify');
            $connection->statement('SET FOREIGN_KEY_CHECKS=0');
            foreach (array_column(Schema::connection('salon_verify')->getTables(), 'name') as $table) {
                $connection->statement('DROP TABLE IF EXISTS `'.str_replace('`', '``', $table).'`');
            }
            $connection->statement('SET FOREIGN_KEY_CHECKS=1');
            DB::purge('salon_verify');
        } elseif ($this->isMysql($config)) {
            DB::connection($name)->statement('DROP DATABASE IF EXISTS `'.str_replace('`', '``', $scratch).'`');
        } else {
            @unlink($scratch);
        }
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private function connection(?string $connection): array
    {
        $name = $connection ?? config('database.default');
        $config = config("database.connections.{$name}");
        if (! is_array($config)) {
            throw new RuntimeException("Unknown database connection [{$name}].");
        }

        return [$name, $config];
    }

    private function isMysql(array $config): bool
    {
        return in_array($config['driver'], ['mysql', 'mariadb'], true);
    }

    private function directory(): string
    {
        $dir = (string) config('salon.backup.directory');
        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException("Cannot create the backup directory {$dir}.");
        }

        return rtrim($dir, '/\\');
    }

    private function dumpMysql(array $config, string $path): void
    {
        $gz = gzopen($path, 'wb9');
        if ($gz === false) {
            throw new RuntimeException("Cannot write {$path}.");
        }
        $errors = '';
        $options = $this->optionFile($config);
        try {
            $process = new Process([config('salon.backup.mysqldump'), "--defaults-extra-file={$options}", '--single-transaction', '--quick', '--routines', '--triggers', '--no-tablespaces', '--default-character-set=utf8mb4', $config['database']]);
            $process->setTimeout(null);
            $process->run(function (string $type, string $buffer) use ($gz, &$errors): void {
                $type === Process::OUT ? gzwrite($gz, $buffer) : $errors .= $buffer;
            });
        } finally {
            gzclose($gz);
            @unlink($options);
        }
        if (! $process->isSuccessful()) {
            @unlink($path);
            throw new RuntimeException('mysqldump failed: '.trim($errors ?: 'exit code '.$process->getExitCode()).' (set MYSQLDUMP_PATH in .env if mysqldump is not on the PATH)');
        }
    }

    private function loadMysqlDump(array $config, string $file): void
    {
        $gz = gzopen($file, 'rb');
        if ($gz === false) {
            throw new RuntimeException("Cannot read {$file}.");
        }
        $options = $this->optionFile($config);
        try {
            $process = new Process([config('salon.backup.mysql'), "--defaults-extra-file={$options}", '--default-character-set=utf8mb4', $config['database']]);
            $process->setTimeout(null);
            $process->setInput((function () use ($gz) {
                while (! gzeof($gz)) {
                    yield gzread($gz, 1 << 16);
                }
            })());
            $process->run();
        } finally {
            gzclose($gz);
            @unlink($options);
        }
        if (! $process->isSuccessful()) {
            throw new RuntimeException('mysql restore failed: '.trim($process->getErrorOutput() ?: 'exit code '.$process->getExitCode()).' (set MYSQL_CLIENT_PATH in .env if mysql is not on the PATH)');
        }
    }

    private function snapshotSqlite(string $name, array $config, string $path): void
    {
        $source = (string) $config['database'];
        if ($source === ':memory:' || $source === '') {
            throw new RuntimeException('An in-memory SQLite database cannot be backed up.');
        }
        $tmp = sys_get_temp_dir().'/salon-snapshot-'.bin2hex(random_bytes(4)).'.sqlite';
        try {
            DB::connection($name)->statement("VACUUM INTO '".str_replace("'", "''", $tmp)."'");
            $in = fopen($tmp, 'rb');
            $gz = gzopen($path, 'wb9');
            while (! feof($in)) {
                gzwrite($gz, (string) fread($in, 1 << 16));
            }
            fclose($in);
            gzclose($gz);
        } finally {
            @unlink($tmp);
        }
    }

    private function gunzipTo(string $file, string $target): void
    {
        $in = gzopen($file, 'rb');
        $out = fopen($target, 'wb');
        if ($in === false || $out === false) {
            throw new RuntimeException("Cannot restore {$file} to {$target}.");
        }
        while (! gzeof($in)) {
            fwrite($out, (string) gzread($in, 1 << 16));
        }
        gzclose($in);
        fclose($out);
    }

    /** A backup that cannot be read back, or that was cut short, is worse than none: fail loudly now. */
    private function assertReadable(string $path, string $driver): void
    {
        $gz = gzopen($path, 'rb');
        $head = '';
        $tail = '';
        $bytes = 0;
        while ($gz !== false && ! gzeof($gz)) {
            $chunk = (string) gzread($gz, 1 << 16);
            $head = $bytes === 0 ? substr($chunk, 0, 32) : $head;
            $tail = substr($tail.$chunk, -2048);
            $bytes += strlen($chunk);
        }
        $gz && gzclose($gz);
        $valid = $driver === 'sqlite' ? str_starts_with($head, 'SQLite format 3') : str_contains($tail, 'Dump completed');
        if ($bytes === 0 || ! $valid) {
            @unlink($path);
            throw new RuntimeException('The backup file failed its integrity check and was removed.');
        }
    }

    private function verifyChecksum(string $file): void
    {
        if (! is_file($file)) {
            throw new RuntimeException("Backup file not found: {$file}");
        }
        if (is_file($file.'.sha256')) {
            $expected = strtok((string) file_get_contents($file.'.sha256'), ' ');
            if (! hash_equals((string) $expected, hash_file('sha256', $file))) {
                throw new RuntimeException('The backup file does not match its checksum; it is damaged or was modified.');
            }
        }
    }

    private function archiveFiles(string $dir, string $stamp): ?string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('The PHP zip extension is required for --files.');
        }
        $path = "{$dir}/salon-files-{$stamp}.zip";
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $added = 0;
        foreach (['private/expense-receipts' => storage_path('app/private/expense-receipts'), 'public/logos' => storage_path('app/public/logos')] as $label => $source) {
            if (! is_dir($source)) {
                continue;
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS)) as $file) {
                $zip->addFile((string) $file, $label.'/'.substr((string) $file, strlen($source) + 1));
                $added++;
            }
        }
        $zip->close();
        if ($added === 0) {
            @unlink($path);

            return null;
        }
        @chmod($path, 0600);

        return $path;
    }

    /** Keep the newest N database backups (with their checksum files). */
    private function prune(string $dir, int $keep): int
    {
        $backups = glob($dir.'/salon-*.{sql.gz,sqlite.gz}', GLOB_BRACE) ?: [];
        rsort($backups);
        $removed = 0;
        foreach (array_slice($backups, max(1, $keep)) as $old) {
            @unlink($old);
            @unlink($old.'.sha256');
            $removed++;
        }

        return $removed;
    }

    /** @param array<string, mixed> $config */
    private function optionFile(array $config): string
    {
        $esc = fn ($v) => '"'.addcslashes((string) $v, '\\"').'"';
        $lines = ['[client]', 'user='.$esc($config['username'] ?? ''), 'password='.$esc($config['password'] ?? '')];
        if (! empty($config['unix_socket'])) {
            $lines[] = 'socket='.$esc($config['unix_socket']);
        } else {
            $lines[] = 'host='.$esc($config['host'] ?? '127.0.0.1');
            $lines[] = 'port='.(int) ($config['port'] ?? 3306);
        }
        $file = tempnam(sys_get_temp_dir(), 'salon-my-');
        chmod($file, 0600);
        file_put_contents($file, implode(PHP_EOL, $lines).PHP_EOL);

        return $file;
    }
}
