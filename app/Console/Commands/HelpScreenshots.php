<?php

namespace App\Console\Commands;

use App\Models\User;
use Database\Seeders\LocalUsersSeeder;
use Illuminate\Console\Command;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Spatie\Browsershot\Browsershot;
use Throwable;

class HelpScreenshots extends Command
{
    protected $signature = 'help:screenshots {--only=}';

    protected $description = 'Capture local demo screenshots for the Help Center';

    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('Screenshots are available only in the local environment.');

            return self::FAILURE;
        }
        $manifest = require resource_path('help/screenshots.php');
        $only = $this->option('only');
        if ($only !== null && ! array_key_exists($only, $manifest)) {
            $this->error('Unknown screenshot name.');

            return self::FAILURE;
        }
        if (! User::query()->where('email', 'company.admin@example.test')
            ->whereHas('company', fn ($query) => $query->where('name', LocalUsersSeeder::DEMO_COMPANY_NAME))->exists()) {
            $this->error('The seeded demo company is missing.');

            return self::FAILURE;
        }

        foreach ($manifest as $name => $entry) {
            if ($only !== null && $only !== $name) {
                continue;
            }
            if (! str_ends_with($entry['user'], '@example.test')) {
                $this->error('Screenshot user must be a demo account.');

                return self::FAILURE;
            }
            $user = User::query()->where('email', $entry['user'])->first();
            if (! $user || ! $user->company_id || ! $user->company?->is_active
                || $user->company->name !== LocalUsersSeeder::DEMO_COMPANY_NAME) {
                $this->error('The seeded demo user is missing.');

                return self::FAILURE;
            }
            $originalLocale = $user->locale;
            try {
                foreach (['en', 'ar'] as $locale) {
                    $user->forceFill(['locale' => $locale])->save();
                    // A fresh driver per capture: the database handler remembers its last write and would
                    // UPDATE a row that does not exist for the new session id.
                    app('session')->forgetDrivers();
                    $session = app('session')->driver();
                    $session->setId(Str::random(40));
                    $session->start();
                    $session->put(auth()->guard('web')->getName(), $user->getAuthIdentifier());
                    $session->put('last_activity_at', now()->timestamp);
                    $session->save();
                    $cookieName = config('session.cookie');
                    $value = Crypt::encryptString(CookieValuePrefix::create($cookieName, Crypt::getKey()).$session->getId());
                    $directory = resource_path("help/shots/{$locale}");
                    if (! is_dir($directory)) {
                        mkdir($directory, 0775, true);
                    }
                    $shot = Browsershot::url(rtrim(config('app.url'), '/').$entry['path'])
                        ->setChromePath(config('displanery.pdf.chrome_path'))
                        ->noSandbox()->useCookies([$cookieName => $value], parse_url(config('app.url'), PHP_URL_HOST))
                        ->windowSize(1280, 800)->setScreenshotType('webp', 80)->waitUntilNetworkIdle();
                    if (isset($entry['selector'])) {
                        $shot->select($entry['selector']);
                    }
                    $shot->save("{$directory}/{$name}.webp");
                    $this->info("Saved {$name} ({$locale})");
                }
            } catch (Throwable $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            } finally {
                $user->forceFill(['locale' => $originalLocale])->save();
            }
        }

        return self::SUCCESS;
    }
}
