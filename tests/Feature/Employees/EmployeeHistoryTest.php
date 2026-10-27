<?php

use App\Enums\UserRole;
use App\Events\EmployeeVersionChanged;
use App\Livewire\Attendance\EmployeeAttendance;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeVersion;
use App\Models\User;
use App\Services\Employees\EmployeeHistory;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-22 12:00:00'));
    $this->company = Company::factory()->create();
    app(TenantContext::class)->setCompany($this->company);
    $this->branch = Branch::factory()->create();
    $this->actor = User::factory()->create(['company_id' => $this->company->id, 'primary_role' => UserRole::HrStaff]);
    $this->employee = Employee::factory()->create(['branch_id' => $this->branch->id, 'hire_date' => '2024-01-01']);
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
    $this->travelBack();
});

test('sparse versions resolve each field independently across back-dated and future changes', function (): void {
    $history = app(EmployeeHistory::class);
    Event::fake([EmployeeVersionChanged::class]);

    $history->record($this->employee, ['department' => 'Later', 'schedule_start' => '09:00'], '2026-11-01', 'manual', $this->actor);
    $backdated = $history->record($this->employee, ['department' => 'Earlier'], '2026-10-01', 'manual', $this->actor, 'Correction');
    $history->record($this->employee, ['schedule_end' => '17:30'], '2026-10-10', 'manual', $this->actor);

    expect($history->asOf($this->employee, '2023-12-31')['department'])->toBeNull()
        ->and($history->asOf($this->employee, '2026-09-30')['department'])->toBe($this->employee->department)
        ->and($history->asOf($this->employee, '2026-10-01')['department'])->toBe('Earlier')
        ->and($history->asOf($this->employee, '2026-10-10')['schedule_end'])->toBe('17:30:00')
        ->and($history->asOf($this->employee, '2026-11-01')['department'])->toBe('Later')
        ->and($history->asOf($this->employee, '2026-11-01')['schedule_end'])->toBe('17:30:00')
        ->and($this->employee->fresh()->department)->toBe('Earlier')
        ->and($this->employee->fresh()->schedule_start)->toBeNull()
        ->and($this->employee->fresh()->schedule_end)->toBe('17:30:00')
        ->and($backdated->changes)->toBe(['department' => 'Earlier'])
        ->and($backdated->previous['department'])->toBe($this->employee->department)
        ->and($backdated->reason)->toBe('Correction');
    Event::assertDispatched(EmployeeVersionChanged::class, fn ($event) => $event->effectiveFrom->toDateString() === '2026-10-01');
});

test('an unchanged value creates no version and schedule end alone leaves other schedule fields intact', function (): void {
    $history = app(EmployeeHistory::class);
    $history->record($this->employee, ['work_pattern' => 'office', 'rest_days' => [5, 6], 'schedule_start' => '08:00'], today(), 'manual', $this->actor);
    $before = EmployeeVersion::count();
    $version = $history->record($this->employee, ['schedule_end' => '16:00'], today(), 'manual', $this->actor);
    expect($version->changes)->toBe(['schedule_end' => '16:00:00'])
        ->and($this->employee->fresh()->work_pattern->value)->toBe('office')
        ->and($this->employee->fresh()->rest_days)->toBe([5, 6])
        ->and($this->employee->fresh()->schedule_start)->toBe('08:00:00')
        ->and(EmployeeVersion::count())->toBe($before + 1);
    expect($history->record($this->employee, ['schedule_end' => '16:00'], today(), 'manual', $this->actor))->toBeNull()
        ->and(EmployeeVersion::count())->toBe($before + 1);
});

test('history omits empty to empty fields and versions without visible changes', function (): void {
    $empty = EmployeeVersion::factory()->create([
        'employee_id' => $this->employee->id,
        'effective_from' => '2025-03-01',
        'changes' => ['email' => '', 'rest_days' => [], 'department' => null],
        'previous' => ['email' => null, 'rest_days' => [], 'department' => ''],
    ]);
    $mixed = EmployeeVersion::factory()->create([
        'employee_id' => $this->employee->id,
        'effective_from' => '2025-04-01',
        'changes' => ['email' => '', 'department' => 'Operations'],
        'previous' => ['email' => null, 'department' => 'Sales'],
    ]);

    expect(EmployeeHistory::visibleFields($empty))->toBe([])
        ->and(EmployeeHistory::visibleFields($mixed))->toBe(['department' => 'Operations']);

    $this->actingAs($this->actor)->get(route('employees.show', $this->employee))->assertOk()
        ->assertDontSee('2025-03-01')->assertSee('2025-04-01')
        ->assertSee('Operations');
    Livewire::test(EmployeeAttendance::class, ['employeeId' => $this->employee->id])
        ->call('show', 'changes')->assertDontSee('2025-03-01')->assertSee('2025-04-01');
});

test('a future version cannot store another company manager or branch', function (): void {
    $other = Company::factory()->create();
    [$otherBranch, $otherManager] = app(TenantContext::class)->runForCompany($other, function (): array {
        return [Branch::factory()->create(), Employee::factory()->create()];
    });
    $history = app(EmployeeHistory::class);
    expect(fn () => $history->record($this->employee, ['manager_employee_id' => $otherManager->id], '2026-11-01', 'manual', $this->actor))
        ->toThrow(ValidationException::class);
    expect(fn () => $history->record($this->employee, ['branch_id' => $otherBranch->id], '2026-11-01', 'manual', $this->actor))
        ->toThrow(ValidationException::class);
    expect(EmployeeVersion::query()->where('employee_id', $this->employee->id)->where('effective_from', '2026-11-01')->exists())->toBeFalse();
});

test('the daily command applies a future version once its date arrives', function (): void {
    $history = app(EmployeeHistory::class);
    $history->record($this->employee, ['work_pattern' => 'shift'], '2026-11-01', 'manual', $this->actor);
    expect($this->employee->fresh()->work_pattern)->toBeNull();

    $this->travelTo(CarbonImmutable::parse('2026-11-01 12:00:00'));
    $this->artisan('employees:apply-effective-changes')->assertSuccessful();
    $this->artisan('employees:apply-effective-changes')->assertSuccessful();
    expect($this->employee->fresh()->work_pattern->value)->toBe('shift');
});
