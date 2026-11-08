<?php

namespace App\Services\Attendance\Reporting;

use App\Jobs\Attendance\AssessDaysJob;
use App\Jobs\Attendance\BuildMonthSummariesJob;
use App\Jobs\Attendance\ImportTransactionsJob;
use App\Jobs\Attendance\ProcessAttendanceJob;
use App\Jobs\ImportEmployeesJob;
use App\Jobs\Leave\ImportLeaveBalanceJob;
use App\Jobs\Leave\ImportLeaveJob;
use App\Jobs\Leave\ReconcileLeaveJob;
use App\Models\Attendance\PendingLeaveReconciliation;
use App\Models\Attendance\ProcessingRun as AttendanceRun;
use App\Models\Company;
use App\Models\Leave\ProcessingRun as LeaveRun;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ProcessingStatus
{
    public function for(Company $company): array
    {
        abort_unless(app(TenantContext::class)->companyId() === $company->id, 403);
        $pending = PendingLeaveReconciliation::query()->where('company_id', $company->id);
        $matching = (clone $pending)->count();
        $months = DB::table('attendance_month_summaries')->where('company_id', $company->id)->where('is_dirty', true)->count();
        // A run left 'processing' by a crashed worker must not show "calculating" forever.
        $recent = now()->subHours(2);
        $runs = AttendanceRun::query()->where('company_id', $company->id)->where('status', 'processing')
            ->where('started_at', '>=', $recent)->get(['kind', 'started_at']);
        $leaveRuns = LeaveRun::query()->where('company_id', $company->id)->where('status', 'processing')
            ->where('started_at', '>=', $recent)->get(['started_at']);
        $queued = $this->queued($company);
        $phase = match (true) {
            $queued['reading_files'] || $queued['employee_import'] => 'reading_files',
            $queued['calculating_days'] || $runs->whereIn('kind', ['cleanup', 'days'])->isNotEmpty() => 'calculating_days',
            $queued['matching_leave'] || $matching > 0 || $leaveRuns->isNotEmpty() => 'matching_leave',
            $queued['updating_totals'] || $months > 0 => 'updating_totals',
            default => null,
        };
        $started = collect([$queued['oldest'], $runs->min('started_at'), $leaveRuns->min('started_at'),
            (clone $pending)->min('created_at'), DB::table('attendance_month_summaries')->where('company_id', $company->id)
                ->where('is_dirty', true)->min('updated_at')])->filter()
            ->map(fn ($value): string => (string) $value)->min();
        $finished = collect([
            AttendanceRun::query()->where('company_id', $company->id)->where('status', 'completed')->max('finished_at'),
            LeaveRun::query()->where('company_id', $company->id)->where('status', 'completed')->max('finished_at'),
            DB::table('attendance_month_summaries')->where('company_id', $company->id)->max('built_at'),
        ])->filter()->max();

        return ['state' => $phase === null ? 'ready' : 'working', 'phase' => $phase,
            'step' => $phase === null ? null : array_search($phase, ['reading_files', 'calculating_days', 'matching_leave', 'updating_totals'], true) + 1,
            'employees_left' => $matching, 'months_left' => $months, 'started_at' => $started,
            'finished_at' => $phase === null ? $finished : null];
    }

    private function queued(Company $company): array
    {
        $result = ['reading_files' => false, 'employee_import' => false, 'calculating_days' => false,
            'matching_leave' => false, 'updating_totals' => false, 'oldest' => null];
        $connection = config('queue.default');
        if (config("queue.connections.{$connection}.driver") !== 'database') {
            return $result;
        }
        $batchIds = DB::table('attendance_import_batches')->where('company_id', $company->id)->pluck('id')->all();
        $leaveIds = DB::table('leave_import_batches')->where('company_id', $company->id)->pluck('id')->all();
        $balanceIds = DB::table('leave_balance_batches')->where('company_id', $company->id)->pluck('id')->all();
        $employeeRuns = DB::table('employee_import_runs')->where('company_id', $company->id)->pluck('id')->all();
        $classes = [
            ImportTransactionsJob::class => ['reading_files', 'batchId', $batchIds],
            ImportLeaveJob::class => ['reading_files', 'batchId', $leaveIds],
            ImportLeaveBalanceJob::class => ['reading_files', 'batchId', $balanceIds],
            ImportEmployeesJob::class => ['employee_import', 'runId', $employeeRuns],
            ProcessAttendanceJob::class => ['calculating_days', 'companyId', [$company->id]],
            AssessDaysJob::class => ['calculating_days', 'companyId', [$company->id]],
            ReconcileLeaveJob::class => ['matching_leave', 'companyId', [$company->id]],
            BuildMonthSummariesJob::class => ['updating_totals', 'companyId', [$company->id]],
        ];
        $table = config("queue.connections.{$connection}.table", 'jobs');
        $jobs = DB::table($table)->select('payload', 'created_at')->where(function ($query) use ($classes): void {
            foreach (array_keys($classes) as $class) {
                $query->orWhere('payload', 'like', '%'.class_basename($class).'%');
            }
        });
        foreach ($jobs->cursor() as $job) {
            $payload = json_decode($job->payload, true);
            $target = $classes[$payload['displayName'] ?? ''] ?? null;
            if ($target === null) {
                continue;
            }
            [$phase, $property, $ids] = $target;
            foreach ($ids as $id) {
                if (str_contains($payload['data']['command'] ?? '', 's:'.strlen($property).':"'.$property.'";i:'.$id.';')) {
                    $result[$phase] = true;
                    $created = CarbonImmutable::createFromTimestamp((int) $job->created_at, config('app.timezone'))->toDateTimeString();
                    $result['oldest'] = min($result['oldest'] ?? $created, $created);
                    break;
                }
            }
        }

        return $result;
    }
}
