<?php

namespace App\Filament\Admin\Pages\Attendance\Concerns;

use App\Models\Attendance\Day;
use App\Models\Attendance\DayDecision;
use App\Models\Attendance\ManualPunch;
use App\Models\Employee;
use App\Services\Attendance\DayReview;
use Illuminate\Validation\ValidationException;

trait ReviewsDays
{
    public ?int $reviewDayId = null;

    public string $reviewRole = 'check_out';

    public string $reviewTime = '';

    public string $reviewReason = '';

    public function selectReviewDay(int $dayId): void
    {
        $day = $this->reviewDay($dayId);
        $this->reviewDayId = $day->id;
        $this->reviewRole = $day->first_in === null ? 'check_in' : 'check_out';
        $this->reviewTime = '';
        $this->reviewReason = '';
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

    public function withdrawReview(int $dayId, string $kind, int $recordId, DayReview $review): void
    {
        $day = $this->reviewDay($dayId);
        $this->validate(['reviewReason' => 'required|string|max:1000']);
        $date = $day->work_date->toDateString();
        $record = match ($kind) {
            'decision' => DayDecision::query()->where('company_id', $day->company_id)
                ->where('employee_id', $day->employee_id)->whereDate('work_date', $date)->findOrFail($recordId),
            'manual' => ManualPunch::query()->where('company_id', $day->company_id)
                ->where('employee_id', $day->employee_id)->whereDate('punched_at', $date)->findOrFail($recordId),
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
        if ($day->reviewed) {
            $decision = DayDecision::query()->where('company_id', $day->company_id)
                ->where('employee_id', $day->employee_id)->whereDate('work_date', $date)
                ->where('decision', 'confirm_absence')->latest('id')->first();

            return $decision === null ? null : ['kind' => 'decision', 'id' => $decision->id];
        }
        $manual = ManualPunch::query()->where('company_id', $day->company_id)
            ->where('employee_id', $day->employee_id)->whereDate('punched_at', $date)
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
