<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\EmailDraft;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EmailDraft> */
class EmailDraftFactory extends Factory
{
    protected $model = EmailDraft::class;

    public function definition(): array
    {
        $companyId = app(TenantContext::class)->companyId();

        return [
            'company_id' => $companyId,
            'branch_id' => Branch::factory(),
            'sender_user_id' => User::factory()->state(['company_id' => $companyId, 'primary_role' => 'dept_manager']),
            'subject' => $this->faker->sentence(),
            'body_text' => $this->faker->paragraph(),
            'received_at' => now(),
        ];
    }
}
