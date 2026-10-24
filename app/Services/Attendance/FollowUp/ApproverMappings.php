<?php

namespace App\Services\Attendance\FollowUp;

use App\Models\Attendance\ApproverMapping;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Leave\LeaveRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ApproverMappings
{
    public function __construct(private AuditLogger $audit, private CaseSync $cases) {}

    public static function key(string $text): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text)));
    }

    public function suggest(Company $company, string $text): array
    {
        $this->assertCompany($company);
        $key = self::key($text);

        return Employee::query()->where('company_id', $company->id)->get()
            ->filter(fn (Employee $employee): bool => self::key($employee->name) === $key
                || ($employee->name_en !== null && self::key($employee->name_en) === $key))
            ->values()->all();
    }

    public function unmapped(Company $company): array
    {
        $this->assertCompany($company);
        $mapped = ApproverMapping::query()->where('company_id', $company->id)->pluck('approver_key')->flip();

        return LeaveRequest::query()->where('company_id', $company->id)->where('status', 'pending')
            ->pluck('approver_name')->filter()->unique()
            ->reject(fn (string $text): bool => $mapped->has(self::key($text)))->values()->all();
    }

    public function confirm(Company $company, string $text, Employee $employee, User $actor): ApproverMapping
    {
        $this->authorize($company, $actor);
        $key = self::key($text);
        if ($key === '' || mb_strlen($key) > 255 || (int) $employee->company_id !== (int) $company->id) {
            throw ValidationException::withMessages(['approver' => __('messages.follow_up.invalid_approver')]);
        }
        $mapping = DB::transaction(function () use ($company, $text, $key, $employee, $actor): ApproverMapping {
            Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
            $mapping = ApproverMapping::query()->where('approver_key', $key)->lockForUpdate()->first();
            $before = $mapping?->only(['employee_id']);
            if ($mapping === null) {
                $mapping = ApproverMapping::create([
                    'company_id' => $company->id, 'approver_key' => $key,
                    'approver_text' => trim($text), 'employee_id' => $employee->id,
                    'confirmed_by' => $actor->id, 'confirmed_at' => now(),
                ]);
            } elseif ((int) $mapping->employee_id !== (int) $employee->id || $mapping->approver_text !== trim($text)) {
                $mapping->update(['approver_text' => trim($text), 'employee_id' => $employee->id,
                    'confirmed_by' => $actor->id, 'confirmed_at' => now()]);
            } else {
                return $mapping;
            }
            $this->audit->log('attendance.approver_mapping.confirmed', $mapping, [
                'before' => $before, 'after' => $mapping->only(['employee_id']),
            ], $actor);

            return $mapping;
        });
        $this->cases->rerouteApprover($key);

        return $mapping;
    }

    private function authorize(Company $company, User $actor): void
    {
        abort_unless(auth()->id() === $actor->id, 403);
        $this->assertCompany($company);
        Gate::forUser($actor)->authorize('sendAttendanceNotifications', $company);
    }

    private function assertCompany(Company $company): void
    {
        abort_unless(app(TenantContext::class)->companyId() === $company->id, 403);
    }
}
