<?php

use App\Exceptions\ImmutableRecordException;
use App\Models\AiGenerationLog;
use App\Models\AuditLog;
use App\Models\Company;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('kept append-only logs reject model and raw SQL updates and deletes', function (string $class): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $record = $class::factory()->create();
    $table = $record->getTable();
    $id = $record->id;

    expect(fn () => $record->update(['created_at' => now()->addDay()]))->toThrow(ImmutableRecordException::class);
    expect(fn () => $record->delete())->toThrow(ImmutableRecordException::class);

    foreach ([
        fn () => DB::table($table)->where('id', $id)->update(['created_at' => now()->addDay()]),
        fn () => DB::table($table)->where('id', $id)->delete(),
        fn () => DB::update("UPDATE `{$table}` SET `created_at` = `created_at` WHERE `id` = ?", [$id]),
        fn () => DB::delete("DELETE FROM `{$table}` WHERE `id` = ?", [$id]),
    ] as $operation) {
        try {
            $operation();
            $this->fail('The database accepted an append-only change.');
        } catch (QueryException $exception) {
            expect($exception->getMessage())->toContain('IMMUTABLE:');
        }
    }
})->with([AuditLog::class, AiGenerationLog::class]);
