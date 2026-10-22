<?php

namespace App\Services\Leave;

class LeaveStatus
{
    public function derive(string $rawStatus, ?string $rejectedOn): array
    {
        if ($rejectedOn !== null) {
            return ['status' => 'rejected', 'unknown' => false];
        }
        $status = mb_strtolower(trim($rawStatus));
        if ($status === 'approved') {
            return ['status' => 'approved', 'unknown' => false];
        }
        if ($status === 'leave entry') {
            return ['status' => 'draft', 'unknown' => false];
        }
        if (str_contains($status, 'approval')) {
            return ['status' => 'pending', 'unknown' => false];
        }

        return ['status' => 'pending', 'unknown' => true];
    }
}
