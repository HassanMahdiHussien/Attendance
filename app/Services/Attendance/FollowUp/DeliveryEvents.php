<?php

namespace App\Services\Attendance\FollowUp;

use App\Models\Attendance\CaseEvent;
use App\Models\Attendance\FollowUpCase;
use App\Models\User;
use App\Services\AuditLogger;

class DeliveryEvents
{
    public function __construct(private AuditLogger $audit) {}

    public function record(FollowUpCase $case, string $event, array $before, array $after, ?User $actor = null): void
    {
        CaseEvent::create([
            'company_id' => $case->company_id, 'case_id' => $case->id,
            'event' => $event, 'before' => $before, 'after' => $after,
            'source' => 'notification', 'actor_id' => $actor?->id, 'created_at' => now(),
        ]);
        $this->audit->log('attendance.case.'.$event, $case, [
            'route_key' => $after['route_key'] ?? $before['route_key'] ?? null,
            'cycle_number' => $after['cycle_number'] ?? $before['cycle_number'] ?? null,
            'before_state' => $before['state'] ?? null, 'after_state' => $after['state'] ?? null,
        ], $actor);
    }
}
