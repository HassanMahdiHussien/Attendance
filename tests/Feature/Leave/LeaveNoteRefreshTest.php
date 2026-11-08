<?php

use App\Enums\UserRole;
use App\Filament\Admin\Pages\Leave\LeaveDescriptions;
use App\Jobs\Leave\ReconcileLeaveJob;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Leave\DescriptionReading;
use App\Models\Leave\ImportBatch;
use App\Models\Leave\LeaveRequest;
use App\Models\Leave\LeaveType;
use App\Models\Leave\RequestVersion;
use App\Models\User;
use App\Services\Attendance\Ai\HolidayClient;
use App\Services\Leave\LeaveDescriptionEffects;
use App\Services\Leave\LeaveDescriptionReader;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function (): void {
    Queue::fake();
    Filament::setTenant(null);
    $this->company = Company::factory()->create();
    app(TenantContext::class)->setCompany($this->company);
    $this->employee = Employee::factory()->create(['company_id' => $this->company->id]);
    $this->type = LeaveType::factory()->create(['company_id' => $this->company->id, 'name' => 'Annual Leave']);
    $actor = User::factory()->create(['company_id' => $this->company->id, 'primary_role' => UserRole::HrStaff]);
    actingInAdminTenant($this, $actor, $this->company);
});

afterEach(function (): void {
    Filament::setTenant(null);
    app(TenantContext::class)->clear();
});

function hourlyNoteFixture(Company $company, Employee $employee, LeaveType $type, string $status = 'proposed'): DescriptionReading
{
    $request = LeaveRequest::factory()->create(['company_id' => $company->id, 'employee_id' => $employee->id,
        'leave_type_id' => $type->id, 'status' => 'approved', 'start_date' => '2026-11-02',
        'end_date' => '2026-11-02', 'duration_days' => 0.5]);
    $batch = ImportBatch::factory()->create(['company_id' => $company->id]);
    $version = RequestVersion::factory()->create(['company_id' => $company->id,
        'request_id' => $request->id, 'batch_id' => $batch->id, 'row_number' => 2,
        'values' => ['description' => 'Hours last week'], 'raw_values' => []]);
    $request->update(['current_version_id' => $version->id]);

    return DescriptionReading::create(['company_id' => $company->id, 'request_id' => $request->id,
        'text_hash' => hash('sha256', 'Hours last week'), 'category' => 'hourly_settlement',
        'worked_dates' => [], 'confidence' => 'low', 'status' => $status]);
}

test('hourly proposals apply only with confidence and a valid period while compensation still requires confirmation', function (): void {
    $reading = hourlyNoteFixture($this->company, $this->employee, $this->type);
    $effects = app(LeaveDescriptionEffects::class);
    $request = $reading->request;
    expect($effects->applies($request, $reading, 'hourly_settlement'))->toBeFalse();
    $reading->update(['settled_from' => '2026-10-26', 'settled_to' => '2026-11-01']);
    expect($effects->applies($request, $reading->fresh(), 'hourly_settlement'))->toBeFalse();
    $reading->update(['confidence' => 'medium']);
    expect($effects->applies($request, $reading->fresh(), 'hourly_settlement'))->toBeTrue();
    $reading->update(['confidence' => 'high']);
    expect($effects->applies($request, $reading->fresh(), 'hourly_settlement'))->toBeTrue();
    $reading->update(['status' => 'rejected']);
    expect($effects->applies($request, $reading->fresh(), 'hourly_settlement'))->toBeFalse();

    $this->type->update(['name' => 'Compensation Leave']);
    $reading->update(['category' => 'compensation_for_day', 'worked_dates' => ['2026-10-30'], 'status' => 'proposed']);
    expect($effects->applies($request->fresh(), $reading->fresh(), 'compensation_for_day'))->toBeFalse();
    $reading->update(['status' => 'confirmed']);
    expect($effects->applies($request->fresh(), $reading->fresh(), 'compensation_for_day'))->toBeTrue();
});

