<?php

use App\Models\AiGenerationLog;
use App\Models\Attendance\AttendanceExportEvent;
use App\Models\Attendance\DayDecision;
use App\Models\Attendance\DeviceSetting;
use App\Models\Attendance\ImportBatch as AttendanceImportBatch;
use App\Models\Attendance\ImportBatchEvent;
use App\Models\Attendance\ManualPunch;
use App\Models\Attendance\Policy;
use App\Models\Attendance\Transaction;
use App\Models\Attendance\TransactionSource;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\EmployeeImportBatch;
use App\Models\EmployeeVersion;
use App\Models\Leave\ImportBatch as LeaveImportBatch;
use App\Models\Leave\ImportBatchEvent as LeaveImportBatchEvent;
use App\Models\Leave\ImportRow as LeaveImportRow;
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
        ManualPunch::factory()->create(), DayDecision::factory()->create(), LeaveImportBatch::factory()->create(),
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
