<?php

namespace App\Services\Leave;

use App\Models\Attendance\Day;
use App\Models\Employee;
use App\Models\Leave\Allocation;
use App\Models\Leave\LeaveRequest;
use App\Models\Leave\RequestDecision;
use App\Support\Tenancy\TenantContext;

class DayReconciliation
{
    public function for(Employee $employee, string $from, ?string $to = null): array
    {
        abort_unless(app(TenantContext::class)->companyId() === $employee->company_id, 403);
        $to ??= $from;
        $days = Day::query()->with('policy')->where('company_id', $employee->company_id)
            ->where('employee_id', $employee->id)->whereBetween('work_date', [$from, $to])
            ->whereNull('superseded_by_run_id')->orderBy('work_date')->get();
        $allocations = Allocation::query()->where('company_id', $employee->company_id)
            ->where('employee_id', $employee->id)->whereIn('target_day_id', $days->pluck('id'))
            ->whereNull('superseded_by_run_id')->get()->groupBy('target_day_id');
        $pending = LeaveRequest::query()->where('company_id', $employee->company_id)
            ->where('employee_id', $employee->id)->where('status', 'pending')
            ->where('start_date', '<=', $to)->where('end_date', '>=', $from)->get();
        $decisions = RequestDecision::query()->where('company_id', $employee->company_id)
            ->whereIn('request_id', $pending->pluck('id'))->orderBy('id')->get();
        $withdrawn = $decisions->where('decision', 'withdraw')
            ->map(fn (RequestDecision $decision): int => (int) ($decision->values['decision_id'] ?? 0))->all();
        $cancelled = $decisions->where('decision', 'cancel')->reject(fn (RequestDecision $decision): bool => in_array($decision->id, $withdrawn, true))->pluck('request_id')->all();
        $pending = $pending->reject(fn (LeaveRequest $request): bool => in_array($request->id, $cancelled, true));
        $result = [];
        foreach ($days as $day) {
            $date = $day->work_date->toDateString();
            $base = ['absence' => $day->status === 'absent' ? (int) ($day->inputs['absence_minutes'] ?? $day->policy?->absence_minutes ?? 480) : 0,
                'late' => $day->status === 'present' ? (int) $day->late_minutes : 0,
                'early' => $day->status === 'present' ? (int) $day->early_minutes : 0,
                'exit' => $day->status === 'present' && $day->policy?->exits_count_toward_balance ? (int) $day->exit_minutes : 0];
            $matched = array_fill_keys(LeaveAllocator::KINDS, 0);
            $explanations = [];
            $closedBy = null;
            $closedAt = null;
            foreach ($allocations->get($day->id, collect()) as $allocation) {
                $matched[$allocation->target_kind] += $allocation->minutes;
                $explanations[] = $allocation->explanation;
                if ($allocation->target_kind === 'absence' && $allocation->source_request_day_id !== null
                    && $allocation->minutes >= $base['absence']) {
                    $closedBy = $allocation->explanation['request'] ?? null;
                    $closedAt = $allocation->created_at?->format('Y-m-d H:i:s');
                }
            }
            $remaining = [];
            foreach ($base as $kind => $minutes) {
                $remaining[$kind] = max(0, $minutes - $matched[$kind]);
            }
            $result[$date] = [
                'day_id' => $day->id, 'status' => $day->status,
                'reconciled' => $matched, 'remaining' => $remaining,
                'absence_closed' => $day->status === 'absent' && $remaining['absence'] === 0,
                'closed_by_request' => $closedBy,
                'closed_at' => $closedAt,
                'pending_requests' => $pending->filter(fn (LeaveRequest $request): bool => $request->start_date->toDateString() <= $date && $request->end_date->toDateString() >= $date)
                    ->map(fn (LeaveRequest $request): array => [
                        'external_id' => $request->external_id, 'approver_name' => $request->approver_name,
                    ])->values()->all(),
                'explanations' => $explanations,
            ];
        }

        return $result;
    }
}
