<?php

namespace App\Services\Attendance\Reporting;

class JobStatePresentation
{
    public function tone(?string $state): string
    {
        return match ($state) {
            'completed' => 'green',
            'failed' => 'red',
            'processing', 'running' => 'indigo',
            default => 'gray',
        };
    }
}
