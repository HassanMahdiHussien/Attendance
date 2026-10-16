<?php

use App\Enums\Jurisdiction;
use App\Enums\PoolRole;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\BranchUser;
use App\Models\CommitteeMandate;
use App\Models\CommitteePoolMember;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Law;
use App\Models\LegalUnit;
use App\Models\Location;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\LocalUsersSeeder;

it('seeds the anonymised pilot structure and built-in laws', function () {
    config()->set('app.seed_default_password', 'local-test-password');
    $this->seed(DatabaseSeeder::class);

    app(TenantContext::class)->runAcrossAllCompanies(function (): void {
        $company = Company::sole();
        expect($company->name)->toBe('شركة الواحة للمشروبات الغازية')
            ->and($company->enabled_languages)->toBe(['ar', 'en']);

        $branch = Branch::sole();
        expect($branch->company_id)->toBe($company->id)
            ->and($branch->name)->toBe('الحلة')
            ->and($branch->jurisdiction)->toBe(Jurisdiction::FederalIraq)
            ->and(Location::count())->toBe(4)
            ->and(Employee::count())->toBe(12)
            ->and(Employee::where('is_general_manager', true)->count())->toBe(1);

        expect(Employee::orderBy('id')->pluck('employee_code')->all())
            ->toBe(array_map(fn (int $number): string => sprintf('EMP-%04d', $number), range(1, 12)));

        $mandate = CommitteeMandate::sole();
        expect($mandate->order_number)->toBe('1097')
            ->and($mandate->order_date)->toBe('2026-08-26')
            ->and($mandate->effective_from)->toBe('2026-08-26')
            ->and(CommitteePoolMember::count())->toBe(9)
            ->and(CommitteePoolMember::where('role_type', PoolRole::Hr)->count())->toBe(5)
            ->and(CommitteePoolMember::where('role_type', PoolRole::Legal)->count())->toBe(2)
            ->and(CommitteePoolMember::where('role_type', PoolRole::LaborUnion)->count())->toBe(2)
            ->and(CommitteePoolMember::whereNotNull('branch_id')->count())->toBe(0)
            ->and(CommitteePoolMember::whereNotNull('employee_id')->count())->toBe(9);

        expect(Law::count())->toBe(3)
            ->and(LegalUnit::count())->toBe(0);
        $jurisdictions = Law::with('jurisdictions')->get()->keyBy('number');
        expect($jurisdictions['37']->jurisdictions->pluck('jurisdiction')->all())->toBe([Jurisdiction::FederalIraq])
            ->and($jurisdictions['37']->source_file_path)->toBe('docs/laws/labor-law-37-2015-gazette-4386.pdf')
            ->and($jurisdictions['71']->jurisdictions->pluck('jurisdiction')->all())->toBe([Jurisdiction::KurdistanRegion])
            ->and($jurisdictions['71']->source_file_path)->toBe('docs/laws/labor-law-71-1987.pdf')
            ->and($jurisdictions['18']->source_file_path)->toBe('docs/laws/gazette-4734-incl-social-security-law-18-2023.pdf')
            ->and($jurisdictions['18']->jurisdictions->pluck('jurisdiction')->all())->toEqualCanonicalizing([Jurisdiction::FederalIraq, Jurisdiction::KurdistanRegion]);

        // Platform admin, company admin and a branch-scoped reporter, plus one demo approver per pool category.
        $reporter = User::where('primary_role', UserRole::Reporter)->sole();
        expect(User::count())->toBe(6)
            ->and($reporter->company_id)->toBe($company->id)
            ->and(BranchUser::where('user_id', $reporter->id)->count())->toBe(1)
            ->and(User::where('primary_role', UserRole::CommitteeMember)->count())->toBe(3)
            ->and(User::where('primary_role', UserRole::PlatformAdmin)->sole()->company_id)->toBeNull()
            ->and(User::where('primary_role', UserRole::CompanyAdmin)->sole()->company_id)->toBe($company->id);

        $realNames = [
            'صباح صالح شاكر', 'حسن مهدي حسين', 'هيا سعد حسين', 'جيلان مصطفى امين',
            'بان جمال حميد', 'علاء باسل باقر', 'بارق منتظر عبد الوهاب',
            'بشار سعيد خضير', 'امير عمران موسى', 'اسماعيل جنك جيغري',
        ];
        $seededNames = Employee::pluck('name')->merge(CommitteePoolMember::pluck('person_name'))->merge(User::pluck('name'))->all();
        foreach ($realNames as $name) {
            expect($seededNames)->not->toContain($name);
        }
    });
});

it('refuses to seed local users without a password', function () {
    config()->set('app.seed_default_password', '');

    expect(fn () => $this->seed(LocalUsersSeeder::class))
        ->toThrow(RuntimeException::class, 'SEED_DEFAULT_PASSWORD');
});
