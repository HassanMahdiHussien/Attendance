<?php

namespace App\Console\Commands;

use App\Models\Attendance\MonthSummary;
use App\Models\Company;
use App\Services\Attendance\Reporting\AttendanceSummary;
use App\Services\Attendance\Reporting\MonthSummaries;
use App\Services\Attendance\Reporting\ReportFilters;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

class VerifyMonthSummaries extends Command
{
    protected $signature = 'attendance:month-summaries-verify {--company=} {--from=} {--to=}';

    public function handle(AttendanceSummary $summary): int
    {
        $companyId = filter_var($this->option('company'), FILTER_VALIDATE_INT);
        if ($companyId === false || $companyId === null) {
            $this->error(__('messages.month_summaries.invalid_company'));

            return self::FAILURE;
        }
        $company = Company::findOrFail($companyId);
        $checked = 0;
        $mismatched = 0;
        $fields = [];
        app(TenantContext::class)->runForCompany($company, function () use ($company, $summary, &$checked, &$mismatched, &$fields): void {
            $query = MonthSummary::query()->where('company_id', $company->id);
            if ($this->option('from') !== null) {
                $query->whereDate('month', '>=', $this->option('from'));
            }
            if ($this->option('to') !== null) {
                $query->whereDate('month', '<=', $this->option('to'));
            }
            $query->orderBy('id')->chunkById(100, function ($months) use ($company, $summary, &$checked, &$mismatched, &$fields): void {
                foreach ($months as $month) {
                    $filters = ReportFilters::fromArray($company, ['from' => $month->month->toDateString(),
                        'to' => min($month->month->endOfMonth()->toDateString(), today('Asia/Baghdad')->toDateString()),
                        'employee_ids' => [(int) $month->employee_id]]);
                    $live = iterator_to_array($summary->perEmployee($filters, false));
                    $expected = MonthSummaries::snapshot($live[0]);
                    $actual = $month->attendance ?? [];
                    $checked++;
                    $different = [];
                    foreach (MonthSummaries::FIELDS as $field) {
                        if (($expected[$field] ?? null) != ($actual[$field] ?? null)) {
                            $different[] = $field;
                            $fields[$field] = true;
                        }
                    }
                    if ($different !== []) {
                        $mismatched++;
                    }
                }
            });
        });
        $this->line(__('messages.month_summaries.verify_counts', [
            'checked' => $checked, 'mismatched' => $mismatched, 'fields' => implode(', ', array_keys($fields)),
        ]));

        return self::SUCCESS;
    }
}
