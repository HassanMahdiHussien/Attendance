<?php

use App\Enums\UserRole;
use App\Jobs\Attendance\AssessDaysJob;
use App\Models\Attendance\Day;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Leave\LeaveRequest;
use App\Models\Leave\LeaveType;
use App\Models\User;
use App\Services\Leave\Reconciler;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
    $this->company = Company::factory()->create();
    app(TenantContext::class)->setCompany($this->company);
    $this->actingAs(User::factory()->create(['company_id' => $this->company->id, 'primary_role' => UserRole::HrStaff]));
    $this->type = LeaveType::factory()->create(['company_id' => $this->company->id, 'covers' => true, 'is_mapped' => true]);
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

function dispatchEmployee(Company $company, string $code): Employee
{
    return Employee::factory()->create([
        'company_id' => $company->id, 'employee_code' => $code,
        'hire_date' => '2020-01-01', 'work_pattern' => 'office',
    ]);
}

function dispatchLeave(Employee $employee, LeaveType $type, string $form, string $date): LeaveRequest
{
    return LeaveRequest::factory()->create([
        'company_id' => $employee->company_id, 'employee_id' => $employee->id,
        'owner_code' => $employee->employee_code, 'leave_type_id' => $type->id,
        'external_id' => $form, 'status' => 'approved',
        'start_date' => $date, 'end_date' => $date, 'duration_days' => 1.0,
    ]);
}

test('employees whose leave changed the same existing days share one assessment job', function (): void {
    $first = dispatchEmployee($this->company, '10000001');
    $second = dispatchEmployee($this->company, '10000002');
    foreach ([$first, $second] as $employee) {
        Day::factory()->create(['company_id' => $this->company->id, 'employee_id' => $employee->id,
            'work_date' => '2026-10-19', 'status' => 'absent']);
        dispatchLeave($employee, $this->type, 'FORM-'.$employee->employee_code, '2026-10-19');
    }

    app(Reconciler::class)->run($this->company, [$first->id, $second->id], 'test');

    Queue::assertPushed(AssessDaysJob::class, 1);
    Queue::assertPushed(AssessDaysJob::class, fn (AssessDaysJob $job): bool => $job->scope['employee_ids'] === [$first->id, $second->id]
        && $job->scope['from'] === '2026-10-18' && $job->scope['to'] === '2026-10-20');
});

test('leave on dates without an assessed day queues no assessment', function (): void {
    $employee = dispatchEmployee($this->company, '10000003');
    dispatchLeave($employee, $this->type, 'FORM-OLD', '2026-03-15');

    app(Reconciler::class)->run($this->company, [$employee->id], 'test');

    Queue::assertNotPushed(AssessDaysJob::class);
});
