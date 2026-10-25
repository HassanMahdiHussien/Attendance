<?php

namespace App\Filament\Admin\Pages\Attendance;

use App\Filament\Admin\Pages\Attendance\Concerns\ReviewsDays;
use App\Filament\Admin\Resources\Attendance\AttendancePage;
use App\Models\Attendance\Day;
use App\Models\Attendance\DayDecision;
use App\Models\Attendance\FollowUpCase;
use App\Models\Attendance\ManualPunch;
use App\Models\Branch;
use App\Models\Employee;
use App\Services\Attendance\FollowUp\FollowUpCases;
use App\Services\Attendance\HolidayLabels;
use App\Services\Attendance\Overtime;
use App\Services\Attendance\Reporting\ReportFilters;
use App\Services\Leave\DayReconciliation;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Locked;

class DayDetail extends AttendancePage
{
    use ReviewsDays;

    protected static ?string $slug = 'attendance/day';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.admin.pages.attendance.day-detail';

    #[Locked]
    public ?int $employeeId = null;

    #[Locked]
    public string $date = '';

    public static function getNavigationLabel(): string
    {
        return __('messages.office_ui.detail_title');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function mount(): void
    {
        parent::mount();
        $employee = request()->query('employee');
        $date = request()->query('date');
        abort_unless((is_string($employee) || is_int($employee)) && ctype_digit((string) $employee)
            && is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date), 404);
        $this->employeeId = (int) $employee;
        $this->date = $date;
        $this->employee();
    }

    public function employee(): Employee
    {
        return Employee::query()->where('company_id', $this->company()->id)->findOrFail($this->employeeId);
    }

    public function day(): Day
    {
        return Day::query()->with(['branch', 'policy'])->where('company_id', $this->company()->id)
            ->where('employee_id', $this->employeeId)->whereDate('work_date', $this->date)
            ->whereNull('superseded_by_run_id')->firstOrFail();
    }

    public function followUpCase(): ?FollowUpCase
    {
        return app(FollowUpCases::class)->forDay($this->employee(), $this->date);
    }

    public function holidayNames(): array
    {
        return app(HolidayLabels::class)->forIds($this->company()->id, $this->day()->holiday_ids ?? []);
    }

    public function versions(): LengthAwarePaginator
    {
        return Day::query()->with('run')->where('company_id', $this->company()->id)
            ->where('employee_id', $this->employeeId)->whereDate('work_date', $this->date)
            ->latest('id')->paginate(25);
    }

    public function decisions(): array
    {
        return DayDecision::query()->with('creator')->where('company_id', $this->company()->id)
            ->where('employee_id', $this->employeeId)->whereDate('work_date', $this->date)
            ->latest('id')->get()->all();
    }

    public function manualPunches(): array
    {
        $next = CarbonImmutable::parse($this->date)->addDay()->toDateString();
        $isShift = in_array($this->day()->inputs['work_pattern'] ?? null, ['shift', 'security'], true);

        return ManualPunch::query()->with('creator')->where('company_id', $this->company()->id)
            ->where('employee_id', $this->employeeId)->where(function ($query) use ($next, $isShift): void {
                $query->whereDate('punched_at', $this->date);
                if ($isShift) {
                    $query->orWhere(fn ($nextDay) => $nextDay->where('role', 'check_out')
                        ->whereBetween('punched_at', [$next.' 00:00:00', $next.' 02:00:00']));
                }
            })
            ->latest('id')->get()->all();
    }

    public function leaveReconciliation(): array
    {
        return app(DayReconciliation::class)->for($this->employee(), $this->date)[$this->date] ?? [];
    }

    public function overtime(): array
    {
        $filters = ReportFilters::fromArray($this->company(), [
            'from' => $this->date, 'to' => $this->date, 'employee_ids' => [$this->employeeId],
        ]);

        return iterator_to_array(app(Overtime::class)->perDay($filters, $this->employeeId), false)[0] ?? [];
    }

    public function entitlement(): ?array
    {
        $filters = ReportFilters::fromArray($this->company(), [
            'from' => $this->date, 'to' => $this->date, 'employee_ids' => [$this->employeeId],
        ]);

        return iterator_to_array(app(Overtime::class)->entitlements($filters), false)[0] ?? null;
    }

