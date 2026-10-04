<?php

namespace App\Console\Commands;

use App\Jobs\HeartbeatJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class Heartbeat extends Command
{
    protected $signature = 'app:heartbeat {--dispatch}';

    protected $description = 'Log a heartbeat or dispatch its queued job';

    public function handle(): int
    {
        if ($this->option('dispatch')) {
            HeartbeatJob::dispatch();
        } else {
            Log::info('Heartbeat command ran.');
        }

        return self::SUCCESS;
    }
}
