<?php

namespace App\Services\Cases;

use App\Enums\CaseStatus;
use App\Models\DisciplinaryCase;
use Carbon\CarbonImmutable;

class Deadlines
{
    public function responseDue(DisciplinaryCase $case): ?CarbonImmutable
    {
        return $this->due($case->defense_letter_issued_at, 'response_days');
    }

    public function procedureDue(DisciplinaryCase $case): ?CarbonImmutable
    {
        return $this->due($case->defense_letter_issued_at, 'procedure_days');
    }

    public function appealEnd(DisciplinaryCase $case): ?CarbonImmutable
    {
        return $this->due($case->decision_issued_at, 'appeal_days');
    }

    public function approachingCutoff(?CarbonImmutable $now = null): CarbonImmutable
    {
        return ($now ?? CarbonImmutable::now('Asia/Baghdad'))->setTimezone('Asia/Baghdad')
            ->addHours((int) config('displanery.deadlines.approaching_hours'));
    }

    public function responseStatus(DisciplinaryCase $case, ?CarbonImmutable $now = null): ?string
    {
        $due = $this->responseDue($case);
        if ($due === null) {
            return null;
        }
        $received = $case->relationLoaded('events')
            ? $case->events->where('event_type', 'response_handoff_recorded')->sortBy('created_at')->first()
            : $case->events()->where('event_type', 'response_handoff_recorded')->orderBy('created_at')->first();
        if ($received !== null) {
            return CarbonImmutable::parse($received->created_at, config('app.timezone'))->setTimezone('Asia/Baghdad')->toDateString() <= $due->toDateString() ? 'met' : 'missed';
        }

        return $this->clockStatus($due, $now, 'missed');
    }

    public function procedureStatus(DisciplinaryCase $case, ?CarbonImmutable $now = null): ?string
    {
        $due = $this->procedureDue($case);
        if ($due === null) {
            return null;
        }
        if ($case->decision_issued_at !== null) {
            return CarbonImmutable::parse($case->decision_issued_at)->setTimezone('Asia/Baghdad')->toDateString() <= $due->toDateString() ? 'met' : 'overdue';
        }
        if ($case->status === CaseStatus::ArchivedFinal) {
            return CarbonImmutable::parse($case->closed_at)->setTimezone('Asia/Baghdad')->toDateString() <= $due->toDateString() ? 'met' : 'overdue';
        }

        return $this->clockStatus($due, $now);
    }

    public function appealStatus(DisciplinaryCase $case, ?CarbonImmutable $now = null): ?string
    {
        $due = $this->appealEnd($case);
        if ($due === null) {
            return null;
        }
        if (in_array($case->status, [CaseStatus::AppealPending, CaseStatus::AppealResolved, CaseStatus::ArchivedFinal], true)) {
            return 'met';
        }

        return $this->clockStatus($due, $now);
    }

    private function due(mixed $anchor, string $configKey): ?CarbonImmutable
    {
        if ($anchor === null) {
            return null;
        }

        return CarbonImmutable::parse($anchor)->setTimezone('Asia/Baghdad')->startOfDay()
            ->addDays((int) config("displanery.deadlines.{$configKey}"));
    }

    private function clockStatus(CarbonImmutable $due, ?CarbonImmutable $now, string $past = 'overdue'): string
    {
        $now ??= CarbonImmutable::now('Asia/Baghdad');
        $now = $now->setTimezone('Asia/Baghdad');
        if ($now->startOfDay()->gt($due)) {
            return $past;
        }
        if ($due->endOfDay()->setMicrosecond(0)->lte($this->approachingCutoff($now))) {
            return 'approaching';
        }

        return 'on_track';
    }
}
