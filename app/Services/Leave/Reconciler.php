<?php

namespace App\Services\Leave;

use App\Models\Attendance\DataIssue;
use App\Models\Attendance\Day;
use App\Models\Attendance\Policy;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeVersion;
use App\Models\Leave\Allocation;
use App\Models\Leave\Credit;
use App\Models\Leave\LeaveRequest;
use App\Models\Leave\ProcessingRun;
use App\Models\Leave\RequestDay;
use App\Models\Leave\RequestDecision;
use App\Models\Leave\Setting;
use App\Services\Attendance\DataIssues;
use App\Services\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Throwable;

class Reconciler
{
    public function __construct(private LeaveDistributor $distributor, private LeaveAllocator $allocator, private AuditLogger $audit, private DataIssues $issues) {}

    public function run(Company $company, array $employeeIds, string $trigger, ?string $from = null, ?string $to = null): ProcessingRun
    {
        abort_unless(app(TenantContext::class)->companyId() === $company->id, 403);
        $ids = array_values(array_unique(array_map('intval', $employeeIds)));
        sort($ids, SORT_NUMERIC);
        $run = ProcessingRun::create([
            'company_id' => $company->id, 'trigger_source' => $trigger,
            'scope' => ['employee_ids' => $ids, 'from' => $from, 'to' => $to], 'status' => 'processing', 'started_at' => now(),
        ]);
        try {
            $changed = 0;
            foreach (array_chunk($ids, 100) as $chunk) {
                $changed += DB::transaction(function () use ($company, $chunk, $run): int {
                    $employees = Employee::query()->where('company_id', $company->id)->whereIn('id', $chunk)
                        ->orderBy('id')->lockForUpdate()->get();
                    $count = 0;
                    foreach ($employees as $employee) {
                        $count += (int) $this->replayEmployee($company, $employee, $run);
                    }

                    return $count;
                });
            }
            $run->update(['status' => 'completed', 'counts' => ['employees_changed' => $changed], 'finished_at' => now()]);
            $this->audit->log('leave.reconciled', $run, ['employee_ids' => $ids, 'employees_changed' => $changed]);
        } catch (Throwable $exception) {
            $run->update(['status' => 'failed', 'finished_at' => now()]);

            throw $exception;
        }

        return $run->fresh();
    }

