<?php

namespace Database\Factories\Leave;

use App\Models\Leave\Setting;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Setting> */
class SettingFactory extends Factory
{
    protected $model = Setting::class;

    public function definition(): array
    {
        return ['company_id' => app(TenantContext::class)->companyId(), 'credit_expiry_days' => null];
    }
}
