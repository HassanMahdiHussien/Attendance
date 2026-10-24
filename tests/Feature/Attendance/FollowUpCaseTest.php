<?php

use App\Enums\UserRole;
use App\Models\Attendance\CaseEvent;
use App\Models\Attendance\DataIssue;
use App\Models\Attendance\Day;
use App\Models\Attendance\FollowUpCase;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Leave\LeaveRequest;
use App\Models\Leave\LeaveType;
use App\Models\User;
use App\Services\Attendance\FollowUp\ApproverMappings;
use App\Services\Attendance\FollowUp\CaseSync;
use App\Services\Attendance\FollowUp\NotificationSettings;
use App\Services\Employees\EmployeeHistory;
use App\Services\Leave\LeaveActions;
use App\Services\Leave\Reconciler;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    Queue::fake();
    $this->company = Company::factory()->create();
    app(TenantContext::class)->setCompany($this->company);
    $this->employee = Employee::factory()->create([
        'company_id' => $this->company->id, 'employee_code' => '12345678',
        'hire_date' => '2020-01-01', 'work_pattern' => 'office',
    ]);
    $this->hr = User::factory()->create(['company_id' => $this->company->id, 'primary_role' => UserRole::HrStaff]);
    $this->actingAs($this->hr);
    $this->type = LeaveType::factory()->create(['company_id' => $this->company->id, 'covers' => true, 'is_mapped' => true]);
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

function followUpDay(Employee $employee, string $date, string $status, int $late = 0): Day
{
    return Day::factory()->create([
        'company_id' => $employee->company_id, 'employee_id' => $employee->id,
        'work_date' => $date, 'status' => $status, 'late_minutes' => $late,
    ]);
}

function followUpRequest(Employee $employee, LeaveType $type, string $form, string $date, string $status, ?string $approver = null): LeaveRequest
{
    return LeaveRequest::factory()->create([
        'company_id' => $employee->company_id, 'employee_id' => $employee->id,
        'owner_code' => $employee->employee_code, 'leave_type_id' => $type->id,
        'external_id' => $form, 'status' => $status, 'approver_name' => $approver,
        'start_date' => $date, 'end_date' => $date, 'duration_days' => 1.0,
    ]);
}

function replayFollowUp(Company $company, Employee $employee): void
{
    app(Reconciler::class)->run($company, [$employee->id], 'test');
}

test('approved leave closes an absence with its source and an identical replay writes no case event', function (): void {
    followUpDay($this->employee, '2026-10-19', 'absent');
    replayFollowUp($this->company, $this->employee);
    $case = FollowUpCase::query()->firstOrFail();
    expect($case->items)->toContain(['type' => 'absence', 'minutes' => 480])
        ->and($case->events()->firstOrFail()->before)->toBeNull()
        ->and($case->events()->firstOrFail()->after['state'])->toBe('waiting_hr');

    followUpRequest($this->employee, $this->type, 'FORM-1', '2026-10-19', 'approved');
    replayFollowUp($this->company, $this->employee);
    $case->refresh();
    expect($case->state)->toBe('closed')
        ->and($case->request_ids)->toContain(LeaveRequest::query()->where('external_id', 'FORM-1')->firstOrFail()->id)
        ->and($case->closure_source['request_numbers'])->toContain('FORM-1');
    $count = CaseEvent::query()->count();
    replayFollowUp($this->company, $this->employee);
    expect(CaseEvent::query()->count())->toBe($count);
});

test('pending requests route per approver and a missing punch routes only to the manager', function (): void {
    $manager = Employee::factory()->create(['company_id' => $this->company->id, 'email' => 'manager@example.test']);
    app(EmployeeHistory::class)->record($this->employee, ['manager_employee_id' => $manager->id], '2026-10-01', 'manual', $this->hr);
    followUpDay($this->employee, '2026-10-19', 'absent');
    followUpDay($this->employee, '2026-10-20', 'missing_punch');
    foreach (['2026-10-19', '2026-10-20'] as $date) {
        followUpRequest($this->employee, $this->type, 'FORM-A-'.$date, $date, 'pending', 'First Approver');
        followUpRequest($this->employee, $this->type, 'FORM-B-'.$date, $date, 'pending', 'Second Approver');
    }
    replayFollowUp($this->company, $this->employee);

    $absence = FollowUpCase::query()->whereDate('work_date', '2026-10-19')->firstOrFail();
    expect($absence->state)->toBe('waiting_hr')
        ->and(collect($absence->routes)->where('kind', 'approver'))->toHaveCount(2)
        ->and(collect($absence->routes)->where('kind', 'manager'))->toHaveCount(0)
        ->and(DataIssue::query()->where('type', 'approver_unmapped')->count())->toBe(2);
    $punch = FollowUpCase::query()->whereDate('work_date', '2026-10-20')->firstOrFail();
    expect(collect($punch->routes)->where('kind', 'approver'))->toHaveCount(0)
        ->and(collect($punch->routes)->firstWhere('kind', 'manager')['recipient_employee_id'])->toBe($manager->id)
        ->and($punch->state)->toBe('open');
});

