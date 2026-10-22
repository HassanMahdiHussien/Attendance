<?php

use App\Exceptions\TenantMismatch;
use App\Models\Branch;
use App\Models\BranchUser;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeImportBatch;
use App\Models\EmployeeVersion;
use App\Models\Location;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

test('every kept composite tenant foreign key rejects another company through models and raw SQL', function (): void {
    $models = [
        'branches' => Branch::class,
        'locations' => Location::class,
        'employees' => Employee::class,
        'employee_import_batches' => EmployeeImportBatch::class,
        'employee_versions' => EmployeeVersion::class,
        'branch_user' => BranchUser::class,
        'users' => User::class,
    ];
    $keys = DB::select("SELECT TABLE_NAME AS source_table, REFERENCED_TABLE_NAME AS target_table, MAX(CASE WHEN COLUMN_NAME <> 'company_id' THEN COLUMN_NAME END) AS foreign_column FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL GROUP BY TABLE_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME HAVING COUNT(*) = 2 AND SUM(COLUMN_NAME = 'company_id') = 1");
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    $context = app(TenantContext::class);
    $checked = 0;
    $make = fn (string $class, Company $company) => $context->runForCompany($company, function () use ($class, $company) {
        if ($class === User::class) {
            return User::factory()->create(['company_id' => $company->id, 'primary_role' => 'hr_staff']);
        }
        if ($class === BranchUser::class) {
            return BranchUser::factory()->create([
                'company_id' => $company->id,
                'branch_id' => Branch::factory()->create(['company_id' => $company->id])->id,
                'user_id' => User::factory()->create(['company_id' => $company->id, 'primary_role' => 'hr_staff'])->id,
            ]);
        }

        return $class::factory()->create();
    });

    foreach ($keys as $key) {
        if (! isset($models[$key->source_table], $models[$key->target_table])) {
            continue;
        }
        $sourceClass = $models[$key->source_table];
        $targetClass = $models[$key->target_table];
        $source = $make($sourceClass, $a);
        $target = $make($targetClass, $b);
        $attributes = $source->getAttributes();
        unset($attributes['id']);
        $attributes[$key->foreign_column] = $target->id;
        if (isset($attributes['employee_code'])) {
            $attributes['employee_code'] = 'CROSS-'.$target->id;
        }
        if (isset($attributes['email'])) {
            $attributes['email'] = 'cross-'.$target->id.'@example.test';
        }

        $context->runForCompany($a, function () use ($sourceClass, $attributes): void {
            expect(fn () => $sourceClass::forceCreate($attributes))->toThrow(TenantMismatch::class);
        });
        try {
            DB::table($key->source_table)->insert($attributes);
            $this->fail('Raw SQL accepted a cross-company link.');
        } catch (QueryException $exception) {
            expect($exception->errorInfo[1])->toBe(1452);
        }
        $checked++;
    }

    expect($checked)->toBeGreaterThan(2);
    $context->clear();
});

test('user employee links and role tenancy reject cross-company assignments', function (): void {
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    $employeeB = app(TenantContext::class)->runForCompany($b, fn () => Employee::factory()->create());

    app(TenantContext::class)->setCompany($a);
    expect(fn () => User::factory()->create(['primary_role' => 'hr_staff', 'company_id' => $a->id, 'employee_id' => $employeeB->id]))->toThrow(TenantMismatch::class);
    expect(fn () => User::factory()->create(['primary_role' => 'hr_staff', 'company_id' => null]))->toThrow(TenantMismatch::class);
    expect(fn () => User::factory()->create(['primary_role' => 'platform_admin', 'company_id' => $a->id]))->toThrow(TenantMismatch::class);
    expect(fn () => Company::factory()->create(['enabled_languages' => ['ar']]))->toThrow(ValidationException::class);
    app(TenantContext::class)->clear();
});

test('branch-user links reject another company user', function (): void {
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    $userB = User::factory()->create(['primary_role' => 'hr_staff', 'company_id' => $b->id]);

    app(TenantContext::class)->runForCompany($a, function () use ($userB): void {
        expect(fn () => BranchUser::factory()->create(['user_id' => $userB->id]))->toThrow(TenantMismatch::class);
    });
});
