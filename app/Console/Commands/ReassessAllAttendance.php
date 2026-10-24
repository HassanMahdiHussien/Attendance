<?php

namespace App\Console\Commands;

use App\Jobs\Attendance\AssessDaysJob;
use App\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReassessAllAttendance extends Command
{
    protected $signature = 'attendance:reassess-all {--company=}';

    protected $description = 'Queue reassessment of dates with attendance days';

    public function handle(): int
    {
        $companyId = $this->option('company');
        $companies = Company::query()->when($companyId !== null, fn ($query) => $query->whereKey((int) $companyId));
        $companies->orderBy('id')->chunkById(100, function ($chunk): void {
            foreach ($chunk as $company) {
                $dates = DB::table('attendance_days')->where('company_id', $company->id)
                    ->distinct()->orderBy('work_date')->pluck('work_date');
                $from = null;
                $to = null;
                foreach ($dates as $date) {
                    $date = substr((string) $date, 0, 10);
                    if ($from !== null && CarbonImmutable::parse($from)->diffInDays($date) >= 31) {
                        $this->queue($company->id, $from, $to);
                        $from = null;
                    }
                    $from ??= $date;
                    $to = $date;
                }
                if ($from !== null) {
                    $this->queue($company->id, $from, $to);
                }
            }
        });

        return self::SUCCESS;
    }

    private function queue(int $companyId, string $from, string $to): void
    {
        AssessDaysJob::dispatch($companyId, ['from' => $from, 'to' => $to], 'policy_replacement')->afterCommit();
    }
}
