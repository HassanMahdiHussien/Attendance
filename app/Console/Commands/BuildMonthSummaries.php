<?php

namespace App\Console\Commands;

use App\Models\Attendance\MonthSummary;
use App\Models\Company;
use App\Services\Attendance\Reporting\MonthSummaries;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BuildMonthSummaries extends Command
{
    protected $signature = 'attendance:month-summaries-build {--company=} {--refresh-current}';

    public function handle(MonthSummaries $summaries): int
    {
        $option = $this->option('company');
        if ($option !== null && ($option === '' || filter_var($option, FILTER_VALIDATE_INT) === false)) {
            $this->error(__('messages.month_summaries.invalid_company'));

            return self::FAILURE;
        }
        $companies = Company::query()->when($option !== null, fn ($query) => $query->whereKey((int) $option))
            ->orderBy('id')->get();
        if ($companies->isEmpty() && $option !== null) {
            $this->error(__('messages.month_summaries.invalid_company'));

            return self::FAILURE;
        }
        $count = 0;
        foreach ($companies as $company) {
            app(TenantContext::class)->runForCompany($company, function () use ($company, $summaries, &$count): void {
                if ($this->option('refresh-current')) {
                    $ids = MonthSummary::query()->where('company_id', $company->id)
                        ->where('month', today('Asia/Baghdad')->startOfMonth()->toDateString())
                        ->pluck('employee_id')->all();
                    $date = today('Asia/Baghdad')->toDateString();
                    $summaries->markDirty($company->id, $ids, $date, $date);
                }
                $rows = DB::table('attendance_days')->where('company_id', $company->id)
                    ->whereNull('superseded_by_run_id')
                    ->selectRaw("employee_id, DATE_FORMAT(work_date, '%Y-%m-01') AS month")
                    ->distinct()->orderBy('employee_id')->get()->groupBy('month');
                foreach ($rows as $month => $employees) {
                    $ids = $employees->pluck('employee_id')->map(fn ($id): int => (int) $id)->all();
                    $summaries->ensureMissing($company->id, $ids, $month);
                    $count += count($ids);
                }
                if (MonthSummary::query()->where('company_id', $company->id)->where('is_dirty', true)->exists()) {
                    $summaries->queue($company->id);
                }
            });
        }
        $this->line(__('messages.month_summaries.build_counts', ['count' => $count]));

        return self::SUCCESS;
    }
}