    private function replayEmployee(Company $company, Employee $employee, ProcessingRun $run): bool
    {
        $requests = LeaveRequest::query()->where('company_id', $company->id)->where('employee_id', $employee->id)
            ->with('leaveType')->orderBy('id')->get();
        $versions = EmployeeVersion::query()->where('company_id', $company->id)->where('employee_id', $employee->id)
            ->orderBy('effective_from')->orderBy('id')->get()
            ->map(fn (EmployeeVersion $version): array => [
                'id' => $version->id, 'effective_from' => $version->effective_from->toDateString(), 'changes' => $version->changes,
            ])->all();
        $policies = Policy::query()->where('company_id', $company->id)->orderBy('effective_from')->orderBy('version')->get()
            ->map(fn (Policy $policy): array => [
                'effective_from' => $policy->effective_from->toDateString(), 'version' => $policy->version,
                'office_rest_days' => $policy->office_rest_days,
            ])->all();
        $decisions = RequestDecision::query()->where('company_id', $company->id)->where('employee_id', $employee->id)
            ->orWhere(function ($query) use ($requests, $company): void {
                $query->where('company_id', $company->id)->whereIn('request_id', $requests->pluck('id'));
            })->orderBy('id')->get();
        $withdrawn = [];
        foreach ($decisions as $decision) {
            if ($decision->decision === 'withdraw') {
                $withdrawn[(int) ($decision->values['decision_id'] ?? 0)] = true;
            }
        }
        $active = $decisions->reject(fn (RequestDecision $decision): bool => $decision->decision === 'withdraw' || isset($withdrawn[$decision->id]));
        $byRequest = $active->groupBy('request_id');
        $blocked = [];
        foreach ($active as $decision) {
            if ($decision->decision === 'reverse_deduction') {
                $blocked[$decision->work_date->toDateString().'|'.$decision->target_kind] = true;
            }
        }
        $plannedDays = [];
        foreach ($requests as $request) {
            $requestDecisions = $byRequest->get($request->id, collect());
            if ($request->status !== 'approved' || ! $request->leaveType?->covers || ! $request->leaveType->is_mapped
                || $requestDecisions->contains('decision', 'cancel')) {
                continue;
            }
            $values = [
                'start_date' => $request->start_date?->toDateString(), 'end_date' => $request->end_date?->toDateString(),
                'duration_days' => $request->duration_days,
            ];
            foreach ($requestDecisions as $decision) {
                if ($decision->decision === 'fix_dates') {
                    $values = array_replace($values, $decision->values ?? []);
                }
            }
            if (in_array('invalid_dates', $request->flags ?? [], true)
                && ! $requestDecisions->contains('decision', 'fix_dates')) {
                continue;
            }
            $confirmation = $requestDecisions->last(fn (RequestDecision $decision): bool => $decision->decision === 'confirm_distribution')?->values;
            $distribution = $this->distributor->distribute($values, $versions, $policies, $confirmation);
            $mismatchKey = 'leave_duration_mismatch|leave_request|request:'.$request->id;
            if ($distribution['flag'] === 'duration_mismatch') {
                $this->issues->open($company, 'leave_duration_mismatch', 'leave_request', 'request:'.$request->id);
            } else {
                $mismatch = DataIssue::query()->where('company_id', $company->id)->where('open_key', $mismatchKey)->first();
                if ($mismatch !== null) {
                    $mismatch->update(['status' => 'resolved', 'open_key' => null,
                        'note' => __('messages.leave_reconciliation.distribution_resolved'), 'resolved_at' => now()]);
                    $this->audit->log('leave.issue.resolved', $mismatch, ['type' => $mismatch->type, 'subject_key' => $mismatch->subject_key]);
                }
            }
            if ($distribution['flag'] !== null) {
                continue;
            }
            foreach ($distribution['days'] as $day) {
                $plannedDays[] = [
                    'key' => $request->id.'|'.$day['work_date'], 'request_id' => $request->id,
                    'external_id' => $request->external_id, 'work_date' => $day['work_date'], 'minutes' => $day['minutes'],
                ];
            }
        }
        $days = Day::query()->with('policy')->where('company_id', $company->id)->where('employee_id', $employee->id)
            ->whereNull('superseded_by_run_id')->orderBy('work_date')->get();
        $targets = [];
        foreach ($days as $day) {
            $date = $day->work_date->toDateString();
            if ($day->status === 'absent') {
                $targets[] = ['day_id' => $day->id, 'work_date' => $date, 'kind' => 'absence',
                    'minutes' => (int) ($day->inputs['min_presence_minutes'] ?? $day->policy?->min_presence_minutes ?? 480)];
            } elseif ($day->status === 'present') {
                foreach (['late' => 'late_minutes', 'early' => 'early_minutes', 'exit' => 'exit_minutes'] as $kind => $column) {
                    if ($kind === 'exit' && ! $day->policy?->exits_count_toward_balance) {
                        continue;
                    }
                    if ((int) $day->{$column} > 0) {
                        $targets[] = ['day_id' => $day->id, 'work_date' => $date, 'kind' => $kind, 'minutes' => (int) $day->{$column}];
                    }
                }
            }
        }
        $oldDays = RequestDay::query()->where('company_id', $company->id)->where('employee_id', $employee->id)
            ->whereNull('superseded_by_run_id')->get();
        $oldByKey = $oldDays->keyBy(fn (RequestDay $day): string => $day->request_id.'|'.$day->work_date->toDateString());
        $sameDays = count($plannedDays) === $oldDays->count();
        foreach ($plannedDays as $day) {
            $sameDays = $sameDays && (int) ($oldByKey->get($day['key'])?->minutes ?? -1) === $day['minutes'];
        }
        $oldCredits = Credit::query()->where('company_id', $company->id)->where('employee_id', $employee->id)
            ->whereNull('superseded_by_run_id')->get();
        $oldAllocations = Allocation::query()->where('company_id', $company->id)->where('employee_id', $employee->id)
            ->whereNull('superseded_by_run_id')->get();
        $setting = Setting::query()->where('company_id', $company->id)->first();
        $expiry = $setting?->credit_expiry_days;
        $sourceDays = [];
        foreach ($plannedDays as $day) {
            $sourceDays[] = ['id' => $day['key'], 'external_id' => $day['external_id'],
                'work_date' => $day['work_date'], 'minutes' => $day['minutes']];
        }
        $targetlessPresentDates = $days->filter(fn (Day $day): bool => $day->status === 'present'
            && ! collect($targets)->contains('work_date', $day->work_date->toDateString()))
            ->map(fn (Day $day): string => $day->work_date->toDateString())->all();
        $creditEligibleDates = $days->filter(fn (Day $day): bool => in_array($day->status, ['present', 'absent'], true))
            ->map(fn (Day $day): string => $day->work_date->toDateString())->all();
        $plan = $this->allocator->allocate($sourceDays, $targets, $blocked, $expiry === null ? null : (int) $expiry,
            $targetlessPresentDates, $creditEligibleDates);
        $flagKeys = [];
        foreach ($plan['flags'] as $flag) {
            $key = 'employee:'.$employee->id.':request_day:'.$flag['request_day_id'];
            $flagKeys[] = 'leave_with_attendance_nothing_to_reconcile|leave_request_day|'.$key;
            $this->issues->open($company, $flag['code'], 'leave_request_day', $key);
        }
        DataIssue::query()->where('company_id', $company->id)->where('type', 'leave_with_attendance_nothing_to_reconcile')
            ->where('status', 'open')->where('subject_key', 'like', 'employee:'.$employee->id.':request_day:%')
            ->whereNotIn('open_key', $flagKeys)->get()->each(function (DataIssue $issue): void {
                $issue->update(['status' => 'resolved', 'open_key' => null,
                    'note' => __('messages.leave_reconciliation.issue_resolved'), 'resolved_at' => now()]);
                $this->audit->log('leave.issue.resolved', $issue, ['type' => $issue->type, 'subject_key' => $issue->subject_key]);
            });
        if ($sameDays && $this->sameLedger($plan, $oldCredits, $oldAllocations, $oldDays)) {
            return false;
        }
        $oldAllocations->each(fn (Allocation $allocation) => $allocation->update(['superseded_by_run_id' => $run->id]));
        $oldCredits->each(fn (Credit $credit) => $credit->update(['superseded_by_run_id' => $run->id]));
        if (! $sameDays) {
            $oldDays->each(fn (RequestDay $day) => $day->update(['superseded_by_run_id' => $run->id]));
        }
        $ids = [];
        foreach ($plannedDays as $day) {
            $ids[$day['key']] = $sameDays ? $oldByKey[$day['key']]->id : RequestDay::create([
                'company_id' => $company->id, 'employee_id' => $employee->id, 'request_id' => $day['request_id'],
                'work_date' => $day['work_date'], 'minutes' => $day['minutes'], 'run_id' => $run->id,
            ])->id;
        }
        $creditIds = [];
        foreach ($plan['credits'] as $credit) {
            $creditIds[$credit['source_day_id']] = Credit::create([
                'company_id' => $company->id, 'employee_id' => $employee->id,
                'source_day_id' => $ids[$credit['source_day_id']], 'run_id' => $run->id,
                'minutes' => $credit['minutes'], 'expires_on' => $credit['expires_on'],
            ])->id;
        }
        foreach ($plan['allocations'] as $allocation) {
            $created = Allocation::create([
                'company_id' => $company->id, 'employee_id' => $employee->id,
                'source_request_day_id' => $allocation['source_kind'] === 'same_day' ? $ids[$allocation['source_day_id']] : null,
                'source_credit_id' => $allocation['source_kind'] === 'credit' ? $creditIds[$allocation['source_day_id']] : null,
                'target_day_id' => $allocation['target_day_id'], 'target_kind' => $allocation['target_kind'],
                'minutes' => $allocation['minutes'], 'explanation' => $allocation['explanation'], 'run_id' => $run->id,
            ]);
            $this->audit->log('leave.allocation.created', $created, [
                'before' => [], 'after' => [
                    'source_kind' => $allocation['source_kind'], 'source_day_id' => $allocation['source_day_id'],
                    'target_day_id' => $allocation['target_day_id'], 'target_kind' => $allocation['target_kind'],
                    'minutes' => $allocation['minutes'], 'run_id' => $run->id,
                ],
            ]);
        }

        return true;
    }

