<?php

namespace App\Services\Leave;

use App\Jobs\Attendance\AssessDaysJob;
use App\Jobs\Attendance\ImportTransactionsJob;
use App\Jobs\Attendance\ProcessAttendanceJob;
use App\Jobs\ImportEmployeesJob;
use App\Jobs\Leave\ImportLeaveBalanceJob;
use App\Jobs\Leave\ImportLeaveJob;
use App\Jobs\Leave\ReconcileLeaveJob;
use App\Models\Attendance\PendingLeaveReconciliation;
use App\Models\Attendance\ProcessingRun as AttendanceRun;
use App\Models\Company;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PendingReconciliation
{
    /** Deferring needs a queue that honours delays; the sync queue would loop on its own re-dispatch. */
    public static function defers(): bool
    {
        return config('queue.connections.'.config('queue.default').'.driver') === 'database';
    }

    public function collect(int $companyId, array $ids, string $from, string $to): void
    {
        abort_unless(app(TenantContext::class)->companyId() === $companyId, 403);
        if (! self::defers()) {
            app(ReconciliationTriggers::class)->dispatch($companyId, $ids, 'days', $from, $to);

            return;
        }
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            DB::statement('INSERT INTO attendance_pending_leave_reconciliations (company_id, employee_id, from_date, to_date, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE from_date = LEAST(from_date, VALUES(from_date)), to_date = GREATEST(to_date, VALUES(to_date)), revision = revision + 1, updated_at = NOW()', [$companyId, $id, $from, $to]);
        }
        if ($ids !== []) {
            foreach (range(0, max(1, (int) config('attendance.leave_matching_slots', 3)) - 1) as $slot) {
                ReconcileLeaveJob::dispatch($companyId, [], 'days', null, null, $slot)
                    ->delay(now()->addSeconds(10))->afterCommit();
            }
        }
    }

    public function hasUpstreamWork(Company $company): bool
    {
        if (! self::defers()) {
            return false;
        }
        // A run left 'processing' by a crashed worker must not hold leave matching back forever.
        if (AttendanceRun::query()->where('company_id', $company->id)->where('status', 'processing')
            ->where('started_at', '>=', now()->subHours(2))->exists()) {
            return true;
        }
        $connection = config('queue.default');
        if (config("queue.connections.{$connection}.driver") !== 'database') {
            return false;
        }
        $batchIds = DB::table('attendance_import_batches')->where('company_id', $company->id)->pluck('id')->all();
        $leaveBatchIds = DB::table('leave_import_batches')->where('company_id', $company->id)->pluck('id')->all();
        $balanceBatchIds = DB::table('leave_balance_batches')->where('company_id', $company->id)->pluck('id')->all();
        $employeeRunIds = DB::table('employee_import_runs')->where('company_id', $company->id)->pluck('id')->all();
        $classes = [
            ImportTransactionsJob::class => ['batchId', $batchIds],
            ImportLeaveJob::class => ['batchId', $leaveBatchIds],
            ImportLeaveBalanceJob::class => ['batchId', $balanceBatchIds],
            ImportEmployeesJob::class => ['runId', $employeeRunIds],
            ProcessAttendanceJob::class => ['companyId', [$company->id]],
            AssessDaysJob::class => ['companyId', [$company->id]],
        ];
        $table = config("queue.connections.{$connection}.table", 'jobs');
        $jobs = DB::table($table)->select('payload')->whereIn('queue', ['imports', 'assessments'])
            ->where(function ($query) use ($classes): void {
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
            [$property, $ids] = $target;
            foreach ($ids as $id) {
                if (str_contains($payload['data']['command'] ?? '', 's:'.strlen($property).':"'.$property.'";i:'.$id.';')) {
                    return true;
                }
            }
        }

        return false;
    }

    public function run(Company $company, Reconciler $reconciler, ReconcileLeaveJob $job): void
    {
        abort_unless(app(TenantContext::class)->companyId() === $company->id, 403);
        if ($this->hasUpstreamWork($company)) {
            ReconcileLeaveJob::dispatch($company->id, [], 'days', null, null, $job->slot)
                ->delay(now()->addSeconds(10))->afterCommit();

            return;
        }
        $token = (string) Str::uuid();
        DB::update('UPDATE attendance_pending_leave_reconciliations SET claim_token = ?, claimed_at = NOW() WHERE company_id = ? AND (claim_token IS NULL OR claimed_at < ?) ORDER BY id LIMIT 10',
            [$token, $company->id, now()->subMinutes(15)]);
        $rows = PendingLeaveReconciliation::query()->where('company_id', $company->id)
            ->where('claim_token', $token)->orderBy('id')->get();
        if ($rows->isEmpty()) {
            if (PendingLeaveReconciliation::query()->where('company_id', $company->id)->exists()) {
                ReconcileLeaveJob::dispatch($company->id, [], 'days', null, null, $job->slot)
                    ->delay(now()->addMinutes(1))->afterCommit();
            }

            return;
        }
        $reconciler->run($company, $rows->pluck('employee_id')->all(), 'days',
            $rows->min('from_date'), $rows->max('to_date'));
        // A row merged again while the replay ran keeps its newer revision and is replayed next time.
        foreach ($rows as $row) {
            PendingLeaveReconciliation::query()->where('company_id', $company->id)->whereKey($row->id)
                ->where('revision', $row->revision)->where('claim_token', $token)->delete();
            PendingLeaveReconciliation::query()->where('company_id', $company->id)->whereKey($row->id)
                ->where('claim_token', $token)->update(['claim_token' => null, 'claimed_at' => null]);
        }
        $remaining = PendingLeaveReconciliation::query()->where('company_id', $company->id);
        if ((clone $remaining)->whereNull('claim_token')->exists()) {
            ReconcileLeaveJob::dispatch($company->id, [], 'days', null, null, $job->slot)->afterCommit();
        } elseif ($remaining->exists()) {
            // A claimed row can become stale after its worker dies; keep a slot available to recover it.
            ReconcileLeaveJob::dispatch($company->id, [], 'days', null, null, $job->slot)
                ->delay(now()->addMinutes(15))->afterCommit();
        }
    }
}