test('mapping confirmation resolves the grouped issue without guessing an approver', function (): void {
    followUpDay($this->employee, '2026-10-19', 'absent');
    followUpRequest($this->employee, $this->type, 'FORM-A', '2026-10-19', 'pending', 'Named Approver');
    replayFollowUp($this->company, $this->employee);
    expect(FollowUpCase::query()->firstOrFail()->routes[0]['recipient_employee_id'])->toBeNull();
    expect(app(ApproverMappings::class)->unmapped($this->company))->toContain('Named Approver');

    $approver = Employee::factory()->create(['company_id' => $this->company->id, 'email' => 'approver@example.test']);
    app(EmployeeHistory::class)->record($approver, ['name_en' => 'Exact Approver'], today(), 'manual', $this->hr);
    expect(collect(app(ApproverMappings::class)->suggest($this->company, '  exact  APPROVER '))->pluck('id'))
        ->toContain($approver->id);
    app(ApproverMappings::class)->confirm($this->company, 'Named Approver', $approver, $this->hr);
    expect(FollowUpCase::query()->firstOrFail()->fresh()->routes[0]['recipient_employee_id'])->toBe($approver->id)
        ->and(FollowUpCase::query()->firstOrFail()->state)->toBe('waiting_approver')
        ->and(DataIssue::query()->where('type', 'approver_unmapped')->where('status', 'open')->count())->toBe(0)
        ->and(app(ApproverMappings::class)->unmapped($this->company))->toBe([]);
});

test('a mapped approver without an address waits for HR and never uses the manager fallback', function (): void {
    app(EmployeeHistory::class)->record($this->employee,
        ['fallback_notification_email' => 'fallback@example.test'], '2026-10-01', 'manual', $this->hr);
    $approver = Employee::factory()->create(['company_id' => $this->company->id, 'email' => null]);
    app(ApproverMappings::class)->confirm($this->company, 'Approver', $approver, $this->hr);
    followUpDay($this->employee, '2026-10-19', 'absent');
    followUpRequest($this->employee, $this->type, 'FORM-A', '2026-10-19', 'pending', 'Approver');
    replayFollowUp($this->company, $this->employee);

    $case = FollowUpCase::query()->firstOrFail();
    expect($case->state)->toBe('waiting_hr')
        ->and($case->routes[0]['recipient_employee_id'])->toBe($approver->id)
        ->and($case->routes[0]['recipient_available'])->toBeFalse()
        ->and(DataIssue::query()->where('type', 'notification_recipient_missing')->where('status', 'open')->count())->toBe(1);
});

test('rejection returns an approver case to the manager and removed days close cases', function (): void {
    $manager = Employee::factory()->create(['company_id' => $this->company->id, 'email' => 'manager@example.test']);
    app(EmployeeHistory::class)->record($this->employee, ['manager_employee_id' => $manager->id], '2026-10-01', 'manual', $this->hr);
    $approver = Employee::factory()->create(['company_id' => $this->company->id, 'email' => 'approver@example.test']);
    app(ApproverMappings::class)->confirm($this->company, 'Approver', $approver, $this->hr);
    $day = followUpDay($this->employee, '2026-10-19', 'absent');
    $request = followUpRequest($this->employee, $this->type, 'FORM-A', '2026-10-19', 'pending', 'Approver');
    replayFollowUp($this->company, $this->employee);
    expect(FollowUpCase::query()->firstOrFail()->state)->toBe('waiting_approver');

    $request->update(['status' => 'rejected']);
    replayFollowUp($this->company, $this->employee);
    expect(FollowUpCase::query()->firstOrFail()->state)->toBe('open')
        ->and(CaseEvent::query()->latest('id')->value('event'))->toBe('reopened');

    $day->update(['superseded_by_run_id' => $day->run_id]);
    replayFollowUp($this->company, $this->employee);
    expect(FollowUpCase::query()->firstOrFail()->state)->toBe('closed')
        ->and(FollowUpCase::query()->firstOrFail()->closure_reason)->toBe('reassessment');
});

