<?php

use App\Console\Commands\BackupRestoreDrill;
use App\Enums\UserRole;
use App\Jobs\HeartbeatJob;
use App\Models\User;
use App\Support\HealthPing;
use Database\Seeders\DocumentDemoSeeder;
use Database\Seeders\LocalUsersSeeder;
use Database\Seeders\PilotCompanySeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Backup\Config\Config as BackupConfig;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Events\BackupWasSuccessful;

// The test transaction keeps a snapshot of the data dictionary, so look from a separate connection.
function scratchDatabaseExists(): bool
{
    $settings = config('database.connections.restore_drill');
    $server = new PDO('mysql:host='.$settings['host'].';port='.$settings['port'],
        $settings['username'], $settings['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    return (bool) $server->query("SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = 'testing_restore_drill'")->fetchColumn();
}

it('silently skips unset pings and never logs a secret URL on transport failure', function (): void {
    Http::fake(['*' => Http::response('bad', 500)]);
    app(HealthPing::class)->success('heartbeat');
    Http::assertNothingSent();

    config()->set('displanery.healthchecks.heartbeat', 'https://example.test/secret-token');
    Log::spy();
    app(HealthPing::class)->success('heartbeat');
    Http::assertSent(fn ($request) => $request->url() === 'https://example.test/secret-token');
    Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => ! str_contains(json_encode([$message, $context]), 'secret-token'));
});

it('swallows a ping transport exception without recording its secret URL', function (): void {
    config()->set('displanery.healthchecks.heartbeat', 'https://example.test/secret-token');
    Log::spy();
    Http::fake(fn () => throw new ConnectionException('https://example.test/secret-token'));
    app(HealthPing::class)->success('heartbeat');
    Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => ! str_contains(json_encode([$message, $context]), 'secret-token'));
});

it('pings on worker heartbeat and backup events', function (): void {
    Mail::fake();
    config()->set('displanery.healthchecks.heartbeat', 'https://example.test/heartbeat');
    config()->set('displanery.healthchecks.backup', 'https://example.test/backup');
    Http::fake(['*' => Http::response('OK')]);

    app(HeartbeatJob::class)->handle();
    event(new BackupWasSuccessful('local', 'displanery'));
    event(new BackupHasFailed(new Exception('private error')));

    Http::assertSentCount(3);
    Http::assertSent(fn ($request) => $request->url() === 'https://example.test/backup/fail'
        && ! str_contains($request->body(), 'private error'));
});

it('checks recent failed jobs without exposing payloads', function (): void {
    config()->set('displanery.healthchecks.queue_failures', 'https://example.test/queue');
    Http::fake(['*' => Http::response('OK')]);
    $this->artisan('ops:check-failures')->assertExitCode(0);
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\SensitiveJob', 'data' => ['secret' => 'hidden']]),
        'exception' => 'hidden', 'failed_at' => now(),
    ]);
    $this->artisan('ops:check-failures')->assertExitCode(1);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/fail')
        && str_contains($request->body(), 'SensitiveJob') && ! str_contains($request->body(), 'hidden'));
});

it('sends a plain operational mail test', function (): void {
    Mail::fake();
    $this->artisan('ops:test-mail operator@example.test')->assertExitCode(0);
    Mail::assertSentCount(1);
});

it('creates only one platform admin unless explicitly allowed', function (): void {
    $make = fn ($email) => $this->artisan('app:create-platform-admin')
        ->expectsQuestion('Name', 'Operator')
        ->expectsQuestion('Email', $email)
        ->expectsQuestion('Password', 'long-private-password')
        ->expectsQuestion('Confirm password', 'long-private-password');

    $make('admin@example.test')->assertExitCode(0);
    $this->artisan('app:create-platform-admin')->assertExitCode(1);
    expect(User::where('primary_role', UserRole::PlatformAdmin->value)->count())->toBe(1);
    expect(DB::table('audit_logs')->pluck('metadata')->implode(' '))->not->toContain('long-private-password');

    $this->artisan('app:create-platform-admin --force-additional')
        ->expectsQuestion('Name', 'Second Operator')
        ->expectsQuestion('Email', 'second@example.test')
        ->expectsQuestion('Password', 'another-long-password')
        ->expectsQuestion('Confirm password', 'another-long-password')
        ->assertExitCode(0);
    expect(User::where('primary_role', UserRole::PlatformAdmin->value)->count())->toBe(2);
});

it('rejects a short or unconfirmed first-admin password', function (): void {
    $this->artisan('app:create-platform-admin')
        ->expectsQuestion('Name', 'Operator')
        ->expectsQuestion('Email', 'admin@example.test')
        ->expectsQuestion('Password', 'short')
        ->expectsQuestion('Confirm password', 'short')
        ->assertExitCode(1);
    expect(User::where('primary_role', UserRole::PlatformAdmin->value)->count())->toBe(0);
});

