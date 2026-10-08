<?php

namespace App\Services\EmailIntake;

use Carbon\CarbonInterface;

class IntakeMessage
{
    public function __construct(
        public int $uid,
        public int $size,
        public string $rawHeaders,
        public CarbonInterface $receivedAt,
        public string $plainText = '',
        public string $html = '',
        public array $attachments = [],
    ) {}
}
