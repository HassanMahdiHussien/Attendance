<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Employee;
use App\Services\Employees\EmployeeHistory;
use App\Services\Employees\ExitReview;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ApplyEmployeeEffectiveChanges extends Command
{
    protected $signature = 'employees:apply-effective-changes';

    protected $description = 'Apply effective-dated employee values and confirmed exits';

    public function handle(EmployeeHistory $history, ExitReview $exits): int
    {
        Company::query()->orderBy('id')->chunkById(100, function ($companies) use ($history, $exits): void {
            foreach ($companies as $company) {
                app(TenantContext::class)->runForCompany($company, function () use ($history, $exits): void {
                    Employee::query()->orderBy('id')->chunkById(200, function ($employees) use ($history, $exits): void {
                        foreach ($employees as $employee) {
                            DB::transaction(function () use ($employee, $history, $exits): void {
                                $current = Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
                                if ($current->versions()->exists()) {
                                    $history->recompute($current);
                                }
                                $exits->applyDue($current->fresh());
                            });
                        }
                    });
                });
            }
        });

        return self::SUCCESS;
    }
}
