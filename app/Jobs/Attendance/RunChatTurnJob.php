<?php

namespace App\Jobs\Attendance;

use App\Services\Attendance\Ai\ChatTurns;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunChatTurnJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public function __construct(public int $companyId, public int $turnId) {}

    public function handle(ChatTurns $turns): void
    {
        $turns->run($this->companyId, $this->turnId);
    }
}
