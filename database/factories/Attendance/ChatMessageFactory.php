<?php

namespace Database\Factories\Attendance;

use App\Models\Attendance\ChatMessage;
use App\Models\Attendance\ChatTurn;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ChatMessage> */
class ChatMessageFactory extends Factory
{
    protected $model = ChatMessage::class;

    public function definition(): array
    {
        return ['company_id' => app(TenantContext::class)->companyId(),
            'turn_id' => ChatTurn::factory(), 'conversation_id' => fn (array $attributes): int => ChatTurn::findOrFail($attributes['turn_id'])->conversation_id,
            'role' => 'user', 'content' => ['text' => 'Attendance?'], 'created_at' => now()];
    }
}
