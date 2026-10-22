<?php

namespace App\Jobs\Attendance;

use App\Models\Company;
use App\Services\Attendance\DayAssessor;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class AssessDaysJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public int $uniqueFor = 3600;

    public function __construct(public int $companyId, public array $scope, public string $trigger) {}

    public function uniqueId(): string
    {
        $scope = $this->scope;
        foreach (['employee_ids', 'branch_ids'] as $key) {
            if (isset($scope[$key])) {
                $scope[$key] = array_values(array_unique(array_map('intval', $scope[$key])));
                sort($scope[$key], SORT_NUMERIC);
            }
        }
        ksort($scope);

        return hash('sha256', json_encode([$this->companyId, $scope], JSON_THROW_ON_ERROR));
    }

    public function handle(DayAssessor $assessor): void
    {
        $company = Company::findOrFail($this->companyId);
        app(TenantContext::class)->runForCompany($company, fn () => $assessor->run($company, $this->scope, $this->trigger));
    }
}