    private function sameLedger(array $plan, $credits, $allocations, $days): bool
    {
        $dayKeys = $days->mapWithKeys(fn (RequestDay $day): array => [$day->id => $day->request_id.'|'.$day->work_date->toDateString()]);
        $creditKeys = $credits->mapWithKeys(fn (Credit $credit): array => [$credit->id => $dayKeys[$credit->source_day_id] ?? '']);
        $plannedCredits = array_map(fn (array $credit): array => [$credit['source_day_id'], $credit['minutes'], $credit['expires_on']], $plan['credits']);
        $existingCredits = $credits->map(fn (Credit $credit): array => [$dayKeys[$credit->source_day_id] ?? '', (int) $credit->minutes, $credit->expires_on?->toDateString()])->all();
        $planned = array_map(fn (array $allocation): array => [
            $allocation['source_kind'], $allocation['source_day_id'], $allocation['target_day_id'],
            $allocation['target_kind'], $allocation['minutes'],
        ], $plan['allocations']);
        $existing = $allocations->map(fn (Allocation $allocation): array => [
            $allocation->source_credit_id === null ? 'same_day' : 'credit',
            $allocation->source_credit_id === null ? ($dayKeys[$allocation->source_request_day_id] ?? '') : ($creditKeys[$allocation->source_credit_id] ?? ''),
            (int) $allocation->target_day_id, $allocation->target_kind, (int) $allocation->minutes,
        ])->all();
        sort($plannedCredits);
        sort($existingCredits);
        sort($planned);
        sort($existing);

        return $plannedCredits === $existingCredits && $planned === $existing;
    }
}
