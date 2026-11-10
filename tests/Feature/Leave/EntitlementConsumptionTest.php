<?php

use App\Livewire\Attendance\EmployeeAttendance;
use App\Models\Attendance\DataIssue;
use App\Models\Attendance\Day;
use App\Models\Attendance\RestDayEntitlement;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Leave\Allocation;
use App\Models\Leave\Credit;
use App\Models\Leave\DescriptionReading;
use App\Models\Leave\EntitlementConsumption;
use App\Models\Leave\ImportBatch;
use App\Models\Leave\LeaveRequest;
use App\Models\Leave\LeaveType;
use App\Models\Leave\RequestVersion;
use App\Models\User;
use App\Services\Attendance\Overtime;
use App\Services\Attendance\Reporting\AttendanceSummary;
use App\Services\Attendance\Reporting\DayPresentation;
use App\Services\Attendance\Reporting\ReportFilters;
use App\Services\Leave\LeaveActions;
use App\Services\Leave\Reconciler;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    Queue::fake();
    $this->company = Company::factory()->create();
    app(TenantContext::class)->setCompany($this->company);
    $this->employee = Employee::factory()->create([
        'company_id' => $this->company->id, 'employee_code' => '00001234',
        'hire_date' => '2020-01-01', 'work_pattern' => 'office',
    ]);
    $this->actor = User::factory()->create(['company_id' => $this->company->id, 'primary_role' => 'hr_staff']);
    $this->actingAs($this->actor);
    $this->type = LeaveType::factory()->create([
        'covers' => true, 'is_mapped' => true, 'consumes' => 'rest_day_entitlement',
    ]);
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

function overtimeRequest(Employee $employee, LeaveType $type, string $form, string $date, float $quantity): LeaveRequest
{
    return LeaveRequest::factory()->create([
        'company_id' => $employee->company_id, 'employee_id' => $employee->id,
        'owner_code' => $employee->employee_code, 'leave_type_id' => $type->id,
        'external_id' => $form, 'status' => 'approved', 'start_date' => $date,
        'end_date' => $date, 'duration_days' => $quantity,
    ]);
}

function overtimeEntitlement(Employee $employee, string $date, float $quantity): RestDayEntitlement
{
    $source = Day::factory()->create([
        'company_id' => $employee->company_id, 'employee_id' => $employee->id,
        'work_date' => $date, 'status' => 'rest_day_worked',
    ]);

    return RestDayEntitlement::factory()->create([
        'company_id' => $employee->company_id, 'employee_id' => $employee->id,
        'source_day_id' => $source->id, 'work_date' => $date,
        'quantity' => $quantity, 'worked_minutes' => (int) ($quantity * 480),
    ]);
}

function replayOvertimeLeave(Company $company, Employee $employee): void
{
    app(Reconciler::class)->run($company, [$employee->id], 'test');
}

function compensationReading(LeaveRequest $request, string $date, string $confidence = 'high', string $status = 'proposed'): DescriptionReading
{
    return DescriptionReading::create(['company_id' => $request->company_id, 'request_id' => $request->id,
        'text_hash' => hash('sha256', $request->external_id.$date), 'category' => 'compensation_for_day',
        'worked_dates' => [$date], 'confidence' => $confidence, 'status' => $status]);
}

test('half-day requests split one full entitlement and an identical replay changes no rows', function (): void {
    $entitlement = overtimeEntitlement($this->employee, '2026-10-16', 1.0);
    overtimeRequest($this->employee, $this->type, 'A', '2026-10-19', 0.5);
    overtimeRequest($this->employee, $this->type, 'B', '2026-10-20', 0.5);
    replayOvertimeLeave($this->company, $this->employee);
    $rows = EntitlementConsumption::query()->whereNull('superseded_by_run_id')->orderBy('id')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('entitlement_id')->all())->toBe([$entitlement->id, $entitlement->id])
        ->and($rows->pluck('quantity')->all())->toBe(['0.5', '0.5'])
        ->and(Credit::count())->toBe(0);

    replayOvertimeLeave($this->company, $this->employee);
    expect(EntitlementConsumption::count())->toBe(2)
        ->and(EntitlementConsumption::query()->whereNull('superseded_by_run_id')->pluck('id')->all())
        ->toBe($rows->pluck('id')->all());
    $filters = ReportFilters::fromArray($this->company, ['from' => '2026-10-16', 'to' => '2026-10-16']);
    $totals = app(Overtime::class)->totals($filters, '2026-10-25');
    $ledger = iterator_to_array(app(Overtime::class)->entitlements($filters, '2026-10-25'));
    expect($totals['entitlements_earned'])->toBe(1.0)
        ->and($totals['entitlements_used'])->toBe(1.0)
        ->and($totals['entitlements_available_now'])->toBe(0.0)
        ->and($ledger[0]['used'])->toBe(1.0)
        ->and($ledger[0]['available'])->toBe(0.0);
});

