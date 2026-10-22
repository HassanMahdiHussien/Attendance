<?php

namespace App\Console\Commands;

use App\Mail\OperationsMessage;
use App\Support\HealthPing;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use PDO;
use RuntimeException;
use Spatie\Backup\BackupDestination\BackupDestination;
use Symfony\Component\Process\Process;
use Throwable;
use ZipArchive;

class BackupRestoreDrill extends Command
{
    protected $signature = 'backup:restore-drill';

    protected $description = 'Restore and verify the newest backup in an isolated scratch database';

    private const VOLATILE = ['sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs'];

    private const APPEND_ONLY = ['audit_logs', 'ai_generation_logs'];

    public function handle(HealthPing $ping): int
    {
        $temporary = null;
        $scratch = null;
        $created = false;
        $failure = null;

        try {
            $liveName = (string) config('database.connections.'.config('database.default').'.database');
            $scratchName = (string) config('displanery.restore_drill.database');
            if (! preg_match('/^[a-z0-9_]+_restore_drill$/D', $scratchName) || $scratchName === $liveName
                || $scratchName !== config('database.connections.restore_drill.database')) {
                throw new RuntimeException('Invalid restore drill database name.');
            }
            if (! app()->environment(['local', 'testing']) && mb_strlen((string) config('backup.backup.password')) < 32) {
                throw new RuntimeException('A strong backup archive password is required.');
            }

            $diskName = config('backup.backup.destination.disks.0');
            if (! is_string($diskName) || $diskName === '') {
                throw new RuntimeException('No backup destination configured.');
            }
            if ($diskName === 'backup-sftp' && ! config('filesystems.disks.backup-sftp.hostFingerprint')) {
                throw new RuntimeException('A pinned backup SFTP host fingerprint is required.');
            }
            $destination = BackupDestination::create($diskName, (string) config('backup.backup.name'));
            $backup = $destination->newestBackup();
            if ($backup === null) {
                throw new RuntimeException('No backup archive found.');
            }

            $temporary = storage_path('app/restore-drill-'.bin2hex(random_bytes(8)));
            if (! mkdir($temporary, 0700, true) || ! chmod($temporary, 0700)) {
                throw new RuntimeException('Unable to create private drill directory.');
            }
            $archivePath = $temporary.'/backup.zip';
            $source = $backup->stream();
            $target = fopen($archivePath, 'wb');
            if ($target === false) {
                fclose($source);
                throw new RuntimeException('Unable to open drill archive.');
            }
            try {
                if (stream_copy_to_stream($source, $target) === false) {
                    throw new RuntimeException('Backup archive download failed.');
                }
            } finally {
                fclose($source);
                fclose($target);
            }
            if (! chmod($archivePath, 0600)) {
                throw new RuntimeException('Unable to secure drill archive.');
            }

            $zip = new ZipArchive;
            if ($zip->open($archivePath, ZipArchive::RDONLY) !== true) {
                throw new RuntimeException('Backup archive cannot be opened.');
            }
            try {
                $password = (string) config('backup.backup.password');
                $dumpIndex = null;
                for ($index = 0; $index < $zip->numFiles; $index++) {
                    $entry = $zip->getNameIndex($index);
                    if (is_string($entry) && preg_match('~^db-dumps/mysql-[^/]+\.sql(?:\.gz)?$~', $entry)) {
                        $dumpIndex = $index;
                        break;
                    }
                }
                if ($dumpIndex === null) {
                    throw new RuntimeException('Database dump missing from archive.');
                }
                $dumpName = $zip->getNameIndex($dumpIndex);
                if ($password !== '') {
                    if ($zip->getFromName($dumpName, 1) !== false) {
                        throw new RuntimeException('Database dump is not encrypted.');
                    }
                    $zip->setPassword($password);
                    if ($zip->getFromName($dumpName, 1) === false) {
                        throw new RuntimeException('Wrong archive password or damaged dump.');
                    }
                }

                $scratch = $this->serverConnection();
                $this->preflightGrants($scratch, $liveName);
                $exists = $scratch->query('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '.$scratch->quote($scratchName))->fetchColumn();
                if ($exists) {
                    $marker = $scratch->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '.$scratch->quote($scratchName)." AND TABLE_NAME = '_restore_drill_marker'")->fetchColumn();
                    if (! $marker) {
                        throw new RuntimeException('Scratch database exists without its restore drill marker.');
                    }
                    $scratch->exec("DROP DATABASE `{$scratchName}`");
                }
                $scratch->exec("CREATE DATABASE `{$scratchName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $created = true;
                $scratch->exec("CREATE TABLE `{$scratchName}`.`_restore_drill_marker` (id TINYINT PRIMARY KEY)");

                $defaultsFile = $temporary.'/mysql.cnf';
                $connection = config('database.connections.restore_drill');
                $option = fn ($value) => '"'.str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '', ''], (string) $value).'"';
                if (file_put_contents($defaultsFile, "[client]\nuser=".$option($connection['username'])."\npassword=".$option($connection['password'])."\nhost=".$option($connection['host'])."\nport=".(int) $connection['port']."\n") === false
                    || ! chmod($defaultsFile, 0600)) {
                    throw new RuntimeException('Unable to secure MySQL drill credentials.');
                }
                $stream = $zip->getStream($dumpName);
                if ($stream === false) {
                    throw new RuntimeException('Cannot decrypt database dump.');
                }
                try {
                    if (str_ends_with($dumpName, '.gz') && ! stream_filter_append($stream, 'zlib.inflate', STREAM_FILTER_READ, ['window' => 31])) {
                        throw new RuntimeException('Cannot decompress database dump.');
                    }
                    $process = new Process(['mysql', '--defaults-extra-file='.$defaultsFile, '--binary-mode', $scratchName]);
                    $process->setTimeout(3600);
                    $process->setInput($this->filteredDump($stream));
                    $process->run();
                    if (! $process->isSuccessful()) {
                        throw new RuntimeException('MySQL import failed.');
                    }
                } finally {
                    fclose($stream);
                    unlink($defaultsFile);
                }

                DB::purge('restore_drill');
                $rows = $this->verifyDatabase($scratchName);
                $this->table(['Table', 'Restored', 'Live'], $rows);
            } finally {
                $zip->close();
            }
        } catch (Throwable $error) {
            $failure = $error;
        } finally {
            DB::disconnect('restore_drill');
            if ($created && $scratch instanceof PDO) {
                try {
                    $scratch->exec("DROP DATABASE `{$scratchName}`");
                } catch (Throwable $cleanupError) {
                    $failure ??= $cleanupError;
                }
            }
            if ($temporary !== null && is_dir($temporary)) {
                try {
                    foreach (glob($temporary.'/*') ?: [] as $file) {
                        unlink($file);
                    }
                    rmdir($temporary);
                } catch (Throwable $cleanupError) {
                    $failure ??= $cleanupError;
                }
            }
        }

