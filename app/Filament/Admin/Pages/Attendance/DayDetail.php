<?php

namespace App\Filament\Admin\Pages\Attendance;

use App\Filament\Admin\Pages\Attendance\Concerns\ReviewsDays;
use App\Filament\Admin\Resources\Attendance\AttendancePage;
use App\Models\Attendance\Day;
use App\Models\Attendance\DayDecision;
use App\Models\Attendance\ManualPunch;
use App\Models\Employee;
use App\Services\Attendance\HolidayLabels;
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

    public function displayValue(mixed $value): string
    {
        if ($value === null) {
            return '—';
        }
        if (is_bool($value)) {
            return $value ? __('messages.office_ui.yes') : __('messages.office_ui.no');
        }

        return is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : (string) $value;
    }

    public function changeValue(string $field, mixed $value): string
    {
        if ($field === 'status' && is_string($value)) {
            return __('messages.office_ui.statuses.'.$value);
        }

        return $this->displayValue($value);
    }

    public function triggerLabel(?string $trigger): string
    {
        $known = ['punches', 'coverage', 'holiday', 'exit', 'employee', 'policy', 'review', 'pending'];

        return __('messages.office_ui.triggers.'.(in_array($trigger, $known, true) ? $trigger : 'unknown'));
    }
}