test('the second full request is flagged without partial spending and cancellation restores capacity', function (): void {
    overtimeEntitlement($this->employee, '2026-10-16', 1.0);
    $first = overtimeRequest($this->employee, $this->type, 'A', '2026-10-19', 1.0);
    $second = overtimeRequest($this->employee, $this->type, 'B', '2026-10-20', 1.0);
    Day::factory()->create(['company_id' => $this->company->id, 'employee_id' => $this->employee->id,
        'work_date' => '2026-10-19', 'status' => 'absent']);
    replayOvertimeLeave($this->company, $this->employee);
    expect(EntitlementConsumption::query()->whereNull('superseded_by_run_id')->pluck('request_id')->all())->toBe([$first->id])
        ->and($second->fresh()->flags)->toContain('entitlement_exceeded')
        ->and(DataIssue::query()->where('type', 'leave_entitlement_exceeded')->where('status', 'open')->count())->toBe(1)
        ->and(Allocation::query()->whereNull('superseded_by_run_id')->count())->toBe(1)
        ->and(Credit::query()->whereNull('superseded_by_run_id')->count())->toBe(0);

    app(LeaveActions::class)->cancel($first, 'Corrected report', $this->actor);
    replayOvertimeLeave($this->company, $this->employee);
    expect(EntitlementConsumption::query()->whereNull('superseded_by_run_id')->pluck('request_id')->all())->toBe([$second->id])
        ->and($second->fresh()->flags)->not->toContain('entitlement_exceeded')
        ->and(DataIssue::query()->where('type', 'leave_entitlement_exceeded')->where('status', 'open')->count())->toBe(0);

    app(LeaveActions::class)->cancel($second, 'Corrected report', $this->actor);
    replayOvertimeLeave($this->company, $this->employee);
    expect(EntitlementConsumption::query()->whereNull('superseded_by_run_id')->count())->toBe(0);
});

test('superseding an entitlement rebuilds the consumption against its current version', function (): void {
    $first = overtimeEntitlement($this->employee, '2026-10-16', 1.0);
    overtimeRequest($this->employee, $this->type, 'A', '2026-10-19', 0.5);
    replayOvertimeLeave($this->company, $this->employee);
    $oldConsumption = EntitlementConsumption::query()->whereNull('superseded_by_run_id')->firstOrFail();

    $first->update(['superseded_by_run_id' => $first->run_id]);
    $second = overtimeEntitlement($this->employee, '2026-10-16', 1.0);
    replayOvertimeLeave($this->company, $this->employee);
    expect($oldConsumption->fresh()->superseded_by_run_id)->not->toBeNull()
        ->and(EntitlementConsumption::query()->whereNull('superseded_by_run_id')->firstOrFail()->entitlement_id)
        ->toBe($second->id);
});

test('reconciliation rejects a company outside the tenant context', function (): void {
    $other = Company::factory()->create();
    app(TenantContext::class)->runForCompany($this->company, function () use ($other): void {
        expect(fn () => app(Reconciler::class)->run($other, [], 'test'))->toThrow(HttpException::class);
    });
});

test('proposed worked date takes its named Friday and rejected or low confidence falls back to oldest', function (): void {
    $this->type->update(['name' => 'Compensation Leave']);
    $old = overtimeEntitlement($this->employee, '2026-10-02', 1.0);
    $worked = overtimeEntitlement($this->employee, '2026-10-09', 1.0);
    $request = overtimeRequest($this->employee, $this->type, 'INVENTED-COMP', '2026-10-15', 1.0);
    $batch = ImportBatch::factory()->create(['company_id' => $this->company->id]);
    $version = RequestVersion::factory()->create(['company_id' => $this->company->id,
        'request_id' => $request->id, 'batch_id' => $batch->id, 'row_number' => 2,
        'values' => ['description' => 'compensation 09.10.2026'], 'raw_values' => []]);
    $request->update(['current_version_id' => $version->id]);
    $reading = compensationReading($request, '2026-10-09');
    replayOvertimeLeave($this->company, $this->employee);
    expect(EntitlementConsumption::query()->whereNull('superseded_by_run_id')->sole()->entitlement_id)->toBe($worked->id);
    $reading->update(['confidence' => 'low']);
    replayOvertimeLeave($this->company, $this->employee);
    expect(EntitlementConsumption::query()->whereNull('superseded_by_run_id')->sole()->entitlement_id)->toBe($old->id);
    $reading->update(['confidence' => 'high', 'status' => 'rejected']);
    replayOvertimeLeave($this->company, $this->employee);
    expect(EntitlementConsumption::query()->whereNull('superseded_by_run_id')->sole()->entitlement_id)->toBe($old->id);
});