    public function displayValue(mixed $value, ?string $field = null): string
    {
        if ($value === null || $value === []) {
            return '—';
        }
        if (is_bool($value)) {
            return $value ? __('messages.office_ui.yes') : __('messages.office_ui.no');
        }
        if (in_array($field, ['rest_days', 'office_rest_days', 'shift_rest_days'], true) && is_array($value)) {
            return $this->weekdays($value);
        }
        if ($field === 'compensatory_rest_days' && is_array($value)) {
            return collect($value)->map(fn (mixed $days, string $pattern): string => __('messages.reporting_ui.patterns.'.$pattern).': '
                .(is_array($days) ? $this->weekdays($days) : __('messages.overtime_ui.individual_rest_days')))->implode(' · ');
        }
        if ($field === 'shift_starts' && is_array($value)) {
            return implode(', ', array_map(fn (mixed $time): string => substr((string) $time, 0, 5), $value));
        }
        if ($field === 'exit_status' && in_array($value, ['none', 'pending_review', 'confirmed'], true)) {
            return __('messages.office_ui.exit_statuses.'.$value);
        }
        if ($field === 'work_pattern' && is_string($value)) {
            return __('messages.reporting_ui.patterns.'.$value);
        }
        if ($field === 'branch_id') {
            return (string) (Branch::query()->where('company_id', $this->company()->id)->whereKey($value)->value('name') ?? $value);
        }

        return is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : (string) $value;
    }

    public function changeValue(string $field, mixed $value): string
    {
        if ($value === null || $value === []) {
            return '—';
        }
        if ($field === 'status' && is_string($value)) {
            return __('messages.office_ui.statuses.'.$value);
        }
        if ($field === 'overtime_basis' && is_string($value)) {
            return __('messages.overtime_ui.basis.'.$value);
        }
        if ($field === 'entitlement' && is_array($value) && isset($value['basis'])) {
            return number_format((float) ($value['quantity'] ?? 0), 1).' '.
                __('messages.overtime_ui.entitlement_basis.'.$value['basis']);
        }
        if (str_ends_with($field, '_minutes') && is_numeric($value)) {
            return $this->minutes((int) $value);
        }
        if (in_array($field, ['first_in', 'last_out', 'required_leave_at', 'shift_start'], true) && is_string($value)) {
            return $this->time($value);
        }
        if (in_array($field, ['intervals', 'exits'], true) && is_array($value)) {
            return collect($value)->map(fn (array $interval): string => $this->time((string) ($interval['from'] ?? ''))
                .'–'.$this->time((string) ($interval['to'] ?? '')))->implode(', ');
        }
        if ($field === 'inputs' && is_array($value)) {
            return collect($value)->only(['work_pattern', 'branch_id', 'hire_date', 'exit_status', 'last_working_day',
                'schedule_start', 'schedule_end', 'rest_days', 'policy_version'])
                ->map(fn (mixed $item, string $key): string => __('messages.office_ui.inputs.'.$key).': '.$this->displayValue($item, $key))
                ->implode(' · ');
        }

        return $this->displayValue($value, $field);
    }

    public function changeArrow(): string
    {
        return app()->getLocale() === 'ar' ? '←' : '→';
    }

    /** Values that may contain Arabic words follow the page direction; times and numbers stay left-to-right. */
    public function changeDir(string $field): string
    {
        return in_array($field, ['status', 'inputs', 'branch_id', 'overtime_basis', 'entitlement'], true) ? 'auto' : 'ltr';
    }

    /** Same-day times show as HH:MM; a time on another date keeps its date so cross-midnight stays clear. */
    private function time(string $value): string
    {
        if (strlen($value) < 16) {
            return substr($value, 0, 5);
        }

        return substr($value, 0, 10) === $this->date ? substr($value, 11, 5) : substr($value, 0, 16);
    }

    private function weekdays(array $days): string
    {
        return collect($days)->map(fn (mixed $day): string => __('messages.office_ui.weekdays.'.(int) $day))->implode(', ');
    }

    public function triggerLabel(?string $trigger): string
    {
        $known = ['punches', 'coverage', 'holiday', 'exit', 'employee', 'policy', 'review', 'pending'];

        return __('messages.office_ui.triggers.'.(in_array($trigger, $known, true) ? $trigger : 'unknown'));
    }
}
