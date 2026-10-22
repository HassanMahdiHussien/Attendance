<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

class AttendanceProcessed implements ShouldDispatchAfterCommit
{
    public function __construct(
        public int $companyId,
        public array $personCodes,
        public string $from,
        public string $to,
    ) {}
}
