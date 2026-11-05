<?php

namespace App\Services\Leave;

use App\Models\Leave\DescriptionReading;
use App\Models\Leave\LeaveRequest;

class LeaveDescriptionEffects
{
    public function __construct(private DayReconciliation $reconciliation) {}

    public function hourlyPreview(LeaveRequest $request, DescriptionReading $reading): array
    {
        $minutes = (int) round((float) $request->duration_days * 480);
        $found = 0;
        if ($request->employee !== null && $reading->settled_from !== null && $reading->settled_to !== null) {
            foreach ($this->reconciliation->for($request->employee,
                $reading->settled_from->toDateString(), $reading->settled_to->toDateString()) as $day) {
                foreach (['late', 'early', 'exit'] as $kind) {
                    $found += (int) ($day['remaining'][$kind] ?? 0);
                }
            }
        }

        return ['minutes' => $minutes, 'found' => $found];
    }

    public function reason(LeaveRequest $request, DescriptionReading $reading): ?string
    {
        if ($request->status !== 'approved') {
            return 'not_approved';
        }
        $type = mb_strtolower($request->leaveType?->name ?? '');
        if ($reading->category === 'compensation_for_day') {
            if ($type !== 'compensation leave') {
                return 'type_mismatch';
            }

            return ($reading->worked_dates ?? []) === [] ? 'no_worked_dates' : null;
        }
        if ($reading->category === 'hourly_settlement') {
            if (! in_array($type, ['overtime equivelant leave', 'annual leave'], true)
                || ($type === 'annual leave' && ! LeaveDescriptionReader::mentionsHourly(
                    (string) ($request->currentVersion?->values['description'] ?? '')))) {
                return 'type_mismatch';
            }

            return $reading->settled_from === null || $reading->settled_to === null ? 'no_period' : null;
        }

        return 'information_only';
    }

    public function applies(LeaveRequest $request, DescriptionReading $reading, string $category): bool
    {
        return $reading->status === 'confirmed' && $reading->category === $category
            && $this->reason($request, $reading) === null;
    }
}
