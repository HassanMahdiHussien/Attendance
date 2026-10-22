<?php

use App\Models\AiGenerationLog;
use App\Models\AuditLog;
use App\Models\Company;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('kept append-only triggers reject raw SQL updates and deletes', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);

    foreach ([AuditLog::factory()->create(), AiGenerationLog::factory()->create()] as $record) {
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
