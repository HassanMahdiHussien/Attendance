<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Leave\DescriptionReading;
use App\Services\Leave\LeaveDescriptionEffects;
use App\Services\Leave\ReconciliationTriggers;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

class RefreshCompensationLeaveNotes extends Command
{
    protected $signature = 'attendance:leave-notes-refresh-compensation {--company=} {--dry-run}';

    public function __construct()
    {
        parent::__construct();
        $this->setDescription(__('messages.leave_descriptions.refresh_compensation_description'));
    }

    public function handle(LeaveDescriptionEffects $effects, ReconciliationTriggers $triggers): int
    {
        $companyId = filter_var($this->option('company'), FILTER_VALIDATE_INT);
        if ($companyId === false || $companyId === null) {
            $this->error(__('messages.leave_descriptions.refresh_company_required'));

            return self::FAILURE;
        }

        $company = Company::findOrFail($companyId);
        $applying = 0;
        $employeeIds = [];
        app(TenantContext::class)->runForCompany($company, function () use ($company, $effects, $triggers, &$applying, &$employeeIds): void {
            DescriptionReading::query()->with(['request.leaveType'])->where('company_id', $company->id)
                ->where('category', 'compensation_for_day')->orderBy('id')
                ->chunkById(500, function ($readings) use ($effects, &$applying, &$employeeIds): void {
                    foreach ($readings as $reading) {
                        $request = $reading->request;
                        if ($request !== null && $effects->applies($request, $reading, 'compensation_for_day')) {
                            $applying++;
                            if ($request->employee_id !== null) {
                                $employeeIds[$request->employee_id] = $request->employee_id;
                            }
                        }
                    }
                });
            if (! $this->option('dry-run')) {
                $triggers->dispatch($company->id, array_values($employeeIds), 'description');
            }
        });

        $this->line(__('messages.leave_descriptions.refresh_compensation_counts', [
            'applying' => $applying, 'employees' => count($employeeIds),
        ]));

        return self::SUCCESS;
    }
}
