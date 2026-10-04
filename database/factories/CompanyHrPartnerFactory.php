<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\CompanyHrPartner;
use App\Models\HrPartner;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CompanyHrPartner> */
class CompanyHrPartnerFactory extends Factory
{
    protected $model = CompanyHrPartner::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'hr_partner_id' => HrPartner::factory(),
        ];
    }
}
