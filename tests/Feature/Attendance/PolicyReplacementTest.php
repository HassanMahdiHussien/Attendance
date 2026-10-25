<?php

use App\Jobs\Attendance\AssessDaysJob;
use App\Jobs\Leave\ReconcileLeaveJob;
use App\Models\Attendance\Day;
use App\Models\Attendance\Policy;
use App\Models\Company;
use App\Services\Attendance\OfficePolicy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Queue;

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

test('policy replacements preserve each effective date and all unrelated values', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->runForCompany($company, function () use ($company): void {
        Policy::factory()->create(['company_id' => $company->id, 'effective_from' => '2000-01-01',
            'version' => 1, 'min_presence_minutes' => 480, 'flex_minutes' => 30]);
        Policy::factory()->create(['company_id' => $company->id, 'effective_from' => '2026-10-01',
            'version' => 2, 'min_presence_minutes' => 480, 'flex_minutes' => 45]);
    });
    $migration = require database_path('migrations/2026_10_23_060000_replace_office_presence_attendance_policies.php');
    $migration->up();
    $migration->up();

    app(TenantContext::class)->runForCompany($company, function () use ($company): void {
        $policies = Policy::query()->where('company_id', $company->id)->orderBy('version')->get();
        expect($policies)->toHaveCount(4)
            ->and($policies[0]->min_presence_minutes)->toBe(480)
            ->and($policies[1]->min_presence_minutes)->toBe(480)
            ->and($policies[2]->effective_from->toDateString())->toBe('2000-01-01')
            ->and($policies[3]->effective_from->toDateString())->toBe('2026-10-01')
            ->and($policies[2]->flex_minutes)->toBe(30)
            ->and($policies[3]->flex_minutes)->toBe(45)
            ->and($policies[2]->absence_minutes)->toBe(480)
            ->and(app(OfficePolicy::class)->asOf($company, '2026-09-30')->version)->toBe(3)
            ->and(app(OfficePolicy::class)->asOf($company, '2026-10-01')->version)->toBe(4);
    });
});

test('reassess-all queues each company with days without touching policy history', function (): void {
    Queue::fake([AssessDaysJob::class, ReconcileLeaveJob::class]);
    $company = Company::factory()->create();
    app(TenantContext::class)->runForCompany($company, function (): void {
        Day::factory()->create(['work_date' => '2026-10-19']);
    });

    $this->artisan('attendance:reassess-all', ['--company' => $company->id])->assertSuccessful();
    $this->artisan('attendance:reassess-all', ['--company' => $company->id])->assertSuccessful();

    $jobs = Queue::pushed(AssessDaysJob::class);
    expect($jobs)->toHaveCount(1)
        ->and($jobs[0]->scope['from'])->toBe('2026-10-19');
    expect(Queue::pushed(ReconcileLeaveJob::class))->toHaveCount(1);
});
