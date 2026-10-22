<?php

use App\Exceptions\TenantMismatch;
use App\Models\Attendance\CoverageInterval;
use App\Models\Attendance\DataIssue;
use App\Models\Attendance\Device;
use App\Models\Attendance\DeviceSetting;
use App\Models\Attendance\ImportBatch as AttendanceImportBatch;
use App\Models\Attendance\ImportBatchEvent;
use App\Models\Attendance\ImportMapping as AttendanceImportMapping;
use App\Models\Attendance\Meal;
use App\Models\Attendance\Person;
use App\Models\Attendance\ProcessingRun;
use App\Models\Attendance\Punch;
use App\Models\Attendance\Transaction;
use App\Models\Attendance\TransactionSource;
use App\Models\Branch;
use App\Models\BranchUser;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeImportBatch;
use App\Models\EmployeeVersion;
use App\Models\Location;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

test('every kept composite tenant foreign key rejects another company through models and raw SQL', function (): void {
    $models = [
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
        if (isset($attributes['employee_code'])) {
            $attributes['employee_code'] = 'CROSS-'.$target->id;
        }
        if (isset($attributes['email'])) {
            $attributes['email'] = 'cross-'.$target->id.'@example.test';
        }
        // Copied rows must not collide on their own unique keys before MySQL reaches the foreign key check.
        foreach (['serial_number', 'person_code', 'header_signature', 'open_key'] as $unique) {
            if (isset($attributes[$unique])) {
                $attributes[$unique] = 'CROSS-'.$key->foreign_column.'-'.$target->id;
            }
        }
        if (isset($attributes['file_sha256'])) {
            $attributes['file_sha256'] = hash('sha256', 'cross-'.$key->foreign_column.'-'.$target->id);
        }
        foreach (['row_number', 'sequence'] as $unique) {
            if (isset($attributes[$unique])) {
                $attributes[$unique] = 900000 + $target->id;
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
