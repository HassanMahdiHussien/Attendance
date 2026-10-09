<?php

namespace Database\Factories;

use App\Models\Law;
use App\Models\LegalUnit;
use App\Models\RegulationComparison;
use App\Models\RegulationComparisonPair;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/** @extends Factory<RegulationComparisonPair> */
class RegulationComparisonPairFactory extends Factory
{
    protected $model = RegulationComparisonPair::class;

    public function definition(): array
    {
        $companyId = app(TenantContext::class)->companyId();
        $comparison = RegulationComparison::factory()->create();
        $statute = Law::factory()->create(['category' => 'other', 'approval_status' => 'approved']);
        $statute->jurisdictions()->create(['jurisdiction' => 'federal_iraq']);
        $lawUnit = LegalUnit::factory()->create(['law_id' => $statute->id]);
        $regulation = Law::factory()->create(['kind' => 'internal_regulation', 'company_id' => $companyId]);
        $regulationUnit = LegalUnit::factory()->create(['law_id' => $regulation->id]);
        $approver = User::factory()->create(['company_id' => $companyId, 'primary_role' => 'company_admin']);
        DB::table('legal_unit_approvals')->insert(['company_id' => $companyId,
            'legal_unit_id' => $regulationUnit->id, 'approved_by' => $approver->id, 'created_at' => now()]);

        return [
            'company_id' => $companyId,
            'comparison_id' => $comparison->id,
            'law_unit_id' => $lawUnit->id,
            'regulation_unit_id' => $regulationUnit->id,
            'verdict' => 'consistent',
        ];
    }
}
