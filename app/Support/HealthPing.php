<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class HealthPing
{
    public function success(string $check): void
    {
        $this->send($check, false);
    }

    public function failure(string $check, string $reason): void
    {
        $this->send($check, true, mb_substr($reason, 0, 200));
    }

    private function send(string $check, bool $failure, string $reason = ''): void
    {
        $url = config("displanery.healthchecks.{$check}");
        if (! is_string($url) || trim($url) === '') {
            return;
        }

        try {
            $response = Http::timeout(5)->retry(2, 200, throw: false)
                ->withBody($reason, 'text/plain')->post(rtrim($url, '/').($failure ? '/fail' : ''));
            if (! $response->successful()) {
                Log::warning('Health ping failed', ['check' => $check, 'status' => $response->status()]);
            }
        } catch (Throwable) {
            // The URL contains a credential and HTTP exception messages may include it.
            Log::warning('Health ping transport failed', ['check' => $check]);
        }
    }
}
