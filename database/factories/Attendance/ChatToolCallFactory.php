<?php

namespace Database\Factories\Attendance;

use App\Models\Attendance\ChatToolCall;
use App\Models\Attendance\ChatTurn;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ChatToolCall> */
class ChatToolCallFactory extends Factory
{
    protected $model = ChatToolCall::class;

    public function definition(): array
    {
        return ['company_id' => app(TenantContext::class)->companyId(), 'turn_id' => ChatTurn::factory(),
            'tool_use_id' => 'tool_'.Str::random(12), 'name' => 'company_summary',
            'input' => [], 'result' => ['ok' => true], 'is_error' => false, 'created_at' => now()];
    }
}
