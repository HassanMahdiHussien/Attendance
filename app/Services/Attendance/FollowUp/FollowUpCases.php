<?php

namespace App\Services\Attendance\FollowUp;

use App\Models\Attendance\FollowUpCase;
use App\Models\Employee;
use App\Models\Leave\LeaveRequest;
use App\Services\Attendance\Reporting\ReportFilters;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class FollowUpCases
{
    public const TILES = ['open_cases', 'waiting_manager', 'waiting_approver', 'waiting_hr'];

    public function query(ReportFilters $filters, ?string $state = null, ?string $issueType = null): Builder
    {
        abort_unless(app(TenantContext::class)->companyId() === $filters->companyId, 403);

        $query = FollowUpCase::query()->where('company_id', $filters->companyId)
            ->whereBetween('work_date', [$filters->from, $filters->to]);
        if ($filters->employeeIds !== []) {
            $query->whereIn('employee_id', $filters->employeeIds);
        }
        if ($state !== null && $state !== '') {
            $query->where('state', $state);
        }
        if ($issueType !== null && $issueType !== '') {
            $query->whereJsonContains('items', ['type' => $issueType]);
        }
        if ($filters->branchIds !== [] || $filters->departments !== [] || $filters->managerEmployeeIds !== []
            || $filters->workPatterns !== [] || $filters->statuses !== []) {
            $query->whereHas('day', function (Builder $day) use ($filters): void {
                if ($filters->branchIds !== []) {
                    $day->whereIn('branch_id', $filters->branchIds);
                }
                if ($filters->departments !== []) {
                    $day->whereIn('inputs->department', $filters->departments);
                }
                if ($filters->managerEmployeeIds !== []) {
                    $day->whereIn('inputs->manager_employee_id', $filters->managerEmployeeIds);
                }
                if ($filters->workPatterns !== []) {
                    $day->whereIn('inputs->work_pattern', $filters->workPatterns);
                }
                if ($filters->statuses !== []) {
                    $day->whereIn('status', $filters->statuses);
                }
            });
        }

        return $query;
    }

    public function forTile(ReportFilters $filters, string $tile): Builder
    {
        abort_unless(in_array($tile, self::TILES, true), 404);
        $query = $this->query($filters);

        return $tile === 'open_cases' ? $query->where('state', '!=', 'closed') : $query->where('state', $tile);
    }

    public function tileTotals(ReportFilters $filters): array
    {
        $totals = [];
        foreach (self::TILES as $tile) {
            $totals[$tile] = $this->forTile($filters, $tile)->count();
        }

        return $totals;
    }

    public function employeeIds(ReportFilters $filters, string $tile): array
    {
        return $this->forTile($filters, $tile)->distinct()->pluck('employee_id')->map(fn ($id): int => (int) $id)->all();
    }

    public function closureRequests(FollowUpCase $case): Collection
    {
        abort_unless(app(TenantContext::class)->companyId() === $case->company_id, 403);

        return LeaveRequest::query()->where('company_id', $case->company_id)
            ->whereIn('id', $case->request_ids ?? [])
            ->whereIn('external_id', $case->closure_source['request_numbers'] ?? [])->get(['id', 'external_id']);
    }

    public function forEmployee(Employee $employee): Collection
    {
        abort_unless(app(TenantContext::class)->companyId() === $employee->company_id, 403);

        return FollowUpCase::query()->where('company_id', $employee->company_id)
            ->where('employee_id', $employee->id)->with(['events', 'deliveryRoutes'])
            ->latest('work_date')->limit(25)->get();
    }

    public function forDay(Employee $employee, string $date): ?FollowUpCase
    {
        abort_unless(app(TenantContext::class)->companyId() === $employee->company_id, 403);

        return FollowUpCase::query()->where('company_id', $employee->company_id)
            ->where('employee_id', $employee->id)->whereDate('work_date', $date)
            ->with(['events', 'deliveryRoutes'])->first();
    }

    public function routeReason(array $route): string
    {
        if ($route['recipient_available'] ?? false) {
            return '';
        }
        if ($route['kind'] === 'approver') {
            return ($route['recipient_employee_id'] ?? null) === null
                ? __('messages.follow_up_ui.approver_unmapped')
                : __('messages.follow_up_ui.approver_email_missing');
        }

        return __('messages.follow_up_ui.manager_email_missing');
    }
}
