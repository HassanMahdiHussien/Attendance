<?php

namespace App\Services\Attendance;

use App\Models\Attendance\Policy;
use App\Models\Company;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class OfficePolicy
{
    // The first version covers all history: HR imports months of punches from before the company joined.
    public const DEFAULT_EFFECTIVE_FROM = '2000-01-01';

    public const DEFAULTS = [
        'office_start' => '08:00:00',
        'flex_minutes' => 60,
        'workday_minutes' => 540,
        'min_presence_minutes' => 480,
        'close_grace_minutes' => 120,
        'exits_count_toward_balance' => false,
        'office_rest_days' => [5, 6],
    ];

    public function __construct(private AuditLogger $audit) {}

    public function asOf(Company $company, CarbonInterface|string $date): ?Policy
    {
        $this->assertCompany($company);

        return Policy::query()->where('company_id', $company->id)
            ->whereDate('effective_from', '<=', $this->date($date))
            ->orderByDesc('effective_from')->orderByDesc('version')->first();
    }

    public function createDefault(Company $company, ?User $actor = null): Policy
    {
        $this->assertCompany($company);

        return DB::transaction(function () use ($company, $actor): Policy {
            Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
            $existing = Policy::query()->where('company_id', $company->id)->first();
            if ($existing !== null) {
                return $existing;
            }

            $policy = Policy::create(self::DEFAULTS + [
                'company_id' => $company->id,
                'effective_from' => self::DEFAULT_EFFECTIVE_FROM,
                'version' => 1,
            ]);
            $this->audit->log('attendance.policy.created', $policy, [
                'version' => 1, 'effective_from' => $policy->effective_from->toDateString(),
                'before' => [], 'after' => self::DEFAULTS,
            ], $actor);

            return $policy;
        });
    }

    public function add(Company $company, array $values, CarbonInterface|string $effectiveFrom, User $actor, string $reason): Policy
    {
        $this->assertCompany($company);
        Gate::forUser($actor)->authorize('update', $company);
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => __('messages.office_policy.reason_required')]);
        }

        return DB::transaction(function () use ($company, $values, $effectiveFrom, $actor, $reason): Policy {
            Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
            $effectiveDate = $this->date($effectiveFrom);
            $current = $this->asOf($company, $effectiveDate);
            $values = array_replace(
                self::DEFAULTS,
                $current === null ? [] : array_intersect_key($current->toArray(), self::DEFAULTS),
                array_intersect_key($values, self::DEFAULTS),
            );
            $this->validate($values);
            $version = (int) Policy::query()->where('company_id', $company->id)->max('version') + 1;
            $policy = Policy::create($values + [
                'company_id' => $company->id, 'effective_from' => $effectiveDate,
                'version' => $version, 'created_by' => $actor->id, 'reason' => $reason,
            ]);
            $this->audit->log('attendance.policy.created', $policy, [
                'version' => $version, 'effective_from' => $policy->effective_from->toDateString(),
                'before' => $current === null ? [] : array_intersect_key($current->toArray(), self::DEFAULTS),
                'after' => $values, 'reason' => $reason,
            ], $actor);
            DB::afterCommit(fn () => app(AssessmentTriggers::class)->policyAdded($policy));

            return $policy;
        });
    }

    private function assertCompany(Company $company): void
    {
        abort_unless(app(TenantContext::class)->companyId() === $company->id, 403);
    }

    private function date(CarbonInterface|string $date): string
    {
        return $date instanceof CarbonInterface ? $date->toDateString() : substr($date, 0, 10);
    }

    private function validate(array $values): void
    {
        $days = $values['office_rest_days'] ?? null;
        if (! is_array($days) || array_diff($days, range(1, 7)) !== [] || count($days) !== count(array_unique($days))
            || ! preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/', (string) ($values['office_start'] ?? ''))
            || ! is_numeric($values['workday_minutes']) || (int) $values['workday_minutes'] <= 0 || (int) $values['workday_minutes'] > 1440
            || ! is_numeric($values['min_presence_minutes']) || (int) $values['min_presence_minutes'] <= 0
            || (int) $values['min_presence_minutes'] > (int) $values['workday_minutes']
            || ! is_numeric($values['flex_minutes']) || (int) $values['flex_minutes'] < 0
            || (int) $values['flex_minutes'] > (int) $values['workday_minutes']
            || ! is_numeric($values['close_grace_minutes']) || (int) $values['close_grace_minutes'] < 0
            || (int) $values['close_grace_minutes'] > 1440) {
            throw ValidationException::withMessages(['policy' => __('messages.office_policy.invalid')]);
        }
    }
}