        if ($failure !== null) {
            $this->error('Restore drill failed. Check the application log for the error type.');
            Log::error('Restore drill failed', ['type' => $failure::class]);
            $ping->failure('restore_drill', 'Restore drill failed');
            $recipient = config('displanery.backups.notify_email');
            if (is_string($recipient) && $recipient !== '') {
                try {
                    Mail::to($recipient)->send(new OperationsMessage('Attendance restore drill failed',
                        'The Attendance restore drill failed. Inspect the application log and run the drill again.'));
                } catch (Throwable) {
                    Log::warning('Restore drill failure mail could not be sent');
                }
            }

            return self::FAILURE;
        }

        Log::info('Restore drill passed', ['tables' => count($rows)]);
        $ping->success('restore_drill');

        return self::SUCCESS;
    }

    private function serverConnection(): PDO
    {
        $settings = config('database.connections.restore_drill');
        if (! $settings['username'] || ! $settings['host']) {
            throw new RuntimeException('Restore drill credentials are incomplete.');
        }

        return new PDO('mysql:host='.$settings['host'].';port='.$settings['port'].';charset=utf8mb4',
            $settings['username'], $settings['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    private function preflightGrants(PDO $connection, string $liveName): void
    {
        if (! app()->isProduction()) {
            return;
        }
        foreach ($connection->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN) as $grant) {
            if ((preg_match('/\bON\s+\*\.\*/i', $grant) && ! preg_match('/^GRANT USAGE ON \*\.\*/i', $grant))
                || preg_match('/\bON\s+`?'.preg_quote($liveName, '/').'`?\./i', $grant)) {
                throw new RuntimeException('Restore drill account has excessive privileges.');
            }
        }
    }

    private function filteredDump($stream): \Generator
    {
        while (($line = fgets($stream, 65536)) !== false) {
            yield preg_match('/^\s*(?:\/\*!|CREATE\b|DEFINER\b)/i', $line)
                ? preg_replace('/DEFINER\s*=\s*(?:`[^`]*`|\'[^\']*\'|[^\s@]+)\s*@\s*(?:`[^`]*`|\'[^\']*\'|[^\s*]+)\s*/i', '', $line)
                : $line;
        }
    }

    private function verifyDatabase(string $scratchName): array
    {
        $live = DB::connection();
        $drill = DB::connection('restore_drill');
        $tableNames = fn ($connection, $schema) => array_column($connection->select('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ?', [$schema, 'BASE TABLE']), 'TABLE_NAME');
        $liveTables = $tableNames($live, $live->getDatabaseName());
        $drillTables = $tableNames($drill, $scratchName);
        if (array_diff($liveTables, $drillTables)) {
            throw new RuntimeException('Restored database is missing live tables.');
        }
        $restoredMigrations = $drill->table('migrations')->orderBy('id')->get(['id', 'migration', 'batch'])
            ->map(fn ($row) => (array) $row)->all();
        $liveMigrations = $live->table('migrations')->orderBy('id')->limit(count($restoredMigrations))
            ->get(['id', 'migration', 'batch'])->map(fn ($row) => (array) $row)->all();
        if ($restoredMigrations !== $liveMigrations) {
            throw new RuntimeException('Restored migrations are not a live prefix.');
        }
        $triggers = fn ($connection, $schema) => array_column($connection->select('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ?', [$schema]), 'TRIGGER_NAME');
        if (array_diff($triggers($live, $live->getDatabaseName()), $triggers($drill, $scratchName))) {
            throw new RuntimeException('Restored database is missing triggers.');
        }
        $this->verifyAppendOnly($live, $drill, $liveTables);
        $rows = [];
        foreach ($liveTables as $table) {
            if (! in_array($table, self::VOLATILE, true)) {
                $rows[] = [$table, $drill->table($table)->count(), $live->table($table)->count()];
            }
        }

        $appendRow = $drill->table('audit_logs')->first();
        if (! $appendRow) {
            $id = $drill->table('audit_logs')->insertGetId(['action' => '__drill__']);
            $appendRow = (object) ['id' => $id];
        }
        $this->assertRejected(fn () => $drill->table('audit_logs')->where('id', $appendRow->id)->delete());
        $this->assertRejected(fn () => $drill->table('audit_logs')->where('id', $appendRow->id)->update(['action' => '__drill_update__']));

        return $rows;
    }

    private function verifyAppendOnly(Connection $live, Connection $drill, array $liveTables): void
    {
        foreach (self::APPEND_ONLY as $table) {
            if (! in_array($table, $liveTables, true)) {
                continue;
            }
            $max = $drill->table($table)->max('id');
            if ($max !== null && $live->table($table)->where('id', '<=', $max)->count() !== $drill->table($table)->count()) {
                throw new RuntimeException('Append-only rows are missing.');
            }
        }
    }

    private function assertRejected(callable $operation): void
    {
        try {
            $operation();
        } catch (QueryException $error) {
            if (($error->errorInfo[0] ?? null) === '45000') {
                return;
            }
            throw new RuntimeException('A restored trigger check failed for another reason.');
        }
        throw new RuntimeException('A restored immutability trigger did not reject a write.');
    }
}
