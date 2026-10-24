<?php

use App\Models\AiGenerationLog;
use App\Models\Attendance\AttendanceExportEvent;
use App\Models\Attendance\DayDecision;
use App\Models\Attendance\DeviceSetting;
use App\Models\Attendance\HolidaySource;
use App\Models\Attendance\HolidayVersion;
use App\Models\Attendance\ImportBatch as AttendanceImportBatch;
use App\Models\Attendance\ImportBatchEvent;
use App\Models\Attendance\ManualPunch;
use App\Models\Attendance\Policy;
use App\Models\Attendance\ProcessingRun;
use App\Models\Attendance\RestDayEntitlement;
use App\Models\Attendance\Transaction;
use App\Models\Attendance\TransactionSource;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\EmployeeImportBatch;
use App\Models\EmployeeVersion;
use App\Models\Leave\Balance as LeaveBalance;
use App\Models\Leave\BalanceBatch as LeaveBalanceBatch;
use App\Models\Leave\BalanceBatchEvent as LeaveBalanceBatchEvent;
use App\Models\Leave\BalanceRow as LeaveBalanceRow;
use App\Models\Leave\EntitlementConsumption;
use App\Models\Leave\ImportBatch as LeaveImportBatch;
use App\Models\Leave\ImportBatchEvent as LeaveImportBatchEvent;
use App\Models\Leave\ImportRow as LeaveImportRow;
use App\Models\Leave\ProcessingRun as LeaveProcessingRun;
use App\Models\Leave\RequestDecision as LeaveRequestDecision;
use App\Models\Leave\RequestVersion as LeaveRequestVersion;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('kept append-only triggers reject raw SQL updates and deletes', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);

    foreach ([AuditLog::factory()->create(), AiGenerationLog::factory()->create(), EmployeeImportBatch::factory()->create(), EmployeeVersion::factory()->create(),
        DeviceSetting::factory()->create(), AttendanceImportBatch::factory()->create(), ImportBatchEvent::factory()->create(),
        Transaction::factory()->create(), TransactionSource::factory()->create(), Policy::factory()->create(),
        ManualPunch::factory()->create(), DayDecision::factory()->create(), HolidayVersion::factory()->create(),
        LeaveImportBatch::factory()->create(),
        LeaveBalanceBatch::factory()->create(), LeaveBalanceBatchEvent::factory()->create(),
        LeaveBalance::factory()->create(), LeaveBalanceRow::factory()->create(),
        AttendanceExportEvent::factory()->create(),
        LeaveImportBatchEvent::factory()->create(), LeaveImportRow::factory()->create(),
        LeaveRequestVersion::factory()->create(), LeaveRequestDecision::factory()->create()] as $record) {
        $table = $record->getTable();
        $triggerCount = DB::table('information_schema.TRIGGERS')
            ->where('EVENT_OBJECT_SCHEMA', DB::getDatabaseName())
            ->where('EVENT_OBJECT_TABLE', $table)->count();
        expect($triggerCount)->toBe(2);

        foreach (["UPDATE `{$table}` SET `created_at` = `created_at` WHERE `id` = ?", "DELETE FROM `{$table}` WHERE `id` = ?"] as $sql) {
            try {
                DB::statement($sql, [$record->id]);
                $this->fail('Append-only trigger did not reject raw SQL.');
            } catch (QueryException $exception) {
                expect($exception->getMessage())->toContain('IMMUTABLE:');
            }
        }
    }
});

test('holiday source original file fields cannot change or be deleted while processing metadata can', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $source = HolidaySource::factory()->create();

    DB::table('attendance_holiday_sources')->where('id', $source->id)->update(['ai_status' => 'done']);
    expect(DB::table('attendance_holiday_sources')->where('id', $source->id)->value('ai_status'))->toBe('done');

    foreach (["UPDATE `attendance_holiday_sources` SET `file_name` = 'changed.pdf' WHERE `id` = {$source->id}",
        "DELETE FROM `attendance_holiday_sources` WHERE `id` = {$source->id}"] as $sql) {
        expect(fn () => DB::statement($sql))->toThrow(QueryException::class);
    }
});

test('rest day entitlements permit only one supersession and never a rewrite or deletion', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $entitlement = RestDayEntitlement::factory()->create();
    $run = ProcessingRun::factory()->create();

    DB::table('attendance_rest_day_entitlements')->where('id', $entitlement->id)
        ->update(['superseded_by_run_id' => $run->id]);
    expect($entitlement->fresh()->superseded_by_run_id)->toBe($run->id);
    foreach ([
        'UPDATE `attendance_rest_day_entitlements` SET `quantity` = 1.0 WHERE `id` = ?',
        'UPDATE `attendance_rest_day_entitlements` SET `superseded_by_run_id` = ? WHERE `id` = ?',
        'DELETE FROM `attendance_rest_day_entitlements` WHERE `id` = ?',
    ] as $index => $sql) {
        $bindings = $index === 1 ? [$run->id, $entitlement->id] : [$entitlement->id];
        expect(fn () => DB::statement($sql, $bindings))->toThrow(QueryException::class);
    }
});

test('entitlement consumption rows permit only one supersession', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $consumption = EntitlementConsumption::factory()->create();
    $run = LeaveProcessingRun::factory()->create();

    expect(DB::table('information_schema.TRIGGERS')->where('EVENT_OBJECT_SCHEMA', DB::getDatabaseName())
        ->where('EVENT_OBJECT_TABLE', 'leave_entitlement_consumptions')->count())->toBe(2);
    DB::table('leave_entitlement_consumptions')->where('id', $consumption->id)
        ->update(['superseded_by_run_id' => $run->id]);
    expect($consumption->fresh()->superseded_by_run_id)->toBe($run->id);
    foreach ([
        'UPDATE `leave_entitlement_consumptions` SET `quantity` = 1.0 WHERE `id` = ?',
        'UPDATE `leave_entitlement_consumptions` SET `superseded_by_run_id` = ? WHERE `id` = ?',
        'DELETE FROM `leave_entitlement_consumptions` WHERE `id` = ?',
    ] as $index => $sql) {
        $bindings = $index === 1 ? [$run->id, $consumption->id] : [$consumption->id];
        expect(fn () => DB::statement($sql, $bindings))->toThrow(QueryException::class);
    }
});
