<?php

namespace App\Jobs\Leave;

use App\Models\Company;
use App\Services\Leave\Reconciler;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ReconcileLeaveJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public int $uniqueFor = 3600;

    public function __construct(
        public int $companyId,
        public array $employeeIds,
        public string $trigger,
        public ?string $from = null,
        public ?string $to = null,
    ) {}

    public function uniqueId(): string
    {
        $ids = array_values(array_unique(array_map('intval', $this->employeeIds)));
        sort($ids, SORT_NUMERIC);

        return hash('sha256', json_encode([$this->companyId, $ids, $this->from, $this->to], JSON_THROW_ON_ERROR));
    }

    public function handle(Reconciler $reconciler): void
    {
        $company = Company::findOrFail($this->companyId);
        app(TenantContext::class)->runForCompany($company, fn () => $reconciler->run($company, $this->employeeIds, $this->trigger, $this->from, $this->to));
    }
}
