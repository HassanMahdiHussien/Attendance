<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\CompanyLawFirmPartner;
use App\Models\LawFirmPartner;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CompanyLawFirmPartner> */
class CompanyLawFirmPartnerFactory extends Factory
{
    protected $model = CompanyLawFirmPartner::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'law_firm_partner_id' => LawFirmPartner::factory(),
        ];
    }
}
