<?php

namespace Database\Factories\Attendance;

use App\Models\Attendance\HolidaySource;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<HolidaySource> */
class HolidaySourceFactory extends Factory
{
    protected $model = HolidaySource::class;

    public function definition(): array
    {
        return [
            'company_id' => app(TenantContext::class)->companyId(),
            'uploaded_by' => User::factory(), 'file_path' => 'holidays/source-'.fake()->uuid().'.pdf',
            'file_name' => 'notice.pdf', 'mime' => 'application/pdf', 'sha256' => hash('sha256', fake()->uuid()),
            'ai_status' => 'pending',
        ];
    }
}
