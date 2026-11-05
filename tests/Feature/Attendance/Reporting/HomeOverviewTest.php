<?php

use App\Models\Attendance\Day;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Services\Attendance\Reporting\AttendanceSummary;
use App\Services\Attendance\Reporting\HomeOverview;
use App\Services\Attendance\Reporting\ReportFilters;
use App\Support\Tenancy\TenantContext;
use Carbon\Carbon;

afterEach(function (): void {
    Carbon::setTestNow();
    app(TenantContext::class)->clear();
});

test('home tasks are positive, ordered and limited to five', function (): void {
    $overview = app(HomeOverview::class);
    expect($overview->tasks([]))->toBe([])
        ->and(array_column($overview->tasks([
            'data_issues' => 3, 'waiting_hr' => 2, 'short_hours_alerts' => 4,
            'shift_unresolved' => 1, 'missing_punch' => 5, 'absent_uncovered' => 6,
        ]), 'key'))->toBe(['absent_uncovered', 'missing_punch', 'shift_unresolved', 'short_hours_alerts', 'waiting_hr']);
});

test('home comparison uses the matching earlier period and colours improvement by measure', function (): void {
    Carbon::setTestNow('2026-11-05 12:00:00');
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $overview = app(HomeOverview::class);
    foreach ([
        ['2026-11-01', '2026-11-05', '2026-10-01', '2026-10-05'],
        ['2026-10-01', '2026-10-31', '2026-09-01', '2026-09-30'],
        ['2026-10-07', '2026-11-05', '2026-09-07', '2026-10-06'],
    ] as [$from, $to, $expectedFrom, $expectedTo]) {
        $previous = $overview->previousFilters($company, ReportFilters::fromArray($company, compact('from', 'to')));
        expect([$previous->from, $previous->to])->toBe([$expectedFrom, $expectedTo]);
    }
    $current = app(AttendanceSummary::class);
    $base = $current->totals(ReportFilters::fromArray($company, ['from' => '2026-11-01', 'to' => '2026-11-05']));
    $better = $base;
    $better['assessed_working_days'] = 10;
    $better['days']['present'] = 8;
    $better['minutes']['late']['gross'] = 30;
    $before = $base;
    $before['assessed_working_days'] = 10;
    $before['days']['present'] = 7;
    $before['minutes']['late']['gross'] = 60;
    $figures = $overview->figures($better, $before);
    expect($figures[0]['difference'])->toBe(10)->and($figures[0]['tone'])->toBe('better')
        ->and($figures[1]['difference'])->toBe(-30)->and($figures[1]['tone'])->toBe('better')
        ->and($figures[2]['tone'])->toBe('same');
});

test('top late is ordered, capped and scoped to filtered branch and company', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $branch = Branch::factory()->create();
    $otherBranch = Branch::factory()->create();
    $ids = [];
    foreach (range(1, 7) as $minutes) {
        $employee = Employee::factory()->create(['branch_id' => $branch->id]);
        $ids[] = $employee->id;
        Day::factory()->create(['employee_id' => $employee->id, 'branch_id' => $branch->id,
            'work_date' => '2026-10-20', 'status' => 'present', 'late_minutes' => $minutes]);
    }
    $outside = Employee::factory()->create(['branch_id' => $otherBranch->id]);
    $ids[] = $outside->id;
    Day::factory()->create(['employee_id' => $outside->id, 'branch_id' => $otherBranch->id,
        'work_date' => '2026-10-20', 'status' => 'present', 'late_minutes' => 90]);
    $foreign = Company::factory()->create();
    app(TenantContext::class)->runForCompany($foreign, function () use (&$ids): void {
        $employee = Employee::factory()->create();
        $ids[] = $employee->id;
        Day::factory()->create(['employee_id' => $employee->id, 'branch_id' => $employee->branch_id,
            'work_date' => '2026-10-20', 'status' => 'present', 'late_minutes' => 100]);
    });
    $filters = ReportFilters::fromArray($company, ['from' => '2026-10-01', 'to' => '2026-10-31', 'branch_ids' => [$branch->id]]);
    $rows = app(HomeOverview::class)->topLate($filters, $ids);
    expect(array_map(fn ($row): int => (int) $row->minutes, $rows))->toBe([7, 6, 5, 4, 3]);
});

test('latest files flag missing and older than seven days', function (): void {
    Carbon::setTestNow('2026-11-05 12:00:00');
    $rows = app(HomeOverview::class)->latestFiles([
        'employees' => '2026-10-27 12:00:00', 'punches' => '2026-11-04 12:00:00', 'leave' => null,
    ]);
    expect(array_column($rows, 'old'))->toBe([true, false, true]);
});
