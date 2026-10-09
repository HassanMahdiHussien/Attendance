<?php

namespace App\Services\Cases;

use Carbon\CarbonImmutable;

class NextActionItem
{
    public function __construct(
        public readonly string $actor,
        public readonly string $label,
        public readonly string $url,
        public readonly string $tone = 'indigo',
        public readonly ?CarbonImmutable $due = null,
        public readonly ?string $deadlineAlert = null,
        public readonly int $priority = 4,
        public readonly string $key = '',
        public readonly ?string $target = null,
    ) {}
}
