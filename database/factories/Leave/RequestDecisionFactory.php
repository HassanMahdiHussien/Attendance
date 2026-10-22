<?php

namespace Database\Factories\Leave;

use App\Models\Leave\LeaveRequest;
use App\Models\Leave\RequestDecision;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RequestDecision> */
class RequestDecisionFactory extends Factory
{
    protected $model = RequestDecision::class;

    public function definition(): array
    {
        return ['company_id' => app(TenantContext::class)->companyId(), 'request_id' => LeaveRequest::factory(), 'decision' => 'cancel', 'reason' => 'Test review', 'created_by' => User::factory()->state(['company_id' => app(TenantContext::class)->companyId(), 'primary_role' => 'hr_staff'])];
    }
}
