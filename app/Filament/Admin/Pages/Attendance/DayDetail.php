<?php

namespace App\Filament\Admin\Pages\Attendance;

use App\Enums\UserRole;
use App\Filament\Admin\Pages\Attendance\Concerns\ReviewsDays;
use App\Filament\Admin\Resources\Attendance\AttendancePage;
use App\Models\Attendance\Day;
use App\Models\Attendance\DayDecision;
use App\Models\Attendance\FollowUpCase;
use App\Models\Attendance\ManualPunch;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Leave\LeaveRequest;
use App\Services\Attendance\FollowUp\CaseSync;
use App\Services\Attendance\FollowUp\FollowUpCases;
use App\Services\Attendance\HolidayLabels;
use App\Services\Attendance\Overtime;
use App\Services\Attendance\Reporting\AttendanceSummary;
use App\Services\Attendance\Reporting\DayPresentation;
use App\Services\Attendance\Reporting\ReportFilters;
use App\Services\Attendance\Reporting\ReportTiles;
use App\Services\Attendance\ShiftDisplay;
use App\Services\Leave\DayReconciliation;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
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

    #[Locked]
    public string $backBranch = '';

    #[Locked]
    public string $backStatus = '';

    #[Locked]
    public string $backShift = '';

    #[Locked]
    public string $backTile = '';

    #[Locked]
    public array $backFilters = [];

    #[Locked]
    public string $backSearch = '';

    #[Locked]
    public int $backPage = 1;

    #[Locked]
    public array $backReview = [];

    #[Locked]
    public array $backFollowUp = [];

    public static function getNavigationLabel(): string
    {
        return __('messages.office_ui.detail_title');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function backUrl(): string
    {
        if ($this->backFollowUp !== []) {
            $filters = array_filter(array_intersect_key($this->backFollowUp,
                array_flip(['from', 'to', 'state', 'issueType', 'branchId', 'managerId', 'page'])),
                fn ($value): bool => is_string($value) || is_int($value));

            return FollowUpCasesPage::getUrl(panel: 'admin', tenant: $this->company()).'?'.http_build_query($filters);
        }

        if ($this->backReview !== []) {
            $filters = array_filter(array_intersect_key($this->backReview,
                array_flip(['from', 'to', 'branchId', 'reviewState', 'page'])),
                fn ($value): bool => is_string($value) || is_int($value));

            return ReviewQueue::getUrl(panel: 'admin', tenant: $this->company()).'?'.http_build_query($filters);
        }

        if (ReportTiles::allowed($this->backTile)) {
            $route = auth()->user()?->primary_role === UserRole::PlatformAdmin
                ? route('dashboard.company.employees', ['company' => $this->company()->id])
                : route('dashboard.employees');
            $filters = array_intersect_key($this->backFilters, array_flip(['from', 'to', 'employee_ids', 'branch_ids',
                'departments', 'manager_employee_ids', 'work_patterns', 'shifts', 'statuses']));

            return $route.'?'.http_build_query($filters + ['tile' => $this->backTile, 'search' => $this->backSearch,
                'page' => $this->backPage]);
        }

        return DailyAttendance::getUrl(panel: 'admin', tenant: $this->company()).'?'.http_build_query([
            'date' => $this->date,
            'branchId' => ctype_digit($this->backBranch) ? $this->backBranch : '',
            'status' => in_array($this->backStatus, ReportFilters::STATUSES, true) ? $this->backStatus : '',
            'shift' => in_array($this->backShift, ShiftDisplay::KEYS, true) ? $this->backShift : '',
        ]);
    }

    public function backLabel(): string
    {
        if ($this->backFollowUp !== []) {
            return __('messages.office_ui.back_to_follow_up');
        }

        if ($this->backReview !== []) {
            return __('messages.office_ui.back_to_review');
        }

        return ReportTiles::allowed($this->backTile)
            ? __('messages.office_ui.back_to_employees') : __('messages.office_ui.back_to_daily');
    }

    public function mount(): void
    {
        parent::mount();
        $employee = request()->query('employee');
        $date = request()->query('date');
        $this->backBranch = (string) request()->query('back_branch', '');
        $this->backStatus = (string) request()->query('back_status', '');
        $this->backShift = (string) request()->query('back_shift', '');
        $this->backTile = (string) request()->query('back_tile', '');
        $backFilters = request()->query('back_filters', []);
        $this->backFilters = is_array($backFilters) ? $backFilters : [];
        $this->backSearch = (string) request()->query('back_search', '');
        $backPage = request()->query('back_page', 1);
        $this->backPage = ctype_digit((string) $backPage) ? max(1, (int) $backPage) : 1;
        $backReview = request()->query('back_review', []);
        $this->backReview = is_array($backReview) ? $backReview : [];
        $backFollowUp = request()->query('back_follow_up', []);
        $this->backFollowUp = is_array($backFollowUp) ? $backFollowUp : [];
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

    public function dayPresentation(): ?array
    {
        $day = $this->day();
        $filters = ReportFilters::fromArray($this->company(), ['from' => $this->date, 'to' => $this->date]);
        $row = app(AttendanceSummary::class)->presentationRowsForDays($filters, [$day->id])[$day->id] ?? null;

        return $row === null ? null : app(DayPresentation::class)->from($row);
    }

    /** @var array<int, string> */
    public array $closeReasons = [];

    public function closeFollowUpCase(int $id, CaseSync $sync): void
    {
        $company = $this->company();
        $case = FollowUpCase::query()->where('company_id', $company->id)
            ->where('employee_id', $this->employee()->id)->findOrFail($id);
        Gate::authorize('sendAttendanceNotifications', $company);
        $this->validate(['closeReasons.'.$id => 'required|string|max:1000'],
            ['closeReasons.'.$id.'.required' => __('messages.follow_up.reason_required')]);
        app(TenantContext::class)->runForCompany($company, fn () => $sync->closeManually($case, $this->closeReasons[$id], auth()->user()));
        unset($this->closeReasons[$id]);
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

    public function describedRequests(): array
    {
        return LeaveRequest::query()->with(['currentVersion', 'descriptionReading'])
            ->where('company_id', $this->company()->id)->where('employee_id', $this->employeeId)
            ->whereDate('start_date', '<=', $this->date)->whereDate('end_date', '>=', $this->date)
            ->get()->filter(fn (LeaveRequest $request): bool => trim((string) ($request->currentVersion?->values['description'] ?? '')) !== '')
            ->all();
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
        if ($field === 'reason') {
            return match ($value) {
                'no_device_for_employee' => __('messages.day_presentation.no_device_for_employee'),
                'branch_data_ended' => __('messages.day_presentation.branch_data_ended'),
                default => (string) $value,
            };
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
