<?php

namespace App\Services\Attendance\FollowUp;

use App\Models\Attendance\NotificationBatch;
use App\Models\Attendance\NotificationMessage;
use App\Models\Company;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class NotificationOverview
{
    public function batches(Company $company): LengthAwarePaginator
    {
        $this->assertCompany($company);

        return NotificationBatch::query()->where('company_id', $company->id)
            ->with(['builder', 'messages.cases', 'messages.attemptRows'])->latest('id')->paginate(15);
    }

    public function batch(Company $company, int $id): NotificationBatch
    {
        $this->assertCompany($company);

        return NotificationBatch::query()->where('company_id', $company->id)->findOrFail($id);
    }

    public function message(Company $company, int $id): NotificationMessage
    {
        $this->assertCompany($company);

        return NotificationMessage::query()->where('company_id', $company->id)->findOrFail($id);
    }

    private function assertCompany(Company $company): void
    {
        abort_unless(app(TenantContext::class)->companyId() === $company->id, 403);
    }
}
