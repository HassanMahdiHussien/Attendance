<?php

namespace Database\Factories;

use App\Models\CaseLink;
use App\Models\CaseLinkAnnotation;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CaseLinkAnnotation> */
class CaseLinkAnnotationFactory extends Factory
{
    protected $model = CaseLinkAnnotation::class;

    public function definition(): array
    {
        return [
            'company_id' => app(TenantContext::class)->companyId(),
            'case_link_id' => CaseLink::factory(),
            'kind' => 'linked_in_error',
            'reason' => 'Linked the wrong case',
            'created_by_user_id' => User::factory()->state(['company_id' => app(TenantContext::class)->companyId(),
                'primary_role' => 'company_admin']),
        ];
    }
}
