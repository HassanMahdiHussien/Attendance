<?php

namespace App\Filament\Admin\Pages\Attendance\Concerns;

use App\Models\Attendance\Day;
use App\Models\Attendance\DayDecision;
use App\Models\Attendance\ManualPunch;
use App\Models\Employee;
use App\Services\Attendance\DayReview;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

trait ReviewsDays
{
    public ?int $reviewDayId = null;

    public string $reviewRole = 'check_out';

    public string $reviewTime = '';

    public string $reviewReason = '';

    public string $reviewShiftStart = '08:00';

    public array $reviewShiftStarts = [];

    public string $reviewStatus = '';

    public bool $reviewIsShift = false;

    public function selectReviewDay(int $dayId): void
    {
        $day = $this->reviewDay($dayId);
        $this->reviewDayId = $day->id;
        $this->reviewRole = $day->first_in === null ? 'check_in' : 'check_out';
        $this->reviewTime = '';
        $this->reviewReason = '';
        $this->reviewShiftStarts = $day->policy?->shift_starts ?? ['00:00', '08:00', '16:00'];
        $this->reviewShiftStart = substr($this->reviewShiftStarts[0], 0, 5);
        $this->reviewStatus = $day->status;
        $this->reviewIsShift = in_array($day->inputs['work_pattern'] ?? null, ['shift', 'security'], true);
    }

    public function addMissingTime(DayReview $review): void
    {
        $day = $this->reviewDay($this->reviewDayId);
        $this->validate([
            'reviewRole' => 'required|in:check_in,check_out', 'reviewTime' => 'required|date_format:H:i',
            'reviewReason' => 'required|string|max:1000',
        ]);
        $review->addMissingTime($this->reviewEmployee($day), $day->work_date->toDateString(),
            $this->reviewTime, $this->reviewRole, $this->reviewReason, auth()->user());
        $this->reviewDayId = null;
    }

    public function confirmAbsence(DayReview $review): void
    {
        $day = $this->reviewDay($this->reviewDayId);
        $this->validate(['reviewReason' => 'required|string|max:1000']);
        $review->confirmAbsence($this->reviewEmployee($day), $day->work_date->toDateString(),
            $this->reviewReason, auth()->user());
        $this->reviewDayId = null;
    }

    public function pickShift(DayReview $review): void
    {
        $day = $this->reviewDay($this->reviewDayId);
        $this->validate(['reviewShiftStart' => 'required|date_format:H:i', 'reviewReason' => 'required|string|max:1000']);
        $review->pickShift($this->reviewEmployee($day), $day->work_date->toDateString(),
            $this->reviewShiftStart, $this->reviewReason, auth()->user());
        $this->reviewDayId = null;
    }

    public function withdrawReview(int $dayId, string $kind, int $recordId, DayReview $review): void
    {
        $day = $this->reviewDay($dayId);
        $this->validate(['reviewReason' => 'required|string|max:1000']);
        $date = $day->work_date->toDateString();
        $isShift = in_array($day->inputs['work_pattern'] ?? null, ['shift', 'security'], true);
        $record = match ($kind) {
            'decision' => DayDecision::query()->where('company_id', $day->company_id)
                ->where('employee_id', $day->employee_id)->whereDate('work_date', $date)->findOrFail($recordId),
            'manual' => ManualPunch::query()->where('company_id', $day->company_id)
                ->where('employee_id', $day->employee_id)->where(function ($query) use ($date, $isShift): void {
                    $next = CarbonImmutable::parse($date)->addDay()->toDateString();
                    $query->whereDate('punched_at', $date);
                    if ($isShift) {
                        $query->orWhere(fn ($nextDay) => $nextDay->where('role', 'check_out')
                            ->whereBetween('punched_at', [$next.' 00:00:00', $next.' 02:00:00']));
                    }
                })->findOrFail($recordId),
            default => throw ValidationException::withMessages(['review' => __('messages.office_ui.invalid_review')]),
        };
        $review->withdraw($record, $this->reviewReason, auth()->user());
        $this->reviewReason = '';
    }

    public function withdrawLatest(int $dayId, DayReview $review): void
    {
        $latest = $this->latestReview($this->reviewDay($dayId));
        if ($latest === null) {
            throw ValidationException::withMessages(['review' => __('messages.office_ui.invalid_review')]);
        }
        $this->withdrawReview($dayId, $latest['kind'], $latest['id'], $review);
    }

    public function latestReview(Day $day): ?array
    {
        $date = $day->work_date->toDateString();
        $isShift = in_array($day->inputs['work_pattern'] ?? null, ['shift', 'security'], true);
        $shiftDecision = DayDecision::query()->where('company_id', $day->company_id)
            ->where('employee_id', $day->employee_id)->whereDate('work_date', $date)
            ->whereIn('decision', ['pick_shift', 'withdraw_shift'])->latest('id')->first();
        if ($shiftDecision?->decision === 'pick_shift') {
            return ['kind' => 'decision', 'id' => $shiftDecision->id, 'shift' => true];
        }
        if ($day->reviewed) {
            $decision = DayDecision::query()->where('company_id', $day->company_id)
                ->where('employee_id', $day->employee_id)->whereDate('work_date', $date)
                ->where('decision', 'confirm_absence')->latest('id')->first();

            return $decision === null ? null : ['kind' => 'decision', 'id' => $decision->id];
        }
        $manual = ManualPunch::query()->where('company_id', $day->company_id)
            ->where('employee_id', $day->employee_id)->where(function ($query) use ($date, $isShift): void {
                $next = CarbonImmutable::parse($date)->addDay()->toDateString();
                $query->whereDate('punched_at', $date);
                if ($isShift) {
                    $query->orWhere(fn ($nextDay) => $nextDay->where('role', 'check_out')
                        ->whereBetween('punched_at', [$next.' 00:00:00', $next.' 02:00:00']));
                }
            })
            ->whereNull('reverses_id')->whereNotIn('id', function ($query) use ($day): void {
                $query->select('reverses_id')->from('attendance_manual_punches')
                    ->where('company_id', $day->company_id)->whereNotNull('reverses_id');
            })->latest('id')->first();

        return $manual === null ? null : ['kind' => 'manual', 'id' => $manual->id];
    }

    private function reviewDay(?int $dayId): Day
    {
        return Day::query()->where('company_id', $this->company()->id)
            ->whereNull('superseded_by_run_id')->findOrFail($dayId);
    }

    private function reviewEmployee(Day $day): Employee
    {
        return Employee::query()->where('company_id', $this->company()->id)->findOrFail($day->employee_id);
    }
}
