<?php

namespace Database\Factories\Attendance;

use App\Models\Attendance\ChatConversation;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ChatConversation> */
class ChatConversationFactory extends Factory
{
    protected $model = ChatConversation::class;

    public function definition(): array
    {
        return ['company_id' => app(TenantContext::class)->companyId(),
            'user_id' => User::factory()->state(['company_id' => app(TenantContext::class)->companyId(), 'primary_role' => 'hr_staff']),
            'title' => 'Attendance inquiry', 'last_message_at' => now()];
    }
}
