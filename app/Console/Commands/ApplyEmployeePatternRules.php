<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Employee;
use App\Services\Employees\PatternRule;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

class ApplyEmployeePatternRules extends Command
{
    protected $signature = 'employees:apply-pattern-rules';

    protected $description = 'Assign default work patterns to employees without one';

    public function handle(PatternRule $rules): int
    {
        Company::query()->orderBy('id')->chunkById(100, function ($companies) use ($rules): void {
            foreach ($companies as $company) {
                app(TenantContext::class)->runForCompany($company, function () use ($rules): void {
                    Employee::query()->whereNull('work_pattern')->orderBy('id')->chunkById(200, function ($employees) use ($rules): void {
                        foreach ($employees as $employee) {
                            $rules->apply($employee, today('Asia/Baghdad')->toDateString());
                        }
                    });
                });
            }
        });

        return self::SUCCESS;
    }
}
