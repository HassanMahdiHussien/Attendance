<?php

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Location;
use App\Models\User;
use App\Services\CompanyOnboardingService;
use App\Services\CompanyStatusService;
use App\Services\UserAccountService;
use App\Support\Tenancy\TenantContext;

test('platform and company foundation actions have attributed, secret-free audit payloads', function (): void {
    $platformAdmin = User::factory()->create();
    $this->actingAs($platformAdmin);
    app(TenantContext::class)->setAllCompanies();

    $company = app(CompanyOnboardingService::class)->create(
        ['name' => 'Audit company', 'subscription_status' => 'trial'],
        ['name' => 'First admin', 'email' => 'audit-admin@example.test', 'password' => 'secret12345'],
        $platformAdmin,
    );
    $hrUser = User::query()->where('company_id', $company->id)->firstOrFail();
    app(CompanyStatusService::class)->setActive($company, false, $platformAdmin);
    app(CompanyStatusService::class)->setActive($company, true, $platformAdmin);
    $company->update(['name' => 'Updated audit company']);

    foreach (['company.created', 'company.deactivated', 'company.activated', 'company.updated'] as $action) {
        expect(AuditLog::query()->where('action', $action)->where('entity_id', $company->id)
            ->where('actor_user_id', $platformAdmin->id)->exists())->toBeTrue();
    }

    app(TenantContext::class)->runForCompany($company, function () use ($hrUser, $company): void {
        $this->actingAs($hrUser);
        $branch = Branch::factory()->create();
        $location = Location::factory()->create(['branch_id' => $branch->id]);
        $employee = Employee::factory()->create(['branch_id' => $branch->id]);
        $branch->update(['name' => 'Updated branch']);
        $location->update(['name' => 'Updated location']);
        $employee->update(['position' => 'Updated position']);
        $account = app(UserAccountService::class)->create([
            'company_id' => $company->id,
            'name' => 'Staff member',
            'email' => 'audit-staff@example.test',
            'password' => 'secret12345',
            'primary_role' => UserRole::HrStaff->value,
        ], $hrUser);
        app(UserAccountService::class)->update($account, ['is_active' => false], $hrUser);

        foreach ([
            ['branch.created', $branch->id],
            ['branch.updated', $branch->id],
            ['location.created', $location->id],
            ['location.updated', $location->id],
            ['employee.created', $employee->id],
            ['employee.updated', $employee->id],
            ['user.created', $account->id],
            ['user.deactivated', $account->id],
        ] as [$action, $entityId]) {
            $entry = AuditLog::query()->where('action', $action)->where('entity_id', $entityId)->sole();
            expect($entry->actor_user_id)->toBe($hrUser->id);
        }
    });

    foreach (AuditLog::query()->whereNotNull('actor_user_id')->get() as $entry) {
        $payload = $entry->metadata;
        expect(json_encode($payload, JSON_THROW_ON_ERROR))->not->toContain('"password"');
        if (str_ends_with($entry->action, '.created') || str_ends_with($entry->action, '.updated')
            || in_array($entry->action, ['company.deactivated', 'company.activated', 'user.deactivated'], true)) {
            expect($payload)->toHaveKeys(['before', 'after']);
        }
    }
});
