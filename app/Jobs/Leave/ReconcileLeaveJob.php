<?php

namespace App\Jobs\Leave;

use App\Models\Company;
use App\Services\Leave\PendingReconciliation;
use App\Services\Leave\Reconciler;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ReconcileLeaveJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public int $uniqueFor = 3600;

    public function __construct(
        public int $companyId,
        public array $employeeIds,
        public string $trigger,
        public ?string $from = null,
        public ?string $to = null,
        public ?int $slot = null,
    ) {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        if ($this->trigger === 'days' && $this->employeeIds === []) {
            return 'pending:'.$this->companyId.':'.($this->slot ?? 0);
        }
        $ids = array_values(array_unique(array_map('intval', $this->employeeIds)));
        sort($ids, SORT_NUMERIC);

        return hash('sha256', json_encode([$this->companyId, $ids, $this->from, $this->to], JSON_THROW_ON_ERROR));
    }

    public function handle(Reconciler $reconciler): void
    {
        $company = Company::findOrFail($this->companyId);
        if ($this->trigger === 'days' && $this->employeeIds === []) {
            app(TenantContext::class)->runForCompany($company,
                fn () => app(PendingReconciliation::class)->run($company, $reconciler, $this));

            return;
        }
        app(TenantContext::class)->runForCompany($company, fn () => $reconciler->run($company, $this->employeeIds, $this->trigger, $this->from, $this->to));
    }
}
