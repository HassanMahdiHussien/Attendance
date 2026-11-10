<?php

use App\Jobs\Attendance\AssessDaysJob;
use App\Jobs\Attendance\BuildMonthSummariesJob;
use App\Jobs\Attendance\ImportTransactionsJob;
use App\Jobs\Attendance\ProcessAttendanceJob;
use App\Jobs\Attendance\WarmResultCacheJob;
use App\Jobs\ImportEmployeesJob;
use App\Jobs\Leave\ImportLeaveBalanceJob;
use App\Jobs\Leave\ImportLeaveJob;
use App\Jobs\Leave\ReconcileLeaveJob;
use App\Models\Attendance\ImportBatch;
use App\Models\Attendance\MonthSummary;
use App\Models\Attendance\PendingLeaveReconciliation;
use App\Models\Attendance\ProcessingRun;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Leave\ProcessingRun as LeaveRun;
use App\Services\Attendance\Reporting\ProcessingStatus;
use App\Services\Leave\PendingReconciliation;
use App\Services\Leave\Reconciler;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    Queue::fake();
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

test('processing jobs and the worker use priority queues', function (): void {
    expect((new ImportTransactionsJob(1))->queue)->toBe('imports')
        ->and((new ImportLeaveJob(1))->queue)->toBe('imports')
        ->and((new ImportLeaveBalanceJob(1))->queue)->toBe('imports')
        ->and((new ImportEmployeesJob(1))->queue)->toBe('imports')
        ->and((new AssessDaysJob(1, [], 'test'))->queue)->toBe('assessments')
        ->and((new ProcessAttendanceJob(1, [], '2026-10-01', '2026-10-01', 'test'))->queue)->toBe('assessments')
        ->and((new ReconcileLeaveJob(1, [1], 'import'))->queue)->toBe('default')
        ->and((new BuildMonthSummariesJob(1))->queue)->toBe('summaries')
        ->and((new WarmResultCacheJob(1))->queue)->toBe('summaries');
    foreach (['deploy/dev-setup.sh', 'deploy/att-demo-deploy.sh', 'deploy/templates/supervisor-worker.conf', 'compose.yaml'] as $file) {
        expect(file_get_contents(base_path($file)))->toContain('--queue=imports,assessments,default,summaries')
            ->toContain('--queue=assessments,default,summaries');
    }
});

test('upload and shared notice poll only while calculations are working', function (): void {
    $upload = file_get_contents(resource_path('views/filament/admin/pages/attendance/upload-center.blade.php'));
    $notice = file_get_contents(resource_path('views/livewire/attendance/processing-notice.blade.php'));
    expect($upload)->toContain('wire:poll.5s', "__('messages.processing_status.ready')")
        ->and($notice)->toContain('wire:poll.15s', "\$status['state'] === 'working'");
});

test('pending leave dates coalesce per employee and company', function (): void {
    config(['queue.default' => 'database']);
    $first = Company::factory()->create();
    $second = Company::factory()->create();
    $employee = app(TenantContext::class)->runForCompany($first,
        fn () => Employee::factory()->create(['company_id' => $first->id]));
    $other = app(TenantContext::class)->runForCompany($second,
        fn () => Employee::factory()->create(['company_id' => $second->id]));
    app(TenantContext::class)->runForCompany($first, function () use ($first, $employee): void {
        $pending = app(PendingReconciliation::class);
        $pending->collect($first->id, [$employee->id], '2026-10-19', '2026-10-20');
        $pending->collect($first->id, [$employee->id], '2026-10-17', '2026-10-21');
        expect(PendingLeaveReconciliation::query()->where('company_id', $first->id)->count())->toBe(1);
        $row = PendingLeaveReconciliation::query()->firstOrFail();
        expect($row->from_date)->toBe('2026-10-17')->and($row->to_date)->toBe('2026-10-21');
    });
    app(TenantContext::class)->runForCompany($second, function () use ($second, $other): void {
        expect(PendingLeaveReconciliation::query()->count())->toBe(0);
        app(PendingReconciliation::class)->collect($second->id, [$other->id], '2026-10-19', '2026-10-20');
        expect(PendingLeaveReconciliation::query()->count())->toBe(1);
    });
});

test('processing status reports pending matching and the last finished run', function (): void {
    config(['queue.default' => 'database']);
    $company = Company::factory()->create();
    app(TenantContext::class)->runForCompany($company, function () use ($company): void {
        $employee = Employee::factory()->create(['company_id' => $company->id]);
        ProcessingRun::factory()->create(['company_id' => $company->id, 'status' => 'completed',
            'finished_at' => '2026-11-08 12:34:00']);
        $service = app(ProcessingStatus::class);
        expect($service->for($company)['state'])->toBe('ready')
            ->and($service->for($company)['finished_at'])->not->toBeNull();
        $active = ProcessingRun::factory()->create(['company_id' => $company->id, 'kind' => 'days',
            'status' => 'processing', 'finished_at' => null]);
        expect($service->for($company)['phase'])->toBe('calculating_days');
        app(PendingReconciliation::class)->collect($company->id, [$employee->id], '2026-11-01', '2026-11-02');
        $active->update(['status' => 'completed', 'finished_at' => now()]);
        $status = $service->for($company);
        expect($status['state'])->toBe('working')->and($status['phase'])->toBe('matching_leave')
            ->and($status['employees_left'])->toBe(1);
        PendingLeaveReconciliation::query()->delete();
        MonthSummary::create(['company_id' => $company->id, 'employee_id' => $employee->id,
            'month' => '2026-11-01', 'is_dirty' => true, 'version' => 1]);
        expect($service->for($company)['phase'])->toBe('updating_totals');
    });
});

