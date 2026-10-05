<?php

use App\Models\AiGenerationLog;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\CaseEvent;
use App\Models\Company;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('append-only triggers exist and reject raw SQL updates and deletes', function (): void {
    $company = Company::factory()->create();
    $context = app(TenantContext::class);
    $context->setCompany($company);
    $records = [
        CaseEvent::factory()->create(), AuditLog::factory()->create(),
        AiGenerationLog::factory()->create(), Attachment::factory()->create(),
    ];

    foreach ($records as $record) {
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
    $context->clear();
});
