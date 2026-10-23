<?php

use App\Services\Leave\LeaveStatus;

test('a rejection date overrides the approval stage', function (): void {
    $status = app(LeaveStatus::class);

    expect($status->derive('Manager Approval', '2026-10-20'))->toBe(['status' => 'rejected', 'unknown' => false])
        ->and($status->derive('Approved', null))->toBe(['status' => 'approved', 'unknown' => false])
        ->and($status->derive('Leave Entry', null))->toBe(['status' => 'draft', 'unknown' => false])
        ->and($status->derive('Department Manager Approval', null))->toBe(['status' => 'pending', 'unknown' => false])
        ->and($status->derive('Unexpected stage', null))->toBe(['status' => 'pending', 'unknown' => true]);
});
