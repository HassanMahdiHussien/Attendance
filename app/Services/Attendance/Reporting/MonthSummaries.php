<?php

namespace App\Services\Attendance\Reporting;

use App\Jobs\Attendance\BuildMonthSummariesJob;
use App\Models\Attendance\MonthSummary;
use App\Models\Company;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class MonthSummaries
{
    public const FIELDS = [
        'assessed_working_days', 'holiday_work_minutes', 'rest_day_work_minutes',
        'office_employees_assessed', 'days', 'minutes', 'observed_minutes',
        'short_hours_alerts', 'flags', 'leave', 'meals',
    ];

    public function __construct(private AttendanceSummary $summary) {}

    public static function snapshot(array $row): array
    {
        return array_intersect_key($row, array_flip(self::FIELDS));
    }

    public function markDirty(int $companyId, array $employeeIds, ?string $from = null, ?string $to = null): void
    {
        abort_unless(app(TenantContext::class)->companyId() === $companyId, 403);
        $employeeIds = array_values(array_unique(array_map('intval', $employeeIds)));
        if ($employeeIds === []) {
            return;
        }
        $today = today('Asia/Baghdad')->toDateString();
        $months = [];
        if ($from !== null && $to !== null) {
            for ($month = CarbonImmutable::parse($from)->startOfMonth(); $month->toDateString() <= min($to, $today); $month = $month->addMonth()) {
                foreach ($employeeIds as $id) {
                    $months[] = [$id, $month->toDateString()];
                }
            }
        } else {
            foreach (array_chunk($employeeIds, 500) as $chunk) {
                foreach ([['attendance_month_summaries', 'month'], ['attendance_days', 'work_date'],
                    ['attendance_meals', 'meal_date'], ['leave_request_days', 'work_date']] as [$table, $date]) {
                    $query = DB::table($table)->where('company_id', $companyId)->whereIn('employee_id', $chunk);
                    if ($table !== 'attendance_month_summaries') {
                        $query->whereNull('superseded_by_run_id');
                    }
                    foreach ($query->selectRaw("employee_id, DATE_FORMAT({$date}, '%Y-%m-01') AS month")
                        ->distinct()->get() as $row) {
                        if ($row->month <= $today) {
                            $months[] = [(int) $row->employee_id, $row->month];
                        }
                    }
                }
            }
        }
        $months = array_values(array_unique($months, SORT_REGULAR));
        foreach (array_chunk($months, 500) as $chunk) {
            $values = implode(', ', array_fill(0, count($chunk), '(?, ?, ?, NULL, 1, 1, NOW(), NOW())'));
            $bindings = [];
            foreach ($chunk as [$id, $month]) {
                array_push($bindings, $companyId, $id, $month);
            }
            DB::statement('INSERT INTO attendance_month_summaries (company_id, employee_id, month, attendance, version, is_dirty, created_at, updated_at) VALUES '
                .$values.' ON DUPLICATE KEY UPDATE version = version + 1, is_dirty = 1, updated_at = NOW()', $bindings);
        }
        if ($months !== []) {
            $this->queue($companyId);
        }
    }

    public function ensureMissing(int $companyId, array $employeeIds, string $month): void
    {
        abort_unless(app(TenantContext::class)->companyId() === $companyId, 403);
        if ($month > today('Asia/Baghdad')->toDateString() || $employeeIds === []) {
            return;
        }
        foreach (array_chunk($employeeIds, 500) as $chunk) {
            DB::table('attendance_month_summaries')->insertOrIgnore(array_map(fn (int $id): array => [
                'company_id' => $companyId, 'employee_id' => $id, 'month' => $month,
                'version' => 1, 'is_dirty' => true, 'created_at' => now(), 'updated_at' => now(),
            ], $chunk));
        }
        $this->queue($companyId);
    }

    public function build(MonthSummary $month): bool
    {
        abort_unless(app(TenantContext::class)->companyId() === $month->company_id, 403);
        $version = (int) $month->version;
        $from = $month->month->toDateString();
        $to = min($month->month->endOfMonth()->toDateString(), now('Asia/Baghdad')->toDateString());
        $company = Company::findOrFail($month->company_id);
        $filters = ReportFilters::fromArray($company, ['from' => $from, 'to' => $to,
            'employee_ids' => [(int) $month->employee_id]]);
        $rows = iterator_to_array($this->summary->perEmployee($filters, false));
        $snapshot = self::snapshot($rows[0]);

        return MonthSummary::query()->whereKey($month->id)->where('version', $version)
            ->where('is_dirty', true)->update(['attendance' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                'is_dirty' => false, 'built_at' => now()]) === 1;
    }

    public function queue(int $companyId): void
    {
        BuildMonthSummariesJob::dispatch($companyId)->afterCommit();
    }
}
