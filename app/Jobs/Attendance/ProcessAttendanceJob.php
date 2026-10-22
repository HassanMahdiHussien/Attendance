<?php

namespace App\Jobs\Attendance;

use App\Models\Company;
use App\Services\Attendance\AttendanceProcessor;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessAttendanceJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public int $uniqueFor = 3600;

    public function __construct(
        public int $companyId,
        public array $personCodes,
        public string $from,
        public string $to,
        public string $trigger,
        public ?int $batchId = null,
        public ?int $deviceId = null,
        public ?string $revision = null,
    ) {}

    public function uniqueId(): string
    {
        $codes = array_values(array_unique($this->personCodes));
        sort($codes, SORT_STRING);

        return hash('sha256', json_encode([$this->companyId, $codes, $this->from, $this->to, $this->trigger, $this->batchId, $this->deviceId, $this->revision], JSON_THROW_ON_ERROR));
    }

    public function handle(AttendanceProcessor $processor): void
    {
        $company = Company::findOrFail($this->companyId);
        app(TenantContext::class)->runForCompany($company, fn () => $processor->run(
            $company, $this->personCodes, $this->from, $this->to, $this->trigger, $this->batchId, $this->deviceId,
        ));
    }
}
