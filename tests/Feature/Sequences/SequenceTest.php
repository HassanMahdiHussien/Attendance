<?php

use App\Enums\SequenceKey;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\SequenceService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

test('employee sequences are per company and supplied codes do not consume numbers', function (): void {
    $context = app(TenantContext::class);
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    foreach ([$a, $b] as $company) {
        $context->runForCompany($company, function (): void {
            expect(Employee::factory()->create(['employee_code' => 'CUSTOM'])->employee_code)->toBe('CUSTOM');
            expect(Employee::factory()->create()->employee_code)->toBe('EMP-0001');
            expect(Employee::factory()->create()->employee_code)->toBe('EMP-0002');
        });
    }
});

test('user request attributes cannot mass assign tenant or role links', function (): void {
    $user = new User;
    $user->fill(['name' => 'Example', 'email' => 'example@example.test', 'password' => 'secret',
        'company_id' => 10, 'primary_role' => 'hr_staff', 'employee_id' => 20]);
    expect($user->name)->toBe('Example');
    foreach (['company_id', 'primary_role', 'employee_id'] as $attribute) {
        expect($user->getAttribute($attribute))->toBeNull();
    }
});

test('a transaction is required and rollback does not consume an employee number', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $default = DB::getDefaultConnection();
    config(['database.connections.sequence_outside' => config("database.connections.{$default}")]);
    DB::setDefaultConnection('sequence_outside');
    try {
        expect(fn () => app(SequenceService::class)->next($company->id, SequenceKey::EmployeeCode))->toThrow(LogicException::class);
    } finally {
        DB::setDefaultConnection($default);
        DB::purge('sequence_outside');
    }
    try {
        DB::transaction(function () use ($company): void {
            expect(app(SequenceService::class)->next($company->id, SequenceKey::EmployeeCode))->toBe(1);
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }
    expect(DB::transaction(fn () => app(SequenceService::class)->next($company->id, SequenceKey::EmployeeCode)))->toBe(1);
});
