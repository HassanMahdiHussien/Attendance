<?php

namespace Database\Factories;

use App\Models\CaseLink;
use App\Models\DisciplinaryCase;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CaseLink> */
class CaseLinkFactory extends Factory
{
    protected $model = CaseLink::class;

    public function definition(): array
    {
        return [
            'linked_case_id' => DisciplinaryCase::factory(),
            'company_id' => app(TenantContext::class)->companyId(),
            'case_id' => DisciplinaryCase::factory(),
            'link_type' => 'related',
        ];
    }
}
