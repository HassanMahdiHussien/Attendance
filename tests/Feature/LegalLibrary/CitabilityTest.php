<?php

use App\Models\Company;
use App\Models\DisciplinaryCase;
use App\Models\Law;
use App\Models\LegalUnit;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

test('citation scope pins labor law and checks other statutes by case date', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->runForCompany($company, function (): void {
        $pinned = Law::factory()->create(['category' => 'labor', 'approval_status' => 'approved']);
        $pinned->jurisdictions()->create(['jurisdiction' => 'federal_iraq']);
        $otherLabor = Law::factory()->create(['category' => 'labor', 'approval_status' => 'approved']);
        $otherLabor->jurisdictions()->create(['jurisdiction' => 'federal_iraq']);
        $security = Law::factory()->create(['category' => 'social_security', 'approval_status' => 'approved',
            'effective_from' => now()->subYear()->toDateString()]);
        $security->jurisdictions()->create(['jurisdiction' => 'federal_iraq']);
        $case = DisciplinaryCase::factory()->create(['law_version_id' => $pinned->id]);
        $a = LegalUnit::factory()->create(['law_id' => $pinned->id]);
        $b = LegalUnit::factory()->create(['law_id' => $otherLabor->id]);
        $c = LegalUnit::factory()->create(['law_id' => $security->id]);
        expect(LegalUnit::citableFor($case)->pluck('legal_units.id')->all())->toContain($a->id, $c->id)
            ->not->toContain($b->id);
        $security->update(['repealed_at' => now()->subDay()->toDateString()]);
        expect(LegalUnit::citableFor($case)->whereKey($c->id)->exists())->toBeFalse();
        $pinned->jurisdictions()->delete();
        expect(LegalUnit::citableFor($case)->whereKey($a->id)->exists())->toBeFalse();
    });
});

test('regulation needs unit approval and a corrected version needs its own approval', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->runForCompany($company, function () use ($company): void {
        $law = Law::factory()->create(['category' => 'labor', 'approval_status' => 'approved']);
        $law->jurisdictions()->create(['jurisdiction' => 'federal_iraq']);
        $case = DisciplinaryCase::factory()->create(['law_version_id' => $law->id]);
        $regulation = Law::factory()->create(['kind' => 'internal_regulation', 'company_id' => $company->id]);
        $unit = LegalUnit::factory()->create(['law_id' => $regulation->id, 'status' => 'verified_text']);
        expect(LegalUnit::citableFor($case)->whereKey($unit->id)->exists())->toBeFalse();
        $reviewer = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'hr_staff']);
        DB::table('legal_unit_approvals')->insert(['company_id' => $company->id,
            'legal_unit_id' => $unit->id, 'approved_by' => $reviewer->id, 'created_at' => now()]);
        expect(LegalUnit::citableFor($case)->whereKey($unit->id)->exists())->toBeTrue();
        $replacement = LegalUnit::factory()->create(['law_id' => $regulation->id,
            'previous_version_id' => $unit->id, 'version' => 2, 'status' => 'verified_human']);
        $unit->update(['superseded_by_id' => $replacement->id]);
        expect(LegalUnit::citableFor($case)->whereKey($unit->id)->exists())->toBeFalse();
        expect(LegalUnit::citableFor($case)->whereKey($replacement->id)->exists())->toBeFalse();
    });
});
