<?php

use App\Events\CoverageChanged;
use App\Jobs\Attendance\BuildMonthSummariesJob;
use App\Jobs\Attendance\WarmResultCacheJob;
use App\Models\Attendance\CoverageInterval;
use App\Models\Attendance\Day;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Services\Attendance\AssessmentTriggers;
use App\Services\Attendance\OfficePolicy;
use App\Services\Attendance\Overtime;
use App\Services\Attendance\Reporting\AttendanceSummary;
use App\Services\Attendance\Reporting\LastUpdate;
use App\Services\Attendance\Reporting\MonthSummaries;
use App\Services\Attendance\Reporting\ReportFilters;
use App\Services\Attendance\Reporting\ResultCache;
use App\Support\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-05 12:00:00');
    Queue::fake();
    $this->company = Company::factory()->create();
    app(TenantContext::class)->setCompany($this->company);
    $this->branch = Branch::factory()->create();
    $this->employee = Employee::factory()->create(['branch_id' => $this->branch->id,
        'hire_date' => '2020-01-01', 'work_pattern' => 'office']);
    app(OfficePolicy::class)->createDefault($this->company);
    $this->filters = ReportFilters::fromArray($this->company, ['from' => '2026-11-01', 'to' => '2026-11-05']);
});

afterEach(function (): void {
    Carbon::setTestNow();
    app(TenantContext::class)->clear();
});

test('result keys change on a dirty month, period and company', function (): void {
    $cache = app(ResultCache::class);
    $calls = 0;
    $compute = function () use (&$calls): array {
        $calls++;

        return ['call' => $calls];
    };
    expect($cache->remember('invented_result', $this->filters, $compute))->toBe(['call' => 1])
        ->and($cache->remember('invented_result', $this->filters, $compute))->toBe(['call' => 1]);
    $before = $cache->version($this->company->id);
    app(MonthSummaries::class)->markDirty($this->company->id, [$this->employee->id], '2026-11-05', '2026-11-05');
    expect($cache->version($this->company->id))->not->toBe($before)
        ->and($cache->remember('invented_result', $this->filters, $compute))->toBe(['call' => 2]);
    $otherPeriod = ReportFilters::fromArray($this->company, ['from' => '2026-10-01', 'to' => '2026-10-31']);
    expect($cache->remember('invented_result', $otherPeriod, $compute))->toBe(['call' => 3]);

    $other = Company::factory()->create();
    $otherFilters = app(TenantContext::class)->runForCompany($other, function () use ($other, $cache, $compute): ReportFilters {
        $filters = ReportFilters::fromArray($other, ['from' => '2026-11-01', 'to' => '2026-11-05']);
        expect($cache->remember('invented_result', $filters, $compute))->toBe(['call' => 4]);

        return $filters;
    });
    expect(fn () => $cache->remember('invented_result', $otherFilters, $compute))->toThrow(HttpException::class);
});

test('cached overtime and coverage equal their live results', function (): void {
    Day::factory()->create(['employee_id' => $this->employee->id, 'branch_id' => $this->branch->id,
        'work_date' => '2026-11-04', 'status' => 'present', 'overtime_minutes' => 45,
        'overtime_basis' => 'after_end', 'early_arrival_minutes' => 10]);
    app(MonthSummaries::class)->markDirty($this->company->id, [$this->employee->id], '2026-11-04', '2026-11-04');
    $cache = app(ResultCache::class);
    $overtime = app(Overtime::class);
    $update = app(LastUpdate::class);

    expect($overtime->totals($this->filters))->toEqual($overtime->totalsLive($this->filters))
        ->and($update->for($this->filters)['coverage'])
        ->toEqual($cache->withoutCache(fn (): array => $update->for($this->filters)['coverage']));
});

test('coverage changes invalidate the cached panel', function (): void {
    $cache = app(ResultCache::class);
    $update = app(LastUpdate::class);
    $before = $update->for($this->filters)['coverage'];
    $version = $cache->version($this->company->id);
    CoverageInterval::factory()->create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id,
        'coverage_from' => '2026-11-04 00:00:00', 'coverage_to' => '2026-11-04 23:59:59',
        'is_confirmed' => true]);
    app(AssessmentTriggers::class)->coverageChanged(new CoverageChanged($this->company->id,
        [$this->branch->id], '2026-11-04', '2026-11-04'));

    $after = $update->for($this->filters)['coverage'];
    expect($cache->version($this->company->id))->not->toBe($version);
    expect($after)->toEqual($cache->withoutCache(fn (): array => $update->for($this->filters)['coverage']));
    expect($after)->not->toEqual($before);
});

test('explicit overtime as-of dates bypass cached totals', function (): void {
    $overtime = app(Overtime::class);
    $overtime->totals($this->filters);
    Day::factory()->create(['employee_id' => $this->employee->id, 'branch_id' => $this->branch->id,
        'work_date' => '2026-11-04', 'status' => 'present', 'overtime_minutes' => 45,
        'overtime_basis' => 'after_end']);

    expect($overtime->totals($this->filters)['overtime_minutes'])->toBe(0)
        ->and($overtime->totals($this->filters, '2026-11-05')['overtime_minutes'])->toBe(45);
});

test('a failed warmup does not fail its job', function (): void {
    $summary = Mockery::mock(AttendanceSummary::class);
    $summary->shouldReceive('totals')->andThrow(new RuntimeException('Invented warmup failure'));
    $overtime = Mockery::mock(Overtime::class);
    $overtime->shouldReceive('totals')->times(3)->andReturn([]);
    $update = Mockery::mock(LastUpdate::class);
    $update->shouldReceive('for')->times(3)->andReturn([]);
    $job = new WarmResultCacheJob($this->company->id);

    $job->handle($summary, $overtime, $update);

    expect(true)->toBeTrue();
});

test('a completed month build queues result warming', function (): void {
    $job = new BuildMonthSummariesJob($this->company->id);

    $job->handle(app(MonthSummaries::class));

    Queue::assertPushed(WarmResultCacheJob::class);
});
