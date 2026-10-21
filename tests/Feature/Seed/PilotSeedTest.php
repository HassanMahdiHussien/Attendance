<?php

use App\Enums\Jurisdiction;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Location;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\LocalUsersSeeder;

it('seeds the anonymised pilot foundation', function () {
    config()->set('app.seed_default_password', 'local-test-password');
    $this->seed(DatabaseSeeder::class);

    app(TenantContext::class)->runAcrossAllCompanies(function (): void {
        $company = Company::sole();
        expect($company->enabled_languages)->toBe(['ar', 'en']);

        $branch = Branch::sole();
        expect($branch->company_id)->toBe($company->id)
            ->and($branch->jurisdiction)->toBe(Jurisdiction::FederalIraq)
            ->and(Location::count())->toBe(4)
            ->and(Employee::count())->toBe(12);

        expect(Employee::orderBy('id')->pluck('employee_code')->all())
            ->toBe(array_map(fn (int $number): string => sprintf('EMP-%04d', $number), range(1, 12)));
        expect(User::where('primary_role', UserRole::PlatformAdmin)->sole()->company_id)->toBeNull();
        expect(User::where('primary_role', UserRole::HrStaff)->sole()->company_id)->toBe($company->id);
    });
});

it('refuses to seed local users without a password', function () {
    config()->set('app.seed_default_password', '');

    expect(fn () => $this->seed(LocalUsersSeeder::class))
        ->toThrow(RuntimeException::class, 'SEED_DEFAULT_PASSWORD');
});
