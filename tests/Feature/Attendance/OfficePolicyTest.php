<?php

use App\Enums\UserRole;
use App\Exceptions\ImmutableRecordException;
use App\Models\Attendance\Policy;
use App\Models\Company;
use App\Models\User;
use App\Services\Attendance\OfficePolicy;
use App\Services\CompanyOnboardingService;
use App\Support\Tenancy\TenantContext;

test('company onboarding creates a default dated office policy', function (): void {
    $actor = User::factory()->create(['primary_role' => UserRole::PlatformAdmin]);
    $company = app(CompanyOnboardingService::class)->create(
        ['name' => 'Office test company'],
        ['name' => 'HR user', 'email' => 'office-hr@example.test', 'password' => 'secret12345'],
        $actor,
    );

    app(TenantContext::class)->runForCompany($company, function () use ($company): void {
        $policy = app(OfficePolicy::class)->asOf($company, today());

        expect($policy)->not->toBeNull()
            ->and($policy->version)->toBe(1)
            ->and($policy->office_start)->toBe('08:00:00')
            ->and($policy->office_rest_days)->toBe([5, 6])
            ->and($policy->exits_count_toward_balance)->toBeFalse();
    });
});

test('same-date policy versions use the higher company version and cannot be updated', function (): void {
    $company = Company::factory()->create();
    $actor = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::HrStaff]);

    app(TenantContext::class)->runForCompany($company, function () use ($company, $actor): void {
        $service = app(OfficePolicy::class);
        $baseline = $service->createDefault($company);
        $first = $service->add($company, ['flex_minutes' => 30, 'workday_minutes' => 600], '2026-10-01', $actor, 'First change');
        $second = $service->add($company, ['flex_minutes' => 45], '2026-10-01', $actor, 'Correction');

        expect($service->asOf($company, '2026-10-01')->id)->toBe($second->id)
            ->and($first->version)->toBe($baseline->version + 1)
            ->and($second->version)->toBe($first->version + 1)
            ->and($second->workday_minutes)->toBe(600)
            ->and(Policy::query()->where('company_id', $company->id)->count())->toBe(3);
        expect(fn () => $first->update(['flex_minutes' => 90]))->toThrow(ImmutableRecordException::class);
    });
});
