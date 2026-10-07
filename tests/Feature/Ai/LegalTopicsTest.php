<?php

use App\LegalLibrary\LawManagement;
use App\LegalLibrary\UnitCorrection;
use App\Models\Company;
use App\Models\Law;
use App\Models\LegalUnit;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('statute topics are indexed outside immutable units and copied to corrections', function (): void {
    app(TenantContext::class)->runAcrossAllCompanies(function (): void {
        $actor = User::factory()->create(['company_id' => null, 'primary_role' => 'platform_admin']);
        $law = Law::factory()->create(['kind' => 'statute']);
        $unit = LegalUnit::factory()->create(['law_id' => $law->id]);
        app(LawManagement::class)->setUnitTopics($unit, $actor, ['discipline', 'workplace conduct']);
        expect($unit->topics()->orderBy('topic')->pluck('topic')->all())->toBe(['discipline', 'workplace conduct']);
        $replacement = app(UnitCorrection::class)->correct($unit, $actor, 'Corrected official text', 'Official correction');
        expect($replacement->topics()->orderBy('topic')->pluck('topic')->all())->toBe(['discipline', 'workplace conduct'])
            ->and($unit->fresh()->superseded_by_id)->toBe($replacement->id);
    });
});

test('topic trigger rejects internal regulation units', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->runForCompany($company, function () use ($company): void {
        $actor = User::factory()->create(['company_id' => null, 'primary_role' => 'platform_admin']);
        $law = Law::factory()->create(['kind' => 'internal_regulation', 'company_id' => $company->id]);
        $unit = LegalUnit::factory()->create(['law_id' => $law->id]);
        expect(fn () => DB::table('legal_unit_topics')->insert(['legal_unit_id' => $unit->id,
            'topic' => 'discipline', 'created_by' => $actor->id, 'created_at' => now()]))->toThrow(QueryException::class);
    });
});
