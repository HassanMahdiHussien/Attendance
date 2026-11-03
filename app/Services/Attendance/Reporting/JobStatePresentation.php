<?php

namespace App\Services\Attendance\Reporting;

class JobStatePresentation
{
    public function tone(?string $state): string
    {
        return match ($state) {
            'completed', 'sent' => 'green',
            'failed' => 'red',
            'processing', 'running', 'sending', 'uncertain' => 'indigo',
            default => 'gray',
        };
    }
}
