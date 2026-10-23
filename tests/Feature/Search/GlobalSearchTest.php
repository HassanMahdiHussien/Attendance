<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\Search\GlobalSearch;
use App\Support\Search\SearchText;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

test('employee search text follows model writes', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $employee = Employee::factory()->create(['name' => 'Example Employee', 'name_en' => 'Hassan Ahmed']);

    expect($employee->search_name)->toBe(SearchText::normalize($employee->name))
        ->and($employee->search_name_en)->toBe('hassan ahmed');
    $employee->update(['name' => 'Updated Employee', 'name_en' => 'Ali Kareem']);
    expect($employee->fresh()->search_name)->toBe('updated employee')
        ->and($employee->fresh()->search_name_en)->toBe('ali kareem');
});

test('global search separates similar names by branch and department and matches both scripts and exact code', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'hr_staff']);
    $hilla = Branch::factory()->create(['name' => 'Hilla']);
    $erbil = Branch::factory()->create(['name' => 'Erbil']);
    $first = Employee::factory()->create(['name' => 'أحمد علي', 'name_en' => 'Ahmed Ali',
        'employee_code' => '00123456', 'branch_id' => $hilla->id, 'department' => 'Finance']);
    Employee::factory()->create(['name' => 'أحمد علي', 'name_en' => 'Ahmed Ali',
        'employee_code' => '00123457', 'branch_id' => $erbil->id, 'department' => 'Operations']);
    $search = app(GlobalSearch::class);
    $details = array_column($search->for($hr, 'ahmed')['employees'], 'detail');

    expect($search->for($hr, 'ahmed')['employees'])->toHaveCount(2)
        ->and($search->for($hr, 'احمد')['employees'])->toHaveCount(2)
        ->and($search->for($hr, '00123456')['employees'])->toHaveCount(1)
        ->and($search->for($hr, '00123456')['employees'][0]['url'])->toBe(route('employees.show', $first))
        ->and(collect($details)->contains(fn (string $detail): bool => str_contains($detail, 'Hilla') && str_contains($detail, 'Finance')))->toBeTrue();
});

test('employee search obeys role and tenant scope', function (): void {
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    app(TenantContext::class)->setCompany($a);
    $admin = User::factory()->create(['company_id' => $a->id, 'primary_role' => 'hr_staff']);
    $hr = User::factory()->create(['company_id' => $a->id, 'primary_role' => 'hr_staff']);
    $employee = Employee::factory()->create(['name' => 'Example Employee']);
    app(TenantContext::class)->setCompany($b);
    Employee::factory()->create(['name' => 'Example Employee']);
    app(TenantContext::class)->setCompany($a);

    $search = app(GlobalSearch::class);
    expect($search->for($admin, 'example'))->toHaveKey('employees')
        ->and($search->for($admin, 'example'))->not->toHaveKey('cases')
        ->and($search->for($hr, 'example')['employees'])->toHaveCount(1)
        ->and($search->for($hr, 'example')['employees'][0]['url'])->toBe(route('employees.show', $employee));
});

test('employee search is capped and query count stays bounded', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $admin = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'hr_staff']);
    Employee::factory()->count(12)->create(['name' => 'Example Employee']);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $results = app(GlobalSearch::class)->for($admin, 'example');
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($results['employees'])->toHaveCount(8)->and($queries)->toBeLessThanOrEqual(8);
});