test('reading a relative hourly note queues reconciliation without confirmation', function (): void {
    $reading = hourlyNoteFixture($this->company, $this->employee, $this->type);
    $reading->update(['category' => null, 'confidence' => null]);
    config()->set('services.anthropic.key', 'test-only-key');
    app()->instance(HolidayClient::class, new class extends HolidayClient
    {
        public function send(array $blocks, array $known, array $schema, string $system, ?callable $onAttempt = null,
            int $maxAttempts = 3, string $effort = 'high', int $timeout = 120): array
        {
            $result = ['model' => HolidayClient::MODEL, 'stop_reason' => 'end_turn',
                'text' => json_encode(['readings' => [['category' => 'hourly_settlement', 'worked_dates' => [],
                    'settled_from' => null, 'settled_to' => null, 'hours' => null, 'confidence' => 'high']]], JSON_THROW_ON_ERROR)];
            $onAttempt?->__invoke('succeeded', null, $result);

            return $result;
        }
    });
    app(LeaveDescriptionReader::class)->read($this->company);
    expect($reading->fresh()->settled_from->toDateString())->toBe('2026-10-26')
        ->and($reading->fresh()->confidence)->toBe('medium')
        ->and($reading->fresh()->status)->toBe('proposed');
    Queue::assertPushed(ReconcileLeaveJob::class, fn (ReconcileLeaveJob $job): bool => $job->companyId === $this->company->id && $job->employeeIds === [$this->employee->id]);
});

test('refresh counts without writing then audits and queues only this tenants applicable employees', function (): void {
    $reading = hourlyNoteFixture($this->company, $this->employee, $this->type);
    $other = Company::factory()->create();
    $otherReading = app(TenantContext::class)->runForCompany($other, function () use ($other): DescriptionReading {
        $employee = Employee::factory()->create(['company_id' => $other->id]);
        $type = LeaveType::factory()->create(['company_id' => $other->id, 'name' => 'Annual Leave']);

        return hourlyNoteFixture($other, $employee, $type);
    });
    $this->artisan('attendance:leave-notes-refresh-periods', ['--company' => $this->company->id, '--dry-run' => true])
        ->expectsOutputToContain('Readings changed: 1; readings now applying: 1; employees to replay: 1.')
        ->assertExitCode(0);
    expect($reading->fresh()->settled_from)->toBeNull()
        ->and(AuditLog::query()->where('action', 'leave.description.period_refreshed')->count())->toBe(0);
    Queue::assertNotPushed(ReconcileLeaveJob::class);

    $this->artisan('attendance:leave-notes-refresh-periods', ['--company' => $this->company->id])
        ->expectsOutputToContain('Readings changed: 1; readings now applying: 1; employees to replay: 1.')
        ->assertExitCode(0);
    $otherStillUnchanged = app(TenantContext::class)->runForCompany($other,
        fn (): bool => DescriptionReading::query()->findOrFail($otherReading->id)->settled_from === null);
    expect($reading->fresh()->settled_from->toDateString())->toBe('2026-10-26')
        ->and($reading->fresh()->settled_to->toDateString())->toBe('2026-11-01')
        ->and($reading->fresh()->confidence)->toBe('medium')
        ->and($otherStillUnchanged)->toBeTrue()
        ->and(AuditLog::query()->where('action', 'leave.description.period_refreshed')->count())->toBe(1);
    Queue::assertPushed(ReconcileLeaveJob::class, fn (ReconcileLeaveJob $job): bool => $job->companyId === $this->company->id && $job->employeeIds === [$this->employee->id]
        && $job->trigger === 'description');
});

test('review outcome shows applied and why an hourly note is not applied', function (): void {
    $reading = hourlyNoteFixture($this->company, $this->employee, $this->type);
    $page = app(LeaveDescriptions::class);
    expect($page->hourlyOutcome($reading))->toBe('no_period');
    Livewire::test(LeaveDescriptions::class)->assertSee(__('messages.leave_descriptions.outcomes.no_period'));
    $reading->update(['settled_from' => '2026-10-26', 'settled_to' => '2026-11-01', 'confidence' => 'medium']);
    expect($page->hourlyOutcome($reading->fresh()))->toBe('applied');
    Livewire::test(LeaveDescriptions::class)->assertSee(__('messages.leave_descriptions.outcomes.applied'));
    $reading->update(['status' => 'rejected']);
    expect($page->hourlyOutcome($reading->fresh()))->toBe('rejected');
    $reading->update(['status' => 'proposed']);
    $reading->request->update(['status' => 'draft']);
    expect($page->hourlyOutcome($reading->fresh()))->toBe('not_approved');
    $reading->request->update(['status' => 'approved']);
    $this->type->update(['name' => 'Compensation Leave']);
    expect($page->hourlyOutcome($reading->fresh()))->toBe('type_mismatch');
    expect(__('messages.leave_descriptions.outcomes.applied'))->toBe('Applied');
});
