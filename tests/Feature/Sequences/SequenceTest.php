<?php

use App\Enums\SequenceKey;
use App\Models\Company;
use App\Models\DisciplinaryCase;
use App\Models\Document;
use App\Models\Employee;
use App\Models\User;
use App\Services\Documents\DocumentIssuer;
use App\Services\SequenceService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

test('case and employee sequences are per company, and supplied codes do not consume numbers', function (): void {
    $context = app(TenantContext::class);
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    foreach ([$a, $b] as $company) {
        $context->runForCompany($company, function (): void {
            expect(Employee::factory()->create(['employee_code' => 'CUSTOM'])->employee_code)->toBe('CUSTOM');
            expect(Employee::factory()->create()->employee_code)->toBe('EMP-0001');
            expect(Employee::factory()->create()->employee_code)->toBe('EMP-0002');
            expect(DisciplinaryCase::factory()->create(['case_number' => 7])->case_number)->toBe(7);
            expect(DisciplinaryCase::factory()->create()->case_number)->toBe(1);
            expect(DisciplinaryCase::factory()->create()->case_number)->toBe(2);
        });
    }
});

test('user request attributes cannot mass assign tenant or role links', function (): void {
    $user = new User;
    $user->fill(['name' => 'Example', 'email' => 'example@example.test', 'password' => 'secret',
        'company_id' => 10, 'primary_role' => 'company_admin', 'employee_id' => 20,
        'law_firm_partner_id' => 30, 'hr_partner_id' => 40]);
    expect($user->name)->toBe('Example');
    foreach (['company_id', 'primary_role', 'employee_id', 'law_firm_partner_id', 'hr_partner_id'] as $attribute) {
        expect($user->getAttribute($attribute))->toBeNull();
    }
});

test('a transaction is required and a rollback does not consume a number', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $default = DB::getDefaultConnection();
    config(['database.connections.sequence_outside' => config("database.connections.{$default}")]);
    DB::setDefaultConnection('sequence_outside');
    try {
        expect(fn () => app(SequenceService::class)->next($company->id, SequenceKey::CaseNumber))->toThrow(LogicException::class);
    } finally {
        DB::setDefaultConnection($default);
        DB::purge('sequence_outside');
    }
    try {
        DB::transaction(function () use ($company): void {
            expect(app(SequenceService::class)->next($company->id, SequenceKey::CaseNumber))->toBe(1);
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }
    expect(DB::transaction(fn () => app(SequenceService::class)->next($company->id, SequenceKey::CaseNumber)))->toBe(1);
});

test('references reset each year; manual values do not advance the counter and generated values skip them', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $actor = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'hr_staff']);
    $issuer = app(DocumentIssuer::class);
    $make = function () use ($actor): Document {
        $document = Document::factory()->create(['created_by' => $actor->id]);
        foreach (['ar', 'en'] as $language) {
            $document->contents()->create(['company_id' => $document->company_id, 'language_code' => $language,
                'body' => 'Text', 'is_authoritative' => $language === 'ar']);
        }

        return $document;
    };
    Date::setTestNow('2026-10-05 12:00:00');
    try {
        $manual = $issuer->issue($make(), $actor, '1');
        expect($manual->reference_is_manual)->toBeTrue();
        expect($issuer->previewReference($make()))->toBe('2');
        expect($issuer->issue($make(), $actor)->reference_number)->toBe('2');
        expect(fn () => $issuer->issue($make(), $actor, '1'))->toThrow(ValidationException::class);
        $issuer->issue($make(), $actor, '963-B');
        expect($issuer->issue($make(), $actor)->reference_number)->toBe('3');
        Date::setTestNow('2027-01-02 12:00:00');
        expect($issuer->issue($make(), $actor)->reference_number)->toBe('1');
    } finally {
        Date::setTestNow();
    }
});
