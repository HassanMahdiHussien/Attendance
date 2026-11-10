<?php

namespace App\Livewire\Attendance;

use App\Models\Company;
use App\Services\Attendance\Reporting\ProcessingStatus;
use App\Support\Access\AdminNav;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class ProcessingNotice extends Component
{
    public function render(ProcessingStatus $service): View
    {
        $companyId = app(TenantContext::class)->companyId();
        $company = $companyId ? Company::find($companyId) : null;
        $status = $company ? $service->for($company) : ['state' => 'ready'];
        $uploadUrl = AdminNav::uploadItems(auth()->user())[0]['url'] ?? null;

        return view('livewire.attendance.processing-notice', compact('status', 'uploadUrl'));
    }
}