test('two notes naming one Friday spend it once and flag the second request', function (): void {
    $this->type->update(['name' => 'Compensation Leave']);
    $old = overtimeEntitlement($this->employee, '2026-10-02', 1.0);
    $named = overtimeEntitlement($this->employee, '2026-10-09', 1.0);
    $first = overtimeRequest($this->employee, $this->type, 'COMP-FIRST', '2026-10-15', 1.0);
    $second = overtimeRequest($this->employee, $this->type, 'COMP-SECOND', '2026-10-16', 1.0);
    compensationReading($first, '2026-10-09');
    compensationReading($second, '2026-10-09');
    replayOvertimeLeave($this->company, $this->employee);

    $used = EntitlementConsumption::query()->whereNull('superseded_by_run_id')->get()->keyBy('request_id');
    expect($used)->toHaveCount(2)
        ->and($used[$first->id]->entitlement_id)->toBe($named->id)
        ->and($used[$second->id]->entitlement_id)->toBe($old->id)
        ->and($second->fresh()->flags)->toContain('described_work_date_no_credit');
});

test('a note naming an unworked Friday is flagged even when another credit is available', function (): void {
    $this->type->update(['name' => 'Compensation Leave']);
    $old = overtimeEntitlement($this->employee, '2026-10-02', 1.0);
    $request = overtimeRequest($this->employee, $this->type, 'COMP-UNWORKED', '2026-10-15', 1.0);
    Day::factory()->create(['company_id' => $this->company->id, 'employee_id' => $this->employee->id,
        'work_date' => '2026-10-15', 'status' => 'absent']);
    compensationReading($request, '2026-10-09');
    replayOvertimeLeave($this->company, $this->employee);

    expect(EntitlementConsumption::query()->whereNull('superseded_by_run_id')->sole()->entitlement_id)->toBe($old->id)
        ->and($request->fresh()->flags)->toContain('described_work_date_no_credit');
    $filters = ReportFilters::fromArray($this->company, ['from' => '2026-10-15', 'to' => '2026-10-15']);
    $day = collect(iterator_to_array(app(AttendanceSummary::class)->perDay($filters, $this->employee->id)))->sole();
    $presentation = app(DayPresentation::class)->from($day);
    expect($presentation['needs_review'])->toBeTrue()
        ->and($presentation['tone'])->toBe('amber')
        ->and($presentation['problems'])->toContain('Note names Fri 9 Oct but no free worked day was found (not worked or already used)');
});

test('employee attendance shows checked leave and worked-day notes', function (): void {
    $this->type->update(['name' => 'Compensation Leave']);
    $entitlement = overtimeEntitlement($this->employee, '2026-10-09', 1.0);
    $entitlement->sourceDay->update(['first_in' => '2026-10-09 08:00:00', 'last_out' => '2026-10-09 17:00:00']);
    Day::factory()->create(['company_id' => $this->company->id, 'employee_id' => $this->employee->id,
        'work_date' => '2026-10-15', 'status' => 'absent']);
    $request = overtimeRequest($this->employee, $this->type, 'COMP-CHECKED', '2026-10-15', 1.0);
    compensationReading($request, '2026-10-09');
    replayOvertimeLeave($this->company, $this->employee);

    app()->setLocale('en');
    Livewire::withQueryParams(['from' => '2026-10-01', 'to' => '2026-10-31'])
        ->test(EmployeeAttendance::class, ['employeeId' => $this->employee->id])
        ->call('setDayFilter', 'all')
        ->assertSee('Compensation for Fri 9 Oct checked: worked 08:00–17:00, used once')
        ->assertSee('Used by leave COMP-CHECKED on Thu 15 Oct');
    foreach (['compensation_checked_times', 'compensation_checked', 'compensation_missing',
        'compensation_used', 'compensation_worked_day'] as $key) {
        expect(trans("messages.day_presentation.$key", [], 'ar'))->not->toBe("messages.day_presentation.$key");
    }
});
