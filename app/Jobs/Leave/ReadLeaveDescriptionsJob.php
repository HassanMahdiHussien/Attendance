<?php

namespace App\Jobs\Leave;

use App\Models\Company;
use App\Models\Leave\DescriptionReading;
use App\Services\Leave\LeaveDescriptionReader;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ReadLeaveDescriptionsJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 420;

    public int $uniqueFor = 600;

    public function __construct(public int $companyId) {}

    public function uniqueId(): string
    {
        return (string) $this->companyId;
    }

    public function handle(LeaveDescriptionReader $reader): void
    {
        $company = Company::findOrFail($this->companyId);
        $more = app(TenantContext::class)->runForCompany($company, fn () => $reader->read($company));
        if ($more) {
            self::dispatch($this->companyId)->delay(now()->addSeconds(5));
        }
    }

    public function failed(Throwable $exception): void
    {
        $company = Company::find($this->companyId);
        if ($company === null) {
            return;
        }
        app(TenantContext::class)->runForCompany($company, function () use ($company): void {
            DescriptionReading::query()->where('company_id', $company->id)
                ->where('status', 'proposed')->whereNull('category')->whereNull('error_code')
                ->update(['error_code' => 'read_failed']);
        });
    }
}
