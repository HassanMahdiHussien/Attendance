<?php

namespace App\Services\Leave;

use App\Models\Company;
use App\Models\Leave\DescriptionReading;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class LeaveDescriptionDecisions
{
    public function __construct(private AuditLogger $audit, private ReconciliationTriggers $triggers,
        private LeaveDescriptionEffects $effects) {}

    public function confirmAll(Company $company, array $ids, User $actor): int
    {
        abort_unless(app(TenantContext::class)->companyId() === $company->id && auth()->id() === $actor->id, 403);
        Gate::forUser($actor)->authorize('manageLeave', $company);
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return 0;
        }

        return DB::transaction(function () use ($company, $ids, $actor): int {
            $readings = DescriptionReading::query()->where('company_id', $company->id)
                ->whereIn('id', $ids)->where('status', 'proposed')->where('confidence', 'high')
                ->whereNotNull('category')->whereNull('error_code')->orderBy('id')->lockForUpdate()->get();
            abort_unless($readings->count() === count($ids), 409);
            abort_unless($readings->every(fn (DescriptionReading $reading): bool => $this->effects->reason(
                $reading->request, $reading) === null), 409);
            $employeeIds = [];
            foreach ($readings as $reading) {
                $reading->update(['status' => 'confirmed', 'decided_by' => $actor->id, 'decided_at' => now()]);
                if ($reading->request?->employee_id !== null) {
                    $employeeIds[] = $reading->request->employee_id;
                }
            }
            $this->audit->log('leave.description.bulk_confirmed', $company, [
                'reading_ids' => $ids, 'before' => 'proposed', 'after' => 'confirmed',
                'count' => count($ids),
            ], $actor);
            $this->triggers->dispatch($company->id, $employeeIds, 'description_decision');

            return count($ids);
        });
    }

    public function decide(DescriptionReading $reading, string $decision, User $actor): void
    {
        $company = Company::findOrFail($reading->company_id);
        abort_unless(app(TenantContext::class)->companyId() === $company->id && auth()->id() === $actor->id, 403);
        Gate::forUser($actor)->authorize('manageLeave', $company);
        abort_unless(in_array($decision, ['confirmed', 'rejected', 'proposed'], true), 422);
        DB::transaction(function () use ($reading, $decision, $actor, $company): void {
            $reading = DescriptionReading::query()->whereKey($reading->id)->lockForUpdate()->firstOrFail();
            abort_unless($reading->category !== null && $reading->error_code === null, 409);
            if ($decision === 'confirmed') {
                abort_unless($this->effects->reason($reading->request, $reading) === null, 409);
            }
            if ($reading->status === $decision) {
                return;
            }
            abort_unless($decision === 'proposed' ? in_array($reading->status, ['confirmed', 'rejected'], true)
                : $reading->status === 'proposed', 409);
            $before = $reading->status;
            $reading->update(['status' => $decision, 'decided_by' => $decision === 'proposed' ? null : $actor->id,
                'decided_at' => $decision === 'proposed' ? null : now()]);
            $this->audit->log('leave.description.decided', $reading, [
                'before' => $before, 'after' => $decision, 'category' => $reading->category,
                'request_id' => $reading->request_id,
            ], $actor);
            if ($before === 'confirmed' || $decision === 'confirmed'
                || ($reading->category === 'hourly_settlement' && in_array('proposed', [$before, $decision], true))) {
                $employeeId = $reading->request?->employee_id;
                if ($employeeId !== null) {
                    $this->triggers->dispatch($company->id, [$employeeId], 'description_decision');
                }
            }
        });
    }
}
