<?php

use App\Exceptions\TenantMismatch;
use App\Models\Branch;
use App\Models\BranchUser;
use App\Models\Company;
use App\Models\CompanySequence;
use App\Models\Employee;
use App\Models\EmployeeImportBatch;
use App\Models\EmployeeImportMapping;
use App\Models\EmployeeVersion;
use App\Models\Location;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

dataset('tenant models', [
    Branch::class, Location::class, Employee::class, BranchUser::class,
    CompanySequence::class, EmployeeImportBatch::class, EmployeeImportMapping::class, EmployeeVersion::class,
]);

test('every kept tenant model isolates reads and builder writes and stamps creation', function (string $model): void {
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    $context = app(TenantContext::class);
    $make = fn (Company $company, array $attributes = []) => $context->runForCompany($company, function () use ($model, $company, $attributes) {
        if ($model === BranchUser::class) {
            return BranchUser::factory()->create($attributes + [
                'company_id' => $company->id,
                'branch_id' => Branch::factory()->create(['company_id' => $company->id])->id,
                'user_id' => User::factory()->create(['company_id' => $company->id, 'primary_role' => 'hr_staff'])->id,
            ]);
        }

        return $model::factory()->create($attributes);
    });

    $own = $make($a);
    $other = $make($b);

    $context->setCompany($a);
    expect($model::query()->pluck('id')->all())->toBe([$own->getKey()]);
    expect($model::find($other->getKey()))->toBeNull();
    expect($model::count())->toBe(1);
    expect($model::query()->whereKey($other->getKey())->update(['company_id' => $a->id]))->toBe(0);
    expect($model::query()->whereKey($other->getKey())->delete())->toBe(0);

    $attributes = $model === CompanySequence::class ? ['sequence_key' => 'employee_code', 'year' => 1] : [];
    $created = $make($a, $attributes + ['company_id' => null]);
    expect((int) $created->company_id)->toBe($a->id);
    expect(fn () => $make($a, ['company_id' => $b->id]))->toThrow(TenantMismatch::class);
    $context->clear();
})->with('tenant models');
