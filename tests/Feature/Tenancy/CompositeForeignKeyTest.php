<?php

use App\Exceptions\TenantMismatch;
use App\Models\AiGenerationLog;
use App\Models\Attendance\ApproverMapping;
use App\Models\Attendance\AttendanceExport;
use App\Models\Attendance\AttendanceExportEvent;
use App\Models\Attendance\CaseEvent;
use App\Models\Attendance\CoverageInterval;
use App\Models\Attendance\DataIssue;
use App\Models\Attendance\Day;
use App\Models\Attendance\DayDecision;
use App\Models\Attendance\Device;
use App\Models\Attendance\DeviceSetting;
use App\Models\Attendance\FollowUpCase;
use App\Models\Attendance\Holiday;
use App\Models\Attendance\HolidaySource;
use App\Models\Attendance\HolidayVersion;
use App\Models\Attendance\ImportBatch as AttendanceImportBatch;
use App\Models\Attendance\ImportBatchEvent;
use App\Models\Attendance\ImportMapping as AttendanceImportMapping;
use App\Models\Attendance\ManualPunch;
use App\Models\Attendance\Meal;
use App\Models\Attendance\NotificationAttempt;
use App\Models\Attendance\NotificationBatch;
use App\Models\Attendance\NotificationMessage;
use App\Models\Attendance\NotificationMessageCase;
use App\Models\Attendance\NotificationRoute;
use App\Models\Attendance\Person;
use App\Models\Attendance\Policy;
use App\Models\Attendance\ProcessingRun;
use App\Models\Attendance\Punch;
use App\Models\Attendance\RestDayEntitlement;
use App\Models\Attendance\Transaction;
use App\Models\Attendance\TransactionSource;
use App\Models\Branch;
use App\Models\BranchUser;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeImportBatch;
use App\Models\EmployeeVersion;
use App\Models\Leave\Allocation as LeaveAllocation;
use App\Models\Leave\Balance as LeaveBalance;
use App\Models\Leave\BalanceBatch as LeaveBalanceBatch;
use App\Models\Leave\BalanceBatchEvent as LeaveBalanceBatchEvent;
use App\Models\Leave\BalanceMapping as LeaveBalanceMapping;
use App\Models\Leave\BalanceRow as LeaveBalanceRow;
use App\Models\Leave\Credit as LeaveCredit;
use App\Models\Leave\EntitlementConsumption;
use App\Models\Leave\ImportBatch as LeaveImportBatch;
use App\Models\Leave\ImportBatchEvent as LeaveImportBatchEvent;
use App\Models\Leave\ImportMapping as LeaveImportMapping;
use App\Models\Leave\ImportRow as LeaveImportRow;
use App\Models\Leave\LeaveRequest;
use App\Models\Leave\LeaveType;
use App\Models\Leave\ProcessingRun as LeaveProcessingRun;
use App\Models\Leave\RequestDay as LeaveRequestDay;
use App\Models\Leave\RequestDecision as LeaveRequestDecision;
use App\Models\Leave\RequestVersion as LeaveRequestVersion;
use App\Models\Location;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

