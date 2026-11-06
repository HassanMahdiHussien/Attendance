<?php

namespace App\Services\Attendance\Reporting;

use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ResultCache
{
    private static bool $bypass = false;

    public function version(int $companyId): string
    {
        abort_unless(app(TenantContext::class)->companyId() === $companyId, 403);
        $row = DB::table('attendance_month_summaries')->where('company_id', $companyId)
            ->selectRaw('COUNT(*) AS months, COALESCE(SUM(version), 0) AS versions, MAX(updated_at) AS updated_at')->first();
        $employeeUpdated = DB::table('employees')->where('company_id', $companyId)->max('updated_at');

        return $row->months.':'.$row->versions.':'.$row->updated_at.':'.$employeeUpdated;
    }

    private function attendanceVersion(ReportFilters $filters): string
    {
        $fromMonth = substr($filters->from, 0, 7).'-01';
        $toMonth = substr($filters->to, 0, 7).'-01';
        $row = DB::table('attendance_month_summaries')->where('company_id', $filters->companyId)
            ->whereBetween('month', [$fromMonth, $toMonth])
            ->selectRaw('COUNT(*) AS months, COALESCE(SUM(version), 0) AS versions, MAX(updated_at) AS updated_at')->first();
        $employeeUpdated = DB::table('employees')->where('company_id', $filters->companyId)->max('updated_at');
        // Issue counts are read live inside totals and resolving an issue does not mark months dirty.
        $issues = DB::table('data_issues')->where('company_id', $filters->companyId)
            ->selectRaw("SUM(status = 'open') AS open, MAX(updated_at) AS updated_at")->first();

        return $row->months.':'.$row->versions.':'.$row->updated_at.':'.$employeeUpdated.':'.$issues->open.':'.$issues->updated_at;
    }

    public function remember(string $name, ReportFilters $filters, callable $compute, ?int $ttlSeconds = null, bool $translated = false): array
    {
        abort_unless(app(TenantContext::class)->companyId() === $filters->companyId, 403);
        if (self::$bypass) {
            return $compute();
        }
        // One entry per view, overwritten when the data changes: the database store never deletes expired rows
        // by itself, so a key holding the version would add a row for every view after every upload.
        $key = 'attendance:result:'.hash('sha256', json_encode([
            $filters->companyId, $name, $filters->toArray(), $translated ? app()->getLocale() : null,
        ], JSON_THROW_ON_ERROR));
        $stamp = CarbonImmutable::now('Asia/Baghdad')->toDateString().'|'.($name === 'attendance_totals'
            ? $this->attendanceVersion($filters) : $this->version($filters->companyId));
        $hit = Cache::get($key);
        if (is_array($hit) && ($hit['stamp'] ?? null) === $stamp) {
            return $hit['value'];
        }
        $value = $compute();
        Cache::put($key, ['stamp' => $stamp, 'value' => $value], $ttlSeconds ?? now()->addDays(7));

        return $value;
    }

    public function withoutCache(callable $callback): mixed
    {
        $previous = self::$bypass;
        self::$bypass = true;
        try {
            return $callback();
        } finally {
            self::$bypass = $previous;
        }
    }
}
