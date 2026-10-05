<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\DocumentApproval;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DocumentApproval> */
class DocumentApprovalFactory extends Factory
{
    protected $model = DocumentApproval::class;

    public function definition(): array
    {
        return [
            'company_id' => app(TenantContext::class)->companyId(),
            'document_id' => Document::factory(),
            'approver_user_id' => User::factory(),
            'role_category' => 'legal',
            'decision' => 'approved',
            'languages_present' => ['ar', 'en'],
            'decided_at' => now(),
        ];
    }
}
