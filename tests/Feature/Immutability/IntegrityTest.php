<?php

use App\Exceptions\ImmutableRecordException;
use App\Models\AiGenerationLog;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\CaseEvent;
use App\Models\Citation;
use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentApproval;
use App\Models\DocumentContent;
use App\Models\LegalUnit;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function integrityCompany(): Company
{
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);

    return $company;
}

function assertImmutableSql(Closure $operation): void
{
    try {
        $affected = $operation();
        if ($affected === 0) {
            throw new RuntimeException('The immutable operation matched no rows.');
        }
        throw new RuntimeException('The database accepted an immutable change.');
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain('IMMUTABLE:');
    }
}

test('append-only logs and attachments reject update and delete on every route', function (string $class): void {
    integrityCompany();
    $record = $class::factory()->create();
    $table = $record->getTable();
    $id = $record->id;
    expect(fn () => $record->update(['created_at' => now()->addDay()]))->toThrow(ImmutableRecordException::class);
    expect(fn () => $record->delete())->toThrow(ImmutableRecordException::class);
    assertImmutableSql(fn () => DB::table($table)->where('id', $id)->update(['created_at' => now()->addDay()]));
    assertImmutableSql(fn () => DB::table($table)->where('id', $id)->delete());
    assertImmutableSql(fn () => DB::update("UPDATE `{$table}` SET `created_at` = `created_at` WHERE `id` = ?", [$id]));
    assertImmutableSql(fn () => DB::delete("DELETE FROM `{$table}` WHERE `id` = ?", [$id]));
})->with([CaseEvent::class, AuditLog::class, AiGenerationLog::class, Attachment::class]);

test('locked document protected columns, unlock, timestamp alone, and delete are refused', function (string $column, mixed $value): void {
    integrityCompany();
    $document = Document::factory()->create(['is_locked' => true, 'status' => 'issued']);
    $id = $document->id;
    expect(fn () => $document->update([$column => $value]))->toThrow(ImmutableRecordException::class);
    assertImmutableSql(fn () => DB::table('documents')->where('id', $id)->update([$column => $value]));
    assertImmutableSql(fn () => DB::update("UPDATE `documents` SET `{$column}` = ? WHERE `id` = ?", [$value, $id]));
    expect(fn () => $document->delete())->toThrow(ImmutableRecordException::class);
    assertImmutableSql(fn () => DB::table('documents')->where('id', $id)->delete());
    assertImmutableSql(fn () => DB::delete('DELETE FROM `documents` WHERE `id` = ?', [$id]));
})->with([
    ['reference_number', 'tampered'], ['issued_at', '2020-01-01 00:00:00'],
    ['document_type', 'decision_letter'], ['version', 7], ['is_locked', 0],
    ['updated_at', '2030-01-01 00:00:00'], ['drafted_by', 'ai'], ['reference_year', 2030],
    ['reference_is_manual', 1], ['issued_by', 999], ['case_id', 999],
    ['company_id', 999], ['created_at', '2030-01-01 00:00:00'],
    ['previous_version_id', 999], ['correction_reason', 'Changed'],
    ['second_reviewed_by', 999], ['second_reviewed_at', '2030-01-01 00:00:00'],
    ['penalty_type', 'termination'], ['status', 'draft'], ['created_by', 999],
    ['id', 999999],
]);

test('locked document permits supersession link and PDF path', function (): void {
    integrityCompany();
    $document = Document::factory()->create(['is_locked' => true, 'status' => 'issued']);
    $successor = Document::factory()->create(['case_id' => $document->case_id, 'document_type' => $document->document_type,
        'version' => 2]);
    $document->update(['status' => 'superseded', 'superseded_by_id' => $successor->id, 'pdf_file_path' => 'signed.pdf']);
    expect($document->fresh()->superseded_by_id)->toBe($successor->id);
    expect($document->fresh()->pdf_file_path)->toBe('signed.pdf');
});

test('locked contents, citations, and approvals reject insert update and delete on every route', function (string $class): void {
    integrityCompany();
    $document = Document::factory()->create();
    $record = $class::factory()->create(['document_id' => $document->id]);
    $document->update(['is_locked' => true, 'status' => 'issued']);
    $table = $record->getTable();
    $id = $record->id;
    $column = $class === DocumentContent::class ? 'body' : ($class === Citation::class ? 'claim_sentence' : 'decision');
    $value = $class === DocumentApproval::class ? 'rejected' : 'tampered';
    expect(fn () => $record->update([$column => $value]))->toThrow(ImmutableRecordException::class);
    expect(fn () => $record->delete())->toThrow(ImmutableRecordException::class);
    assertImmutableSql(fn () => DB::table($table)->where('id', $id)->update([$column => $value]));
    assertImmutableSql(fn () => DB::table($table)->where('id', $id)->delete());
    assertImmutableSql(fn () => DB::update("UPDATE `{$table}` SET `{$column}` = ? WHERE `id` = ?", [$value, $id]));
    assertImmutableSql(fn () => DB::delete("DELETE FROM `{$table}` WHERE `id` = ?", [$id]));
    $attributes = $record->getAttributes();
    unset($attributes['id']);
    expect(fn () => $class::create($attributes))->toThrow(ImmutableRecordException::class);
    assertImmutableSql(fn () => DB::table($table)->insert($attributes));
    $columns = array_keys($attributes);
    $names = implode(', ', array_map(fn ($name) => "`{$name}`", $columns));
    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    assertImmutableSql(fn () => DB::insert("INSERT INTO `{$table}` ({$names}) VALUES ({$placeholders})", array_values($attributes)));
})->with([DocumentContent::class, Citation::class, DocumentApproval::class]);

test('verified legal unit text, article, page, and delete are refused on every route', function (string $column, mixed $value): void {
    integrityCompany();
    $unit = LegalUnit::factory()->create();
    $id = $unit->id;
    expect(fn () => $unit->update([$column => $value]))->toThrow(ImmutableRecordException::class);
    expect(fn () => $unit->delete())->toThrow(ImmutableRecordException::class);
    assertImmutableSql(fn () => DB::table('legal_units')->where('id', $id)->update([$column => $value]));
    assertImmutableSql(fn () => DB::table('legal_units')->where('id', $id)->delete());
    assertImmutableSql(fn () => DB::update("UPDATE `legal_units` SET `{$column}` = ? WHERE `id` = ?", [$value, $id]));
    assertImmutableSql(fn () => DB::delete('DELETE FROM `legal_units` WHERE `id` = ?', [$id]));
})->with([['official_text', 'Changed'], ['article_number', '10'], ['source_page', 99]]);

test('approval invalidation and verified unit supersession remain permitted', function (): void {
    integrityCompany();
    $approval = DocumentApproval::factory()->create();
    $approval->update(['invalidated_at' => now()]);
    expect($approval->fresh()->invalidated_at)->not->toBeNull();

    $unit = LegalUnit::factory()->create();
    $successor = LegalUnit::factory()->create(['law_id' => $unit->law_id,
        'article_number' => $unit->article_number, 'version' => 2, 'previous_version_id' => $unit->id]);
    $unit->update(['superseded_by_id' => $successor->id]);
    expect($unit->fresh()->superseded_by_id)->toBe($successor->id);
});