test('every kept composite tenant foreign key rejects another company through models and raw SQL', function (): void {
    $models = [
        'ai_generation_logs' => AiGenerationLog::class,
        'branches' => Branch::class,
        'locations' => Location::class,
        'employees' => Employee::class,
        'employee_import_batches' => EmployeeImportBatch::class,
        'employee_versions' => EmployeeVersion::class,
        'attendance_devices' => Device::class,
        'attendance_device_settings' => DeviceSetting::class,
        'attendance_import_batches' => AttendanceImportBatch::class,
        'attendance_import_batch_events' => ImportBatchEvent::class,
        'attendance_import_mappings' => AttendanceImportMapping::class,
        'attendance_transactions' => Transaction::class,
        'attendance_transaction_sources' => TransactionSource::class,
        'attendance_people' => Person::class,
        'attendance_coverage_intervals' => CoverageInterval::class,
        'attendance_processing_runs' => ProcessingRun::class,
        'attendance_punches' => Punch::class,
        'attendance_meals' => Meal::class,
        'attendance_policies' => Policy::class,
        'attendance_manual_punches' => ManualPunch::class,
        'attendance_day_decisions' => DayDecision::class,
        'attendance_days' => Day::class,
        'attendance_rest_day_entitlements' => RestDayEntitlement::class,
        'attendance_holidays' => Holiday::class,
        'attendance_holiday_sources' => HolidaySource::class,
        'attendance_holiday_versions' => HolidayVersion::class,
        'attendance_exports' => AttendanceExport::class,
        'attendance_export_events' => AttendanceExportEvent::class,
        'attendance_cases' => FollowUpCase::class,
        'attendance_case_events' => CaseEvent::class,
        'attendance_approver_mappings' => ApproverMapping::class,
        'attendance_notification_batches' => NotificationBatch::class,
        'attendance_notification_messages' => NotificationMessage::class,
        'attendance_notification_routes' => NotificationRoute::class,
        'attendance_notification_message_cases' => NotificationMessageCase::class,
        'attendance_notification_attempts' => NotificationAttempt::class,
        'leave_import_batches' => LeaveImportBatch::class,
        'leave_balance_batches' => LeaveBalanceBatch::class,
        'leave_balance_batch_events' => LeaveBalanceBatchEvent::class,
        'leave_balance_mappings' => LeaveBalanceMapping::class,
        'leave_balances' => LeaveBalance::class,
        'leave_balance_rows' => LeaveBalanceRow::class,
        'leave_import_batch_events' => LeaveImportBatchEvent::class,
        'leave_import_mappings' => LeaveImportMapping::class,
        'leave_types' => LeaveType::class,
        'leave_requests' => LeaveRequest::class,
        'leave_import_rows' => LeaveImportRow::class,
        'leave_request_versions' => LeaveRequestVersion::class,
        'leave_request_decisions' => LeaveRequestDecision::class,
        'leave_processing_runs' => LeaveProcessingRun::class,
        'leave_request_days' => LeaveRequestDay::class,
        'leave_credits' => LeaveCredit::class,
        'leave_allocations' => LeaveAllocation::class,
        'leave_entitlement_consumptions' => EntitlementConsumption::class,
        'data_issues' => DataIssue::class,
        'branch_user' => BranchUser::class,
        'users' => User::class,
    ];
    $keys = DB::select("SELECT TABLE_NAME AS source_table, REFERENCED_TABLE_NAME AS target_table, MAX(CASE WHEN COLUMN_NAME <> 'company_id' THEN COLUMN_NAME END) AS foreign_column FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL GROUP BY TABLE_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME HAVING COUNT(*) = 2 AND SUM(COLUMN_NAME = 'company_id') = 1");
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    $context = app(TenantContext::class);
    $checked = 0;
    $make = fn (string $class, Company $company) => $context->runForCompany($company, function () use ($class, $company) {
        if ($class === User::class) {
            return User::factory()->create(['company_id' => $company->id, 'primary_role' => 'hr_staff']);
        }
        if ($class === BranchUser::class) {
            return BranchUser::factory()->create([
                'company_id' => $company->id,
                'branch_id' => Branch::factory()->create(['company_id' => $company->id])->id,
                'user_id' => User::factory()->create(['company_id' => $company->id, 'primary_role' => 'hr_staff'])->id,
            ]);
        }

        return $class::factory()->create();
    });

    foreach ($keys as $key) {
        if (! isset($models[$key->source_table], $models[$key->target_table])) {
            continue;
        }
        $sourceClass = $models[$key->source_table];
        $targetClass = $models[$key->target_table];
        $source = $make($sourceClass, $a);
        $target = $make($targetClass, $b);
        $attributes = $source->getAttributes();
        unset($attributes['id']);
        $attributes[$key->foreign_column] = $target->id;
        if ($key->source_table === 'leave_allocations' && $key->foreign_column === 'source_credit_id') {
            $attributes['source_request_day_id'] = null;
        }
        if ($key->source_table === 'attendance_rest_day_entitlements' && $key->foreign_column !== 'source_day_id') {
            $attributes['source_day_id'] = $context->runForCompany($a, fn () => Day::factory()->create())->id;
        }
        if ($key->source_table === 'leave_entitlement_consumptions') {
            if ($key->foreign_column !== 'request_id') {
                $attributes['request_id'] = $context->runForCompany($a, fn () => LeaveRequest::factory()->create())->id;
            }
            if ($key->foreign_column !== 'entitlement_id') {
                $attributes['entitlement_id'] = $context->runForCompany($a, fn () => RestDayEntitlement::factory()->create())->id;
            }
        }
        if (isset($attributes['employee_code'])) {
            $attributes['employee_code'] = 'CROSS-'.$target->id;
        }
        if (isset($attributes['email'])) {
            $attributes['email'] = 'cross-'.$target->id.'@example.test';
        }
        // Copied rows must not collide on their own unique keys before MySQL reaches the foreign key check.
        foreach (['serial_number', 'person_code', 'header_signature', 'open_key', 'external_id', 'normalized_key'] as $unique) {
            if (isset($attributes[$unique])) {
                $attributes[$unique] = 'CROSS-'.$key->foreign_column.'-'.$target->id;
            }
        }
        if (isset($attributes['file_sha256'])) {
            $attributes['file_sha256'] = hash('sha256', 'cross-'.$key->foreign_column.'-'.$target->id);
        }
        if (isset($attributes['sha256'])) {
            $attributes['sha256'] = hash('sha256', 'cross-'.$key->foreign_column.'-'.$target->id);
        }
        if (isset($attributes['balance_date']) && $key->source_table === 'leave_balance_batches') {
            $attributes['balance_date'] = '2001-01-01';
        }
        if ($key->source_table === 'attendance_cases' && $key->foreign_column === 'day_id') {
            $attributes['work_date'] = '2001-01-01';
        }
        if (isset($attributes['approver_key'])) {
            $attributes['approver_key'] = 'cross-'.$key->foreign_column.'-'.$target->id;
        }
        if (isset($attributes['uuid'])) {
            $attributes['uuid'] = (string) Str::uuid();
        }
        if (isset($attributes['address'])) {
            $attributes['address'] = 'cross-'.$target->id.'@example.test';
        }
        if (isset($attributes['route_key'])) {
            $attributes['route_key'] = 'cross-'.$key->foreign_column.'-'.$target->id;
        }
        if (isset($attributes['run_key'])) {
            $attributes['run_key'] = 'cross-'.$key->foreign_column.'-'.$target->id;
        }
        foreach (['row_number', 'sequence', 'version'] as $unique) {
            if (isset($attributes[$unique])) {
                $attributes[$unique] = $unique === 'version' ? 2000000000 + $target->id : 900000 + $target->id;
            }
        }

        $context->runForCompany($a, function () use ($sourceClass, $attributes): void {
            expect(fn () => $sourceClass::forceCreate($attributes))->toThrow(TenantMismatch::class);
        });
        try {
            DB::table($key->source_table)->insert($attributes);
            $this->fail('Raw SQL accepted a cross-company link.');
        } catch (QueryException $exception) {
            expect($exception->errorInfo[1])->toBe(1452);
        }
        $checked++;
    }

    expect($checked)->toBeGreaterThan(2);
    $context->clear();
});

test('user employee links and role tenancy reject cross-company assignments', function (): void {
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    $employeeB = app(TenantContext::class)->runForCompany($b, fn () => Employee::factory()->create());

    app(TenantContext::class)->setCompany($a);
    expect(fn () => User::factory()->create(['primary_role' => 'hr_staff', 'company_id' => $a->id, 'employee_id' => $employeeB->id]))->toThrow(TenantMismatch::class);
    expect(fn () => User::factory()->create(['primary_role' => 'hr_staff', 'company_id' => null]))->toThrow(TenantMismatch::class);
    expect(fn () => User::factory()->create(['primary_role' => 'platform_admin', 'company_id' => $a->id]))->toThrow(TenantMismatch::class);
    expect(fn () => Company::factory()->create(['enabled_languages' => ['ar']]))->toThrow(ValidationException::class);
    app(TenantContext::class)->clear();
});

test('branch-user links reject another company user', function (): void {
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    $userB = User::factory()->create(['primary_role' => 'hr_staff', 'company_id' => $b->id]);

    app(TenantContext::class)->runForCompany($a, function () use ($userB): void {
        expect(fn () => BranchUser::factory()->create(['user_id' => $userB->id]))->toThrow(TenantMismatch::class);
    });
});
