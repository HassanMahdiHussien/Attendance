<?php

use App\Enums\UserRole;
use App\Filament\Admin\Pages\Attendance\AttendanceIssues;
use App\Filament\Admin\Resources\EmployeeResource\Pages\EditEmployee;
use App\Models\Attendance\DataIssue;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\Employees\EmployeeHistory;
use App\Services\Employees\PatternRule;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->company = Company::factory()->create();
    app(TenantContext::class)->setCompany($this->company);
    $this->branch = Branch::factory()->create();
    $this->actor = User::factory()->create(['company_id' => $this->company->id, 'primary_role' => UserRole::HrStaff]);
});

afterEach(function (): void {
    Filament::setTenant(null);
    app(TenantContext::class)->clear();
});

test('security position wins over collar and the rule records its own version', function (): void {
    $security = Employee::factory()->create(['branch_id' => $this->branch->id, 'position' => 'Security Guard', 'staff_category' => 'B']);
    $blue = Employee::factory()->create(['branch_id' => $this->branch->id, 'position' => 'Technician', 'staff_category' => 'B']);
    $white = Employee::factory()->create(['branch_id' => $this->branch->id, 'position' => 'Analyst', 'staff_category' => 'W']);
    $unknown = Employee::factory()->create(['branch_id' => $this->branch->id, 'position' => 'Analyst']);
    $rules = app(PatternRule::class);

    expect($rules->apply($security, today()))->not->toBeNull()
        ->and($rules->apply($blue, today()))->not->toBeNull()
        ->and($rules->apply($white, today()))->not->toBeNull()
        ->and($rules->apply($unknown, today()))->toBeNull()
        ->and($security->fresh()->work_pattern?->value)->toBe('security')
        ->and($blue->fresh()->work_pattern?->value)->toBe('shift')
        ->and($white->fresh()->work_pattern?->value)->toBe('office')
        ->and($unknown->fresh()->work_pattern)->toBeNull();

    $version = $security->versions()->where('source', 'rule')->firstOrFail();
    expect($version->reason)->toContain('security_position')
        ->and($version->changes)->toBe(['work_pattern' => 'security']);
});

test('a set pattern stays set and an HR change resolves a flagged security review', function (): void {
    $employee = Employee::factory()->create([
        'branch_id' => $this->branch->id, 'position' => 'Information Security Manager', 'staff_category' => 'W',
    ]);
    $rules = app(PatternRule::class);
    $rules->apply($employee, today());
    $issue = DataIssue::query()->where('type', 'pattern_review')->sole();

    expect($employee->fresh()->work_pattern?->value)->toBe('security')
        ->and($issue->subject_key)->toBe('employee:'.$employee->id)
        ->and($issue->status)->toBe('open')
        ->and($rules->apply($employee, today()))->toBeNull()
        ->and(DataIssue::query()->where('type', 'pattern_review')->count())->toBe(1);

    app(EmployeeHistory::class)->record($employee, ['work_pattern' => 'office'], today(), 'manual', $this->actor);
    expect($employee->fresh()->work_pattern?->value)->toBe('office')
        ->and($rules->source($employee))->toBe('manual')
        ->and($issue->fresh()->status)->toBe('resolved')
        ->and($rules->apply($employee, today()))->toBeNull();
});

test('the existing-employee command applies rules once within each company', function (): void {
    $employee = Employee::factory()->create(['branch_id' => $this->branch->id, 'position' => 'Technician', 'staff_category' => 'B']);
    $other = Company::factory()->create();
    $otherEmployee = app(TenantContext::class)->runForCompany($other, fn () => Employee::factory()->create([
        'position' => 'Analyst', 'staff_category' => 'W',
    ]));

    $this->artisan('employees:apply-pattern-rules')->assertSuccessful();
    $this->artisan('employees:apply-pattern-rules')->assertSuccessful();

    expect($employee->fresh()->work_pattern?->value)->toBe('shift')
        ->and($employee->versions()->where('source', 'rule')->count())->toBe(1);
    app(TenantContext::class)->runForCompany($other, function () use ($otherEmployee): void {
        expect($otherEmployee->fresh()->work_pattern?->value)->toBe('office')
            ->and($otherEmployee->versions()->where('source', 'rule')->count())->toBe(1);
    });
});

test('HR may dismiss a pattern review but cannot resolve it without changing the pattern', function (): void {
    $employee = Employee::factory()->create([
        'branch_id' => $this->branch->id, 'position' => 'Security Manager', 'staff_category' => 'B',
    ]);
    app(PatternRule::class)->apply($employee, today());
    $issue = DataIssue::query()->where('type', 'pattern_review')->sole();
    actingInAdminTenant($this, $this->actor, $this->company);

    Livewire::test(AttendanceIssues::class)->set('notes.'.$issue->id, 'Reviewed.')->call('close', $issue->id, 'resolve')->assertForbidden();
    expect($issue->fresh()->status)->toBe('open');
    Livewire::test(AttendanceIssues::class)->set('notes.'.$issue->id, 'Security role confirmed.')
        ->call('close', $issue->id, 'dismiss');
    expect($issue->fresh()->status)->toBe('dismissed');
});

test('employee profile and edit form show a rule-set pattern with its open review issue', function (): void {
    $employee = Employee::factory()->create([
        'branch_id' => $this->branch->id, 'position' => 'Information Security Manager', 'staff_category' => 'B',
    ]);
    app(PatternRule::class)->apply($employee, today());
    expect(app(PatternRule::class)->source($employee))->toBe('rule');

    $this->actingAs($this->actor)->get(route('employees.show', $employee))->assertOk()
        ->assertSee('Set by rule')->assertSee('Review work pattern issue');
    actingInAdminTenant($this, $this->actor, $this->company);
    Livewire::test(EditEmployee::class, ['record' => $employee->id])
        ->assertSee('Set by rule')->assertSee('Review work pattern issue');
    Livewire::withQueryParams(['type' => 'pattern_review', 'subjectKey' => 'employee:'.$employee->id])
        ->test(AttendanceIssues::class)->assertSee('employee:'.$employee->id);
});

test('a rule-set pattern covers the whole employment so earlier punches are assessed', function (): void {
    // Real data: the rule runs on the day HR imports the list, but punches from earlier months are imported too.
    $employee = Employee::factory()->create([
        'branch_id' => $this->branch->id, 'position' => 'Technician', 'staff_category' => 'B', 'hire_date' => '2015-03-01',
    ]);

    app(PatternRule::class)->apply($employee, '2026-10-22');

    $history = app(EmployeeHistory::class);
    expect($history->asOf($employee, '2026-10-18')['work_pattern'])->toBe('shift')
        ->and($history->asOf($employee, '2015-03-01')['work_pattern'])->toBe('shift')
        ->and($employee->fresh()->work_pattern?->value)->toBe('shift');
});
