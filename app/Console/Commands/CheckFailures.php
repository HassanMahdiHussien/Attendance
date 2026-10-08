<?php

namespace App\Console\Commands;

use App\Support\HealthPing;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CheckFailures extends Command
{
    protected $signature = 'ops:check-failures';

    protected $description = 'Check failed jobs in the past hour';

    public function handle(HealthPing $ping): int
    {
        $failures = DB::table('failed_jobs')->where('failed_at', '>=', now()->subHour())->get(['payload']);
        if ($failures->isEmpty()) {
            $ping->success('queue_failures');
            $this->info('No recent failed jobs.');

            return self::SUCCESS;
        }

        $classes = $failures->map(function ($row): string {
            $payload = json_decode($row->payload, true);

            $class = (string) ($payload['displayName'] ?? 'unknown');

            return preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]*$/D', $class)
                ? basename(str_replace('\\', '/', $class)) : 'unknown';
        })->unique()->values()->all();
        $reason = $failures->count().' failed jobs: '.implode(', ', $classes);
        $ping->failure('queue_failures', $reason);
        $this->error($reason);

        return self::FAILURE;
    }
}
