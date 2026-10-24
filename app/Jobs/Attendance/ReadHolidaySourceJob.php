<?php

namespace App\Jobs\Attendance;

use App\Models\Attendance\HolidaySource;
use App\Models\Company;
use App\Services\Attendance\Ai\HolidayReader;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ReadHolidaySourceJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 420;

    public int $uniqueFor = 600;

    public function __construct(public int $companyId, public int $sourceId) {}

    public function uniqueId(): string
    {
        return $this->companyId.':'.$this->sourceId;
    }

    public function handle(HolidayReader $reader): void
    {
        $company = Company::findOrFail($this->companyId);
        app(TenantContext::class)->runForCompany($company, function () use ($reader): void {
            $source = HolidaySource::query()->where('company_id', $this->companyId)->findOrFail($this->sourceId);
            $reader->read($source);
        });
    }
}
