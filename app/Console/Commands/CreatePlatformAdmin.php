<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CreatePlatformAdmin extends Command
{
    protected $signature = 'app:create-platform-admin {--force-additional}';

    protected $description = 'Create a Platform Admin account';

    public function handle(AuditLogger $audit): int
    {
        if (! $this->option('force-additional') && User::query()->where('primary_role', UserRole::PlatformAdmin->value)->exists()) {
            $this->error('A Platform Admin already exists.');

            return self::FAILURE;
        }

        $name = trim((string) $this->ask('Name'));
        $email = trim((string) $this->ask('Email'));
        $password = (string) $this->secret('Password');
        $confirmation = (string) $this->secret('Confirm password');
        if ($name === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($password) < 12 || $password !== $confirmation) {
            $this->error('Name, valid email, and matching passwords of at least 12 characters are required.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($name, $email, $password, $audit): void {
            $user = new User;
            $user->name = $name;
            $user->email = $email;
            $user->password = $password;
            $user->primary_role = UserRole::PlatformAdmin;
            $user->company_id = null;
            $user->save();
            $audit->log('platform_admin_created', $user, actor: $user);
        });
        $this->info('Platform Admin created.');

        return self::SUCCESS;
    }
}