test('cancelling pending leave returns its follow-up items to the manager', function (): void {
    $manager = Employee::factory()->create(['company_id' => $this->company->id, 'email' => 'manager@example.test']);
    app(EmployeeHistory::class)->record($this->employee, ['manager_employee_id' => $manager->id], '2026-10-01', 'manual', $this->hr);
    $approver = Employee::factory()->create(['company_id' => $this->company->id, 'email' => 'approver@example.test']);
    app(ApproverMappings::class)->confirm($this->company, 'Approver', $approver, $this->hr);
    followUpDay($this->employee, '2026-10-19', 'absent');
    $request = followUpRequest($this->employee, $this->type, 'FORM-A', '2026-10-19', 'pending', 'Approver');
    replayFollowUp($this->company, $this->employee);
    expect(FollowUpCase::query()->firstOrFail()->state)->toBe('waiting_approver');

    app(LeaveActions::class)->cancel($request, 'Request withdrawn', $this->hr);
    replayFollowUp($this->company, $this->employee);
    $case = FollowUpCase::query()->firstOrFail();
    expect($case->state)->toBe('open')
        ->and($case->routes[0]['kind'])->toBe('manager')
        ->and($case->routes[0]['recipient_employee_id'])->toBe($manager->id)
        ->and(CaseEvent::query()->latest('id')->value('event'))->toBe('reopened');
});

test('manual closure survives identical replay and reopens when remaining items change', function (): void {
    $day = followUpDay($this->employee, '2026-10-19', 'present', 15);
    replayFollowUp($this->company, $this->employee);
    $case = FollowUpCase::query()->firstOrFail();
    app(CaseSync::class)->closeManually($case, 'Reviewed source record', $this->hr);
    replayFollowUp($this->company, $this->employee);
    expect($case->fresh()->state)->toBe('closed')
        ->and($case->fresh()->manual_closed_items)->toHaveCount(1);

    $day->update(['late_minutes' => 30]);
    replayFollowUp($this->company, $this->employee);
    expect($case->fresh()->state)->not->toBe('closed')
        ->and($case->fresh()->manual_closed_items)->toBeNull();
});

test('manager and fallback changes reroute a case and resolve a missing-recipient issue', function (): void {
    followUpDay($this->employee, '2026-10-19', 'absent');
    replayFollowUp($this->company, $this->employee);
    expect(FollowUpCase::query()->firstOrFail()->state)->toBe('waiting_hr')
        ->and(DataIssue::query()->where('type', 'notification_recipient_missing')->where('status', 'open')->count())->toBe(1);

    app(EmployeeHistory::class)->record($this->employee,
        ['fallback_notification_email' => 'fallback@example.test'], '2026-10-01', 'manual', $this->hr);
    app(CaseSync::class)->reroute($this->employee);
    expect(FollowUpCase::query()->firstOrFail()->state)->toBe('open')
        ->and(FollowUpCase::query()->firstOrFail()->routes[0]['recipient_available'])->toBeTrue()
        ->and(DataIssue::query()->where('type', 'notification_recipient_missing')->where('status', 'open')->count())->toBe(0);

    $manager = Employee::factory()->create(['company_id' => $this->company->id, 'email' => 'manager@example.test']);
    app(EmployeeHistory::class)->record($this->employee, ['manager_employee_id' => $manager->id], '2026-10-01', 'manual', $this->hr);
    app(CaseSync::class)->reroute($this->employee);
    $case = FollowUpCase::query()->firstOrFail()->fresh();
    expect($case->state)->toBe('open')
        ->and($case->routes[0]['recipient_employee_id'])->toBe($manager->id)
        ->and(DataIssue::query()->where('type', 'notification_recipient_missing')->where('status', 'open')->count())->toBe(0);
});

