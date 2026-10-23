<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

class DaysAssessed implements ShouldDispatchAfterCommit
{
    public function __construct(
        public int $companyId,
        public array $employeeIds,
        public string $from,
        public string $to,
    ) {}
}
