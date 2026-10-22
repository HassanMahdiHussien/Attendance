<?php

namespace App\Console\Commands;

use App\Models\User;
use Database\Seeders\LocalUsersSeeder;
use Illuminate\Console\Command;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DevSessionCookie extends Command
{
    protected $signature = 'dev:session-cookie {email} {--locale=en} {--restore-locale=}';

    protected $description = 'Issue a browser session for a local demo user or restore its locale';

    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('Demo sessions are available only in the local environment.');

            return self::FAILURE;
        }

        $email = (string) $this->argument('email');
        if (! str_ends_with(strtolower($email), '@example.test')) {
            $this->error('Demo sessions require an @example.test address.');

            return self::FAILURE;
        }

        $locale = (string) ($this->option('restore-locale') ?: $this->option('locale'));
        if (! in_array($locale, ['ar', 'en'], true)) {
            $this->error('Locale must be ar or en.');

            return self::FAILURE;
        }

        $user = User::query()->where('email', $email)->first();
        if (! $user) {
            $this->error('Demo user not found.');

            return self::FAILURE;
        }

        $demoCompanyId = DB::table('companies')->where('name', LocalUsersSeeder::DEMO_COMPANY_NAME)->value('id');
        if (! $demoCompanyId || ($user->company_id !== null && (int) $user->company_id !== (int) $demoCompanyId)) {
            $this->error('User is not part of the seeded demo data.');

            return self::FAILURE;
        }

        if ($this->option('restore-locale') !== null) {
            $user->forceFill(['locale' => $locale])->save();
            $this->line(json_encode(['restored' => true]));

            return self::SUCCESS;
        }

        if (! $user->is_active || ($user->company_id !== null && ! $user->company?->is_active)) {
            $this->error('Demo user is inactive.');

            return self::FAILURE;
        }

        $originalLocale = $user->locale;
        $user->forceFill(['locale' => $locale])->save();
        try {
            // The database handler retains its last write; a fresh driver must insert the new session id.
            app('session')->forgetDrivers();
            $session = app('session')->driver();
            $session->setId(Str::random(40));
            $session->start();
            $session->put(auth()->guard('web')->getName(), $user->getAuthIdentifier());
            $session->put('last_activity_at', now()->timestamp);
            $session->save();

            $cookieName = config('session.cookie');
            $cookieValue = Crypt::encryptString(CookieValuePrefix::create($cookieName, Crypt::getKey()).$session->getId());
            $names = DB::table('users')->where('email', 'like', '%@example.test')
                ->where(fn ($query) => $query->where('company_id', $demoCompanyId)->orWhereNull('company_id'))
                ->pluck('name');
            foreach (['employees' => ['name', 'name_en'], 'companies' => ['name'], 'branches' => ['name'], 'locations' => ['name']] as $table => $columns) {
                $query = DB::table($table);
                if ($table === 'companies') {
                    $query->where('id', $demoCompanyId);
                } else {
                    $query->where('company_id', $demoCompanyId);
                }
                foreach ($query->get($columns) as $row) {
                    foreach ($columns as $column) {
                        $names->push($row->{$column});
                    }
                }
            }
            $this->line(json_encode([
                'name' => $cookieName,
                'value' => $cookieValue,
                'originalLocale' => $originalLocale,
                'names' => $names->filter()->unique()->values()->all(),
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } catch (\Throwable $exception) {
            $user->forceFill(['locale' => $originalLocale])->save();
            throw $exception;
        }

        return self::SUCCESS;
    }
}
