<?php

namespace Database\Factories\Attendance;

use App\Models\Attendance\ChatProposal;
use App\Models\Attendance\ChatProposalEvent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ChatProposalEvent> */
class ChatProposalEventFactory extends Factory
{
    protected $model = ChatProposalEvent::class;

    public function definition(): array
    {
        return ['company_id' => app(TenantContext::class)->companyId(),
            'proposal_id' => ChatProposal::factory(), 'event' => 'proposed',
            'details' => [], 'created_at' => now()];
    }
}
