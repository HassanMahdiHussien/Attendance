<?php

namespace App\Services\Leave;

use App\Models\Attendance\DataIssue;
use App\Models\Company;
use App\Models\Leave\LeaveRequest;
use App\Models\Leave\LeaveType;
use App\Models\User;
use App\Services\Attendance\DataIssues;
use App\Services\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class LeaveTypes
{
    public function __construct(private DataIssues $issues, private AuditLogger $audit, private ReconciliationTriggers $triggers) {}

    public function map(LeaveType $type, bool $covers, string $consumes, string $reason, User $actor): LeaveType
    {
        $company = Company::findOrFail($type->company_id);
        abort_unless(auth()->id() === $actor->id && app(TenantContext::class)->companyId() === $company->id, 403);
        Gate::forUser($actor)->authorize('manageLeave', $company);
        if (trim($reason) === '' || ! in_array($consumes, ['none', 'rest_day_entitlement', 'overtime_or_credit'], true)) {
            throw ValidationException::withMessages(['type' => __('messages.leave_reconciliation.invalid_mapping')]);
        }

        return DB::transaction(function () use ($type, $covers, $consumes, $reason, $actor, $company): LeaveType {
            $type = LeaveType::query()->whereKey($type->id)->lockForUpdate()->firstOrFail();
            $before = $type->only(['covers', 'consumes', 'is_mapped']);
            $type->update(['covers' => $covers, 'consumes' => $consumes, 'is_mapped' => true, 'mapped_by' => $actor->id]);
            $issue = DataIssue::query()->where('company_id', $company->id)
                ->where('open_key', 'leave_type_unmapped|leave_type|type:'.$type->id)->first();
            if ($issue !== null) {
                $this->issues->resolve($issue, $actor, $reason);
            }
            $this->audit->log('leave.type.mapped', $type, ['before' => $before,
                'after' => $type->only(['covers', 'consumes', 'is_mapped']), 'reason' => $reason], $actor);
            $ids = LeaveRequest::query()->where('company_id', $company->id)->where('leave_type_id', $type->id)
                ->whereNotNull('employee_id')->distinct()->pluck('employee_id')->all();
            $this->triggers->dispatch($company->id, $ids, 'type_mapping');

            return $type;
        });
    }

    public function key(string $name, ?string $otherName): string
    {
        return mb_strtolower(trim($name)).'|'.mb_strtolower(trim($otherName ?? ''));
    }

    public function findOrSeed(Company $company, string $name, ?string $otherName): LeaveType
    {
        abort_unless(app(TenantContext::class)->companyId() === $company->id, 403);
        $key = $this->key($name, $otherName);
        $default = $this->suggested($key);
        DB::table('leave_types')->insertOrIgnore([
            'company_id' => $company->id, 'name' => $name, 'other_name' => $otherName,
            'normalized_key' => $key, 'covers' => $default['covers'],
            'consumes' => $default['consumes'], 'is_mapped' => $default['mapped'],
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $type = LeaveType::query()->where('company_id', $company->id)->where('normalized_key', $key)->firstOrFail();
        if (! $type->is_mapped) {
            $this->issues->open($company, 'leave_type_unmapped', 'leave_type', 'type:'.$type->id);
        }

        return $type;
    }

    public function suggested(string $key): array
    {
        if ($key === 'compensation leave|') {
            return ['covers' => true, 'consumes' => 'rest_day_entitlement', 'mapped' => true];
        }
        if ($key === 'overtime equivelant leave|') {
            return ['covers' => true, 'consumes' => 'overtime_or_credit', 'mapped' => true];
        }
        $covers = in_array($key, [
            'annual leave|', 'sick leave|', 'work from home|', 'business travel request|',
            'administrative leave|', 'paternity leave|', 'funeral leave|',
            'bereavement leave|', 'marriage leave|', 'other|unpaid leave',
        ], true) || str_contains($key, 'court');

        return ['covers' => $covers, 'consumes' => 'none', 'mapped' => $covers];
    }
}
