<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class HeartbeatJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        Log::info('Heartbeat job processed.');
    }
}
