<?php

namespace Database\Factories\Attendance;

use App\Models\Attendance\ChatConversation;
use App\Models\Attendance\ChatTurn;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ChatTurn> */
class ChatTurnFactory extends Factory
{
    protected $model = ChatTurn::class;

    public function definition(): array
    {
        return ['company_id' => app(TenantContext::class)->companyId(),
            'conversation_id' => ChatConversation::factory(), 'sequence' => 1, 'status' => 'queued'];
    }
}
