<?php

namespace Database\Factories\Attendance;

use App\Models\Attendance\CaseEvent;
use App\Models\Attendance\FollowUpCase;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CaseEvent> */
class CaseEventFactory extends Factory
{
    protected $model = CaseEvent::class;

    public function definition(): array
    {
        return [
            'company_id' => app(TenantContext::class)->companyId(),
            'case_id' => FollowUpCase::factory(), 'event' => 'opened',
            'before' => null, 'after' => ['state' => 'open'],
            'source' => 'test', 'created_at' => now(),
        ];
    }
}
