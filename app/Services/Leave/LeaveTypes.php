<?php

namespace App\Services\Leave;

use App\Models\Company;
use App\Models\Leave\LeaveType;
use App\Services\Attendance\DataIssues;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

class LeaveTypes
{
    public function __construct(private DataIssues $issues) {}

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
