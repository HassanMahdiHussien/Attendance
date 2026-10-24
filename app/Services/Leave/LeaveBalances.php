<?php

namespace App\Services\Leave;

use App\Models\Employee;
use App\Models\Leave\Balance;
use App\Models\Leave\BalanceBatch;
use App\Models\Leave\BalanceRow;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

class LeaveBalances
{
    public function current(Employee $employee): ?Balance
    {
        $this->assertTenant($employee);

        return $this->completed($employee)->orderByDesc('balance_date')->orderByDesc('batch_id')->first();
    }

    public function history(Employee $employee): array
    {
        $this->assertTenant($employee);
        $previous = null;
        $history = [];
        foreach ($this->completed($employee)->with('batch')->orderBy('balance_date')->orderBy('batch_id')->get() as $balance) {
            $history[] = ['balance' => $balance, 'change_days' => $previous === null
                ? null : number_format((float) $balance->remaining_days - (float) $previous->remaining_days, 4, '.', '')];
            $previous = $balance;
        }

        return array_reverse($history);
    }

    public function presentInLatestUpload(Employee $employee): bool
    {
        $this->assertTenant($employee);
        $latest = BalanceBatch::query()->where('company_id', $employee->company_id)
            ->whereIn('id', $this->completedBatchIds($employee->company_id))->orderByDesc('id')->value('id');
        if ($latest === null) {
            return false;
        }

        return BalanceRow::query()->where('batch_id', $latest)->where('employee_id', $employee->id)
            ->where('outcome', 'imported')->exists();
    }

    private function completed(Employee $employee): Builder
    {
        return Balance::query()->where('employee_id', $employee->id)
            ->whereIn('batch_id', $this->completedBatchIds($employee->company_id));
    }

    private function completedBatchIds(int $companyId): QueryBuilder
    {
        return DB::table('leave_balance_batch_events as events')->where('events.company_id', $companyId)
            ->where('events.state', 'completed')->whereRaw('events.sequence = (SELECT MAX(next_events.sequence) FROM leave_balance_batch_events AS next_events WHERE next_events.company_id = events.company_id AND next_events.batch_id = events.batch_id)')
            ->select('events.batch_id');
    }

    private function assertTenant(Employee $employee): void
    {
        abort_unless(app(TenantContext::class)->companyId() === (int) $employee->company_id, 403);
    }
}