test('processing status does not include another company pending work', function (): void {
    config(['queue.default' => 'database']);
    $first = Company::factory()->create();
    $second = Company::factory()->create();
    app(TenantContext::class)->runForCompany($first, function () use ($first): void {
        $employee = Employee::factory()->create(['company_id' => $first->id]);
        app(PendingReconciliation::class)->collect($first->id, [$employee->id], '2026-11-01', '2026-11-02');
    });
    app(TenantContext::class)->runForCompany($second, function () use ($first, $second): void {
        expect(app(ProcessingStatus::class)->for($second)['state'])->toBe('ready');
        expect(fn () => app(ProcessingStatus::class)->for($first))->toThrow(HttpException::class);
    });
});

test('replaying several employees loads shared policies and leave settings once', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->runForCompany($company, function () use ($company): void {
        $ids = Employee::factory()->count(3)->create(['company_id' => $company->id])->pluck('id')->all();
        $shared = ['attendance_policies' => 0, 'leave_settings' => 0];
        DB::listen(function ($query) use (&$shared): void {
            foreach ($shared as $table => &$count) {
                if (str_contains(strtolower($query->sql), 'from `'.$table.'`')) {
                    $count++;
                }
            }
        });
        app(Reconciler::class)->run($company, $ids, 'test');
        expect($shared['attendance_policies'])->toBe(1)->and($shared['leave_settings'])->toBe(1);
    });
});

test('pending replay waits for its own company assessment and then covers merged dates once', function (): void {
    config(['queue.default' => 'database']);
    $company = Company::factory()->create();
    $other = Company::factory()->create();
    app(TenantContext::class)->runForCompany($company, function () use ($company, $other): void {
        $employee = Employee::factory()->create(['company_id' => $company->id]);
        $service = app(PendingReconciliation::class);
        $service->collect($company->id, [$employee->id], '2026-10-19', '2026-10-20');
        $service->collect($company->id, [$employee->id], '2026-10-17', '2026-10-21');
        $assessment = new AssessDaysJob($company->id, [], 'test');
        $jobId = DB::table('jobs')->insertGetId(['queue' => 'assessments', 'payload' => json_encode([
            'displayName' => AssessDaysJob::class, 'data' => ['command' => serialize($assessment)],
        ]), 'attempts' => 0, 'available_at' => time(), 'created_at' => time()]);
        $reconciler = Mockery::mock(Reconciler::class);
        $reconciler->shouldReceive('run')->never();
        $runner = new ReconcileLeaveJob($company->id, [], 'days');
        $service->run($company, $reconciler, $runner);
        expect(PendingLeaveReconciliation::query()->count())->toBe(1);
        DB::table('jobs')->where('id', $jobId)->delete();
        $reconciler = Mockery::mock(Reconciler::class);
        $reconciler->shouldReceive('run')->once()->withArgs(fn ($actual, $ids, $trigger, $from, $to): bool => $actual->id === $company->id && $ids === [$employee->id] && $trigger === 'days'
                && $from === '2026-10-17' && $to === '2026-10-21')->andReturn(new LeaveRun);
        $service->run($company, $reconciler, $runner);
        expect(PendingLeaveReconciliation::query()->count())->toBe(0);
        $foreignJob = new AssessDaysJob($other->id, [], 'test');
        DB::table('jobs')->insert(['queue' => 'assessments', 'payload' => json_encode([
            'displayName' => AssessDaysJob::class, 'data' => ['command' => serialize($foreignJob)],
        ]), 'attempts' => 0, 'available_at' => time(), 'created_at' => time()]);
        expect($service->hasUpstreamWork($company))->toBeFalse();
    });
});

test('processing status sees queued imports on the priority queue', function (): void {
    config(['queue.default' => 'database']);
    $company = Company::factory()->create();
    app(TenantContext::class)->runForCompany($company, function () use ($company): void {
        $batch = ImportBatch::factory()->create(['company_id' => $company->id]);
        $job = new ImportTransactionsJob($batch->id);
        DB::table('jobs')->insert(['queue' => 'imports', 'payload' => json_encode([
            'displayName' => ImportTransactionsJob::class, 'data' => ['command' => serialize($job)],
        ]), 'attempts' => 0, 'available_at' => time(), 'created_at' => time()]);
        $status = app(ProcessingStatus::class)->for($company);
        expect($status['state'])->toBe('working')->and($status['phase'])->toBe('reading_files');
    });
});