it('rejects weak archive passwords outside local and testing', function (): void {
    Mail::fake();
    app()->detectEnvironment(fn () => 'production');
    try {
        config()->set('displanery.backups.notify_email', 'ops@example.test');
        config()->set('backup.backup.destination.disks', ['local']);
        foreach ([null, 'weak'] as $password) {
            config()->set('backup.backup.password', $password);
            BackupConfig::rebind();
            $this->artisan('backup:run')->assertExitCode(1);
        }
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }
});

it('makes an encrypted archive and restores it into a cleaned scratch database', function (): void {
    Mail::fake();
    Http::fake();
    Storage::fake('backup-test');
    Storage::disk('local')->put('drill-proof.txt', 'durable bytes');
    config()->set('backup.backup.source.files.include', [Storage::disk('local')->path('')]);
    config()->set('backup.backup.source.files.relative_path', Storage::disk('local')->path(''));
    config()->set('backup.backup.destination.disks', ['backup-test']);
    config()->set('backup.monitor_backups.0.disks', ['backup-test']);
    config()->set('backup.backup.password', str_repeat('a', 40));
    config()->set('displanery.backups.notify_email', 'operator@example.test');
    config()->set('displanery.restore_drill.database', 'testing_restore_drill');
    config()->set('database.connections.restore_drill.database', 'testing_restore_drill');
    BackupConfig::rebind();

    $this->artisan('backup:run')->assertExitCode(0);
    $archives = Storage::disk('backup-test')->allFiles('displanery');
    expect($archives)->toHaveCount(1);
    $zip = new ZipArchive;
    $zip->open(Storage::disk('backup-test')->path($archives[0]));
    expect($zip->getFromName('drill-proof.txt'))->toBeFalse();
    $zip->setPassword(str_repeat('a', 40));
    expect($zip->getFromName('drill-proof.txt'))->toBe('durable bytes');
    $zip->close();

    $this->artisan('backup:restore-drill')->assertExitCode(0);
    expect(scratchDatabaseExists())->toBeFalse();

    config()->set('backup.backup.password', str_repeat('b', 40));
    $this->artisan('backup:restore-drill')->assertExitCode(1);
    Mail::assertSentCount(1);
    expect(scratchDatabaseExists())->toBeFalse();
    config()->set('backup.backup.password', str_repeat('a', 40));

    $settings = config('database.connections.restore_drill');
    $server = new PDO('mysql:host='.$settings['host'].';port='.$settings['port'],
        $settings['username'], $settings['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $server->exec('CREATE DATABASE `testing_restore_drill`');
    try {
        $this->artisan('backup:restore-drill')->assertExitCode(1);
        expect(scratchDatabaseExists())->toBeTrue();
    } finally {
        $server->exec('DROP DATABASE `testing_restore_drill`');
    }

    $rewriteDump = function (callable $change) use ($archives): void {
        $path = Storage::disk('backup-test')->path($archives[0]);
        $archive = new ZipArchive;
        $archive->open($path);
        $archive->setPassword(str_repeat('a', 40));
        try {
            $dumpName = collect(range(0, $archive->numFiles - 1))
                ->map(fn ($index) => $archive->getNameIndex($index))
                ->first(fn ($name) => str_starts_with($name, 'db-dumps/mysql-'));
            expect($dumpName)->not->toBeNull();
            $compressed = str_ends_with($dumpName, '.gz');
            $sql = $archive->getFromName($dumpName);
            if ($compressed) {
                $sql = gzdecode($sql);
            }
            $archive->deleteName($dumpName);
            $changed = $change($sql);
            $archive->addFromString($dumpName, $compressed ? gzencode($changed) : $changed);
            $archive->setEncryptionName($dumpName, ZipArchive::EM_AES_256, str_repeat('a', 40));
        } finally {
            $archive->close();
        }
    };
    $rewriteDump(fn ($sql) => $sql."\nINSERT INTO `companies` (`name`, `letterhead_logo_path`, `created_at`, `updated_at`) VALUES ('drill', 'missing.png', NOW(), NOW());\n");
    $this->artisan('backup:restore-drill')->assertExitCode(1);
    expect(scratchDatabaseExists())->toBeFalse();

    $rewriteDump(function ($sql) {
        expect($sql)->toContain('CREATE TABLE `cache_locks`');

        return str_replace('CREATE TABLE `cache_locks`', 'CREATE TABLE `cache_locks_broken`', $sql);
    });
    $this->artisan('backup:restore-drill')->assertExitCode(1);
    expect(scratchDatabaseExists())->toBeFalse();
});

it('refuses unsafe scratch database names before changing any database', function (): void {
    foreach (['testing', 'Other_restore_drill', 'testing-restore_drill'] as $name) {
        config()->set('displanery.restore_drill.database', $name);
        $this->artisan('backup:restore-drill')->assertExitCode(1);
    }
});

it('refuses demo seeders in production', function (): void {
    app()->detectEnvironment(fn () => 'production');
    try {
        foreach ([PilotCompanySeeder::class, LocalUsersSeeder::class,
            DocumentDemoSeeder::class] as $seeder) {
            expect(fn () => app($seeder)->run())->toThrow(RuntimeException::class);
        }
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }
});

it('gates backup schedule entries on the backups setting', function (): void {
    Artisan::call('schedule:list');
    $events = collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains($event->command ?? '', 'backup:'));
    expect($events)->toHaveCount(4);
    config()->set('displanery.backups.enabled', false);
    expect($events->every(fn ($event) => ! $event->filtersPass(app())))->toBeTrue();
    config()->set('displanery.backups.enabled', true);
    expect($events->every(fn ($event) => $event->filtersPass(app())))->toBeTrue();
});

it('rejects missing restored tables and referenced files', function (): void {
    $database = 'testing_restore_drill';
    $settings = config('database.connections.restore_drill');
    $server = new PDO('mysql:host='.$settings['host'].';port='.$settings['port'],
        $settings['username'], $settings['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $server->exec("CREATE DATABASE `{$database}`");
    config()->set('database.connections.restore_drill.database', $database);
    DB::purge('restore_drill');
    $command = app(BackupRestoreDrill::class);
    try {
        expect(fn () => (new ReflectionMethod($command, 'verifyDatabase'))->invoke($command, $database))
            ->toThrow(RuntimeException::class, 'missing live tables');

        $server->exec("CREATE TABLE `{$database}`.`companies` (id BIGINT PRIMARY KEY, letterhead_logo_path VARCHAR(255), internal_regulation_file_path VARCHAR(255))");
        $server->exec("INSERT INTO `{$database}`.`companies` VALUES (1, 'missing.png', NULL)");
        $archive = storage_path('app/drill-file-check.zip');
        $zip = new ZipArchive;
        $zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        try {
            $zip->addFromString('unrelated.txt', 'bytes');
            expect(fn () => (new ReflectionMethod($command, 'verifyFiles'))->invoke($command, $zip))
                ->toThrow(RuntimeException::class, 'missing from archive');
        } finally {
            $zip->close();
            unlink($archive);
        }

        $server->exec("CREATE TABLE `{$database}`.`audit_logs` (id BIGINT PRIMARY KEY)");
        DB::table('audit_logs')->insert(['action' => 'drill.coverage.first']);
        $lastId = DB::table('audit_logs')->insertGetId(['action' => 'drill.coverage.second']);
        $server->exec("INSERT INTO `{$database}`.`audit_logs` VALUES ({$lastId})");
        expect(fn () => (new ReflectionMethod($command, 'verifyAppendOnly'))->invoke($command,
            DB::connection(), DB::connection('restore_drill'), ['audit_logs']))
            ->toThrow(RuntimeException::class, 'Append-only rows are missing');

        $server->exec("UPDATE `{$database}`.`companies` SET letterhead_logo_path = NULL WHERE id = 1");
        $server->exec("CREATE TABLE `{$database}`.`company_letterheads` (id BIGINT PRIMARY KEY, logo_path VARCHAR(255), logo_sha256 CHAR(64), header_image_path VARCHAR(255), header_image_sha256 CHAR(64))");
        $server->exec("INSERT INTO `{$database}`.`company_letterheads` VALUES (1, 'logo.png', '".hash('sha256', 'original')."', NULL, NULL)");
        $zip = new ZipArchive;
        $zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        try {
            $zip->addFromString('storage/app/private/logo.png', 'altered');
            $zip->close();
            $zip->open($archive, ZipArchive::RDONLY);
            expect(fn () => (new ReflectionMethod($command, 'verifyFiles'))->invoke($command, $zip))
                ->toThrow(RuntimeException::class, 'hash differs');
        } finally {
            $zip->close();
            unlink($archive);
        }
    } finally {
        DB::disconnect('restore_drill');
        $server->exec("DROP DATABASE `{$database}`");
    }
});

it('removes definers while streaming SQL into the import client', function (): void {
    $stream = fopen('php://temp', 'w+');
    fwrite($stream, "/*!50017 DEFINER=`live_user`@`%`*/ CREATE TRIGGER x BEFORE UPDATE ON y FOR EACH ROW SET @x = 1;\n");
    fwrite($stream, "INSERT INTO notes VALUES ('DEFINER=user@host');\n");
    rewind($stream);
    try {
        $chunks = (new ReflectionMethod(BackupRestoreDrill::class, 'filteredDump'))
            ->invoke(app(BackupRestoreDrill::class), $stream);
        $sql = implode('', iterator_to_array($chunks));
        expect($sql)->not->toContain('DEFINER=`live_user`')->toContain('CREATE TRIGGER')
            ->toContain('DEFINER=user@host');
    } finally {
        fclose($stream);
    }
});
