<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

class CoverageChanged implements ShouldDispatchAfterCommit
{
    public function __construct(
        public int $companyId,
        public array $branchIds,
        public string $from,
        public string $to,
    ) {}
}
