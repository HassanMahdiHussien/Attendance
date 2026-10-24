<?php

namespace App\Livewire\Attendance;

use App\Filament\Admin\Pages\Attendance\AttendancePunches;
use App\Filament\Admin\Pages\Attendance\DayDetail;
use App\Models\Attendance\DataIssue;
use App\Models\Employee;
use App\Models\Leave\Allocation;
use App\Models\Leave\Credit;
use App\Models\Leave\LeaveRequest;
use App\Models\Leave\RequestDay;
use App\Models\Leave\RequestVersion;
use App\Policies\EmployeeProfilePolicy;
use App\Services\Attendance\Reporting\AttendanceExports;
use App\Services\Attendance\Reporting\AttendanceSummary;
use App\Services\Attendance\Reporting\LastUpdate;
use App\Services\Attendance\Reporting\ReportFilters;
use App\Services\Attendance\Reporting\ReportTiles;
use App\Services\Leave\DayReconciliation;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class EmployeeAttendance extends Component
{
    #[Locked]
    public int $employeeId;

    public string $from = '';

    public string $to = '';

    public string $tab = 'days';

    public string $selectedTile = '';

    public string $exportKind = 'employee_statement_xlsx';

    public string $exportLocale = 'en';

    public bool $exportQueued = false;

    public function mount(int $employeeId): void
    {
        $this->employeeId = $employeeId;
        $this->period('current');
    }

    public function period(string $choice): void
    {
        $this->employee();
        $today = CarbonImmutable::now('Asia/Baghdad');
        abort_unless(in_array($choice, ['current', 'previous', 'last_30'], true), 404);
        [$this->from, $this->to] = match ($choice) {
            'current' => [$today->startOfMonth()->toDateString(), $today->toDateString()],
            'previous' => [$today->subMonthNoOverflow()->startOfMonth()->toDateString(), $today->subMonthNoOverflow()->endOfMonth()->toDateString()],
            'last_30' => [$today->subDays(29)->toDateString(), $today->toDateString()],
        };
    }

    public function show(string $tab): void
    {
        $this->employee();
        abort_unless(in_array($tab, ['days', 'leave', 'credits', 'changes', 'issues'], true), 404);
        $this->tab = $tab;
        $this->selectedTile = '';
    }

    public function showTile(string $tile): void
    {
        $this->employee();
        abort_unless(ReportTiles::allowed($tile), 404);
        $this->selectedTile = $tile;
        $this->tab = match (true) {
            str_starts_with($tile, 'leave:') || str_starts_with($tile, 'leave_status:') => 'leave',
            str_starts_with($tile, 'credit_') => 'credits',
            str_starts_with($tile, 'issue_type:') || $tile === 'data_issues' => 'issues',
            default => 'days',
        };
    }

    public function apply(): void
    {
        $this->employee();
    }

    public function requestExport(AttendanceExports $exports): void
    {
        $employee = $this->employee();
        abort_unless(in_array($this->exportKind, ['employee_statement_xlsx', 'statement_pdf'], true), 403);
        app(TenantContext::class)->runForCompany($employee->company_id, function () use ($employee, $exports): void {
            $exports->request($employee->company, auth()->user(), $this->exportKind,
                ['from' => $this->from, 'to' => $this->to, 'employee_ids' => [$employee->id]], $this->exportLocale);
        });
        $this->exportQueued = true;
    }

    public function render(AttendanceSummary $summary, DayReconciliation $reconciliation, LastUpdate $lastUpdate): View
    {
        $employee = $this->employee();

        return app(TenantContext::class)->runForCompany($employee->company_id, function () use ($employee, $summary, $reconciliation, $lastUpdate): View {
            $filters = ReportFilters::fromArray($employee->company, [
                'from' => $this->from, 'to' => $this->to, 'employee_ids' => [$employee->id],
            ]);
            $totals = $summary->totals($filters);
            $days = $this->tab === 'days' ? array_values(array_filter(iterator_to_array($summary->perDay($filters, $employee->id)),
                fn (array $day): bool => $this->matchesDay($day))) : [];
            $detail = $this->tab === 'days' ? $reconciliation->for($employee, $filters->from, $filters->to) : [];
            $requests = $this->tab === 'leave' ? LeaveRequest::query()->with(['leaveType', 'currentVersion'])
                ->where('employee_id', $employee->id)->where('start_date', '<=', $filters->to)
                ->where('end_date', '>=', $filters->from)->orderByDesc('start_date')->get() : collect();
            if (str_starts_with($this->selectedTile, 'leave_status:')) {
                $requests = $requests->where('status', substr($this->selectedTile, 13));
            } elseif (str_starts_with($this->selectedTile, 'leave:')) {
                $dayRequestIds = RequestDay::query()->where('employee_id', $employee->id)
                    ->whereNull('superseded_by_run_id')->where('minutes', '>', 0)
                    ->whereBetween('work_date', [$filters->from, $filters->to])
                    ->pluck('request_id')->all();
                $group = substr($this->selectedTile, 6);
                $requests = $requests->filter(function (LeaveRequest $request) use ($dayRequestIds, $group): bool {
                    $name = ($request->leaveType?->name ?? 'unmapped')
                        .($request->leaveType?->other_name === null ? '' : ' / '.$request->leaveType->other_name);

                    return in_array($request->id, $dayRequestIds, true) && $name.'|'.$request->status === $group;
                });
            }

            $requestVersions = $requests->isEmpty() ? collect() : RequestVersion::query()
                ->whereIn('request_id', $requests->pluck('id'))->orderByDesc('snapshot_at')->orderByDesc('id')
                ->get()->groupBy('request_id');
            $credits = $this->tab === 'credits' ? Credit::query()->with('sourceDay.request')->where('employee_id', $employee->id)
                ->whereNull('superseded_by_run_id')->whereHas('sourceDay', fn ($query) => $query->where('work_date', '<=', $filters->to))
                ->orderBy('id')->get() : collect();
            $used = $credits->isEmpty() ? collect() : Allocation::query()->whereIn('source_credit_id', $credits->pluck('id'))
                ->whereNull('superseded_by_run_id')->whereHas('targetDay', fn ($query) => $query->where('work_date', '<=', $filters->to)
                ->whereNull('superseded_by_run_id'))
                ->get()->groupBy('source_credit_id');
            $credits = $credits->filter(function (Credit $credit) use ($filters, $used): bool {
                $usedMinutes = $used->get($credit->id, collect())->sum('minutes');

                return match ($this->selectedTile) {
                    'credit_created' => $credit->sourceDay?->work_date?->toDateString() >= $filters->from,
                    'credit_used' => $usedMinutes > 0,
                    'credit_available' => ($credit->expires_on === null || $credit->expires_on->toDateString() >= $filters->to)
                        && $credit->minutes > $usedMinutes,
                    default => true,
                };
            });

            $changes = $this->tab === 'changes' ? $employee->versions()->with('actor')->orderByDesc('effective_from')
                ->orderByDesc('id')->get() : collect();
            $issueIds = $this->tab === 'issues' ? $summary->issueIds($filters, $employee->id) : [];
            $issues = $issueIds === [] ? collect() : DataIssue::query()->whereIn('id', $issueIds)->orderByDesc('id')->get();
            if (str_starts_with($this->selectedTile, 'issue_type:')) {
                $issues = $issues->where('type', substr($this->selectedTile, 11));
            }

            return view('livewire.attendance.employee-attendance', [
                'employee' => $employee, 'totals' => $totals,
                'tiles' => array_diff_key(ReportTiles::fromTotals($totals), array_flip([
                    'active_employees', 'office_employees_assessed', 'without_work_pattern',
                ])),
                'freshness' => $lastUpdate->for($filters),
                'days' => $days, 'detail' => $detail, 'requests' => $requests,
                'requestVersions' => $requestVersions, 'credits' => $credits,
                'used' => $used, 'changes' => $changes, 'issues' => $issues,
                'dayUrl' => DayDetail::getUrl(panel: 'admin', tenant: $employee->company),
                'punchUrl' => AttendancePunches::getUrl(panel: 'admin', tenant: $employee->company),
            ]);
        });
    }

    private function employee(): Employee
    {
        $employee = Employee::withoutGlobalScopes()->with('company')->findOrFail($this->employeeId);
        abort_unless(app(EmployeeProfilePolicy::class)->view(auth()->user(), $employee), 404);

        return $employee;
    }

    private function matchesDay(array $day): bool
    {
        $tile = $this->selectedTile;
        if ($tile === '' || $tile === 'active_employees' || $tile === 'office_employees_assessed') {
            return true;
        }
        if (in_array($tile, ['present', 'absent', 'missing_punch', 'shift_unresolved', 'pending'], true)) {
            return $day['status'] === $tile;
        }
        if ($tile === 'assessed_working_days') {
            return in_array($day['status'], ['present', 'absent', 'missing_punch'], true);
        }
        if ($tile === 'short_hours_alerts') {
            return $day['flags']['short_hours'];
        }
        if (in_array($tile, ['hr_confirmed_absence', 'absence_closed_by_request'], true)) {
            return $day['flags'][$tile];
        }
        if (in_array($tile, ['late', 'early'], true)) {
            return $day['minutes'][$tile]['gross'] > 0;
        }
        if (in_array($tile, ['balance', 'reconciled', 'remaining'], true)) {
            foreach ($day['minutes'] as $kind) {
                if (($tile === 'balance' && $kind['gross'] > 0)
                    || ($tile === 'remaining' && $kind['remaining'] > 0)
                    || ($tile === 'reconciled' && $kind['reconciled_by_leave'] + $kind['reconciled_by_credit'] > 0)) {
                    return true;
                }
            }

            return false;
        }

        return true;
    }
}
