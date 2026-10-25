<?php

namespace App\Filament\Admin\Pages\Attendance;

use App\Filament\Admin\Resources\Attendance\AttendancePage;
use App\Models\Attendance\FollowUpCase;
use App\Models\Branch;
use App\Models\Employee;
use App\Services\Attendance\FollowUp\CaseSync;
use App\Services\Attendance\FollowUp\FollowUpCases;
use App\Services\Attendance\Reporting\AttendanceExports;
use App\Services\Attendance\Reporting\ReportFilters;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;

class FollowUpCasesPage extends AttendancePage
{
    protected static ?string $slug = 'attendance/follow-up-cases';

    protected string $view = 'filament.admin.pages.attendance.follow-up-cases';

    public string $from = '';

    public string $to = '';

    public string $state = '';

    public string $issueType = '';

    public string $branchId = '';

    public string $managerId = '';

    public string $managerSearch = '';

    public array $reportFilters = [];

    public string $closeReason = '';

    public static function getNavigationLabel(): string
    {
        return __('messages.follow_up_ui.cases');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function mount(): void
    {
        parent::mount();
        $this->from = (string) request()->query('from', now('Asia/Baghdad')->startOfMonth()->toDateString());
        $this->to = (string) request()->query('to', now('Asia/Baghdad')->toDateString());
        $this->state = (string) request()->query('state', '');
        $this->branchId = (string) (request()->query('branch_ids')[0] ?? '');
        $this->managerId = (string) (request()->query('manager_employee_ids')[0] ?? '');
        $this->reportFilters = request()->only(['employee_ids', 'departments', 'work_patterns', 'statuses']);
    }

    public function filters(): ReportFilters
    {
        return ReportFilters::fromArray($this->company(), [
            'from' => $this->from, 'to' => $this->to,
            'branch_ids' => $this->branchId === '' ? [] : [$this->branchId],
            'manager_employee_ids' => $this->managerId === '' ? [] : [$this->managerId],
        ] + $this->reportFilters);
    }

    public function cases(): LengthAwarePaginator
    {
        $service = app(FollowUpCases::class);
        $query = $this->state === 'open_cases'
            ? $service->forTile($this->filters(), 'open_cases')
            : $service->query($this->filters(), $this->state, $this->issueType);
        if ($this->state === 'open_cases' && $this->issueType !== '') {
            $query->whereJsonContains('items', ['type' => $this->issueType]);
        }

        return $query
            ->with('employee')->latest('work_date')->latest('id')->paginate(25);
    }

    public function branches()
    {
        return Branch::query()->where('company_id', $this->company()->id)->orderBy('name')->get();
    }

    public function managers()
    {
        if (mb_strlen(trim($this->managerSearch)) < 2) {
            return collect();
        }

        return Employee::query()->where('company_id', $this->company()->id)
            ->where(fn ($query) => $query->where('name', 'like', '%'.$this->managerSearch.'%')
                ->orWhere('name_en', 'like', '%'.$this->managerSearch.'%')
                ->orWhere('employee_code', 'like', $this->managerSearch.'%'))
            ->orderBy('name')->limit(20)->get();
    }

    public function selectedManager(): ?Employee
    {
        return $this->managerId === '' ? null : Employee::query()
            ->where('company_id', $this->company()->id)->findOrFail($this->managerId);
    }

    public function closeCase(int $id, CaseSync $sync): void
    {
        $case = FollowUpCase::query()->where('company_id', $this->company()->id)->findOrFail($id);
        $this->validate(['closeReason' => 'required|string|max:1000']);
        $sync->closeManually($case, $this->closeReason, auth()->user());
        $this->closeReason = '';
    }

    public function export(AttendanceExports $exports): void
    {
        Gate::authorize('sendAttendanceNotifications', $this->company());
        $exports->request($this->company(), auth()->user(), 'follow_up_cases', array_replace($this->filters()->toArray(), [
            'case_state' => $this->state === '' ? null : $this->state,
            'case_issue_type' => $this->issueType === '' ? null : $this->issueType,
        ]), app()->getLocale());
    }
}