test('a pending status change reroutes without an allocation change and a draft never routes', function (): void {
    $manager = Employee::factory()->create(['company_id' => $this->company->id, 'email' => 'manager@example.test']);
    app(EmployeeHistory::class)->record($this->employee, ['manager_employee_id' => $manager->id], '2026-10-01', 'manual', $this->hr);
    $approver = Employee::factory()->create(['company_id' => $this->company->id, 'email' => 'approver@example.test']);
    app(ApproverMappings::class)->confirm($this->company, 'Approver', $approver, $this->hr);
    followUpDay($this->employee, '2026-10-19', 'absent');
    $request = followUpRequest($this->employee, $this->type, 'FORM-A', '2026-10-19', 'draft', 'Approver');
    replayFollowUp($this->company, $this->employee);
    expect(FollowUpCase::query()->firstOrFail()->state)->toBe('open');

    $request->update(['status' => 'pending']);
    replayFollowUp($this->company, $this->employee);
    expect(FollowUpCase::query()->firstOrFail()->state)->toBe('waiting_approver')
        ->and(CaseEvent::query()->latest('id')->value('event'))->toBe('changed');
});

test('a foreign company cannot edit mappings or settings through the current tenant', function (): void {
    $other = Company::factory()->create();
    $otherEmployee = app(TenantContext::class)->runForCompany($other,
        fn (): Employee => Employee::factory()->create(['company_id' => $other->id]));
    $otherCase = app(TenantContext::class)->runForCompany($other,
        fn (): FollowUpCase => FollowUpCase::factory()->create(['employee_id' => $otherEmployee->id]));

    expect(fn () => app(ApproverMappings::class)->confirm($this->company, 'Approver', $otherEmployee, $this->hr))
        ->toThrow(ValidationException::class);
    expect(fn () => app(NotificationSettings::class)->forCompany($other))
        ->toThrow(HttpException::class);
    expect(fn () => app(CaseSync::class)->closeManually($otherCase, 'Wrong company', $this->hr))
        ->toThrow(HttpException::class);
    expect(FollowUpCase::query()->where('company_id', $other->id)->count())->toBe(0);
});

test('platform admin can confirm a mapping through an explicit company context', function (): void {
    $admin = User::factory()->create(['primary_role' => UserRole::PlatformAdmin]);
    $this->actingAs($admin);
    $mapping = app(ApproverMappings::class)->confirm($this->company, 'Named Approver', $this->employee, $admin);
    expect($mapping->company_id)->toBe($this->company->id)
        ->and($mapping->employee_id)->toBe($this->employee->id);
});

test('excluded day statuses and overtime alone never open a case', function (): void {
    foreach (['pending', 'device_outage', 'not_employed', 'rest_day', 'holiday', 'pattern_missing', 'shift_unresolved'] as $index => $status) {
        followUpDay($this->employee, '2026-10-'.sprintf('%02d', 19 + $index), $status, 30);
    }
    followUpDay($this->employee, '2026-10-27', 'present');
    replayFollowUp($this->company, $this->employee);
    expect(FollowUpCase::query()->count())->toBe(0);
});

test('notification settings and mapping changes require the dedicated gate and company context', function (): void {
    $settings = app(NotificationSettings::class)->forCompany($this->company);
    expect($settings->mode)->toBe('review')
        ->and($settings->reminder_interval_days)->toBe(2)
        ->and($settings->reminder_max)->toBe(3);
    app(NotificationSettings::class)->set($this->company, [
        'mode' => 'auto', 'build_time' => '10:00', 'reminder_interval_days' => 3,
        'reminder_max' => 2, 'language' => 'ar', 'reason' => 'HR approved',
    ], $this->hr);
    expect(app(NotificationSettings::class)->forCompany($this->company)->mode)->toBe('auto');

    $outsider = User::factory()->create(['company_id' => $this->company->id, 'primary_role' => UserRole::HrStaff, 'is_active' => false]);
    $this->actingAs($outsider);
    expect(fn () => app(NotificationSettings::class)->set($this->company, [
        'mode' => 'review', 'build_time' => '09:00', 'reminder_interval_days' => 2,
        'reminder_max' => 3, 'language' => 'en', 'reason' => 'Denied',
    ], $outsider))->toThrow(AuthorizationException::class);
    expect(fn () => app(ApproverMappings::class)->confirm($this->company, 'Approver', $this->employee, $outsider))
        ->toThrow(AuthorizationException::class);
});
