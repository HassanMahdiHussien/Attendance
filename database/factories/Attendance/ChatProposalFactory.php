<?php

namespace Database\Factories\Attendance;

use App\Models\Attendance\ChatProposal;
use App\Models\Attendance\ChatTurn;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ChatProposal> */
class ChatProposalFactory extends Factory
{
    protected $model = ChatProposal::class;

    public function definition(): array
    {
        return ['company_id' => app(TenantContext::class)->companyId(), 'turn_id' => ChatTurn::factory(),
            'conversation_id' => fn (array $attributes): int => ChatTurn::findOrFail($attributes['turn_id'])->conversation_id,
            'kind' => 'employee_change', 'payload' => ['employees' => []],
            'status' => 'proposed', 'created_at' => now()];
    }
}
