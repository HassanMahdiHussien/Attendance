<?php

namespace App\Services;

use App\Models\CaseEvent;
use App\Models\DisciplinaryCase;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class CaseEventLogger
{
    public function log(DisciplinaryCase $case, string $eventType, array $payload = [], ?string $notes = null, ?User $actor = null): CaseEvent
    {
        $actor ??= Auth::user();

        return CaseEvent::create([
            'company_id' => $case->company_id,
            'case_id' => $case->id,
            'actor_user_id' => $actor?->id,
            'event_type' => $eventType,
            'payload' => $payload,
            'notes' => $notes,
            'created_at' => now(),
        ]);
    }
}
