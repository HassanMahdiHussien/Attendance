<?php

use App\Enums\CaseStatus;
use App\Exceptions\IllegalTransition;
use App\Exceptions\ImmutableRecordException;
use App\Models\Company;
use App\Models\DisciplinaryCase;
use App\Models\Law;
use App\Models\User;
use App\Services\Cases\CaseWorkflow;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('the model and database both reject status writes outside CaseWorkflow', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $actor = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $law = Law::factory()->create();
    $case = DisciplinaryCase::factory()->create(['law_version_id' => $law->id, 'opened_by_user_id' => $actor->id,
        'violation_place_freetext' => 'Warehouse']);

    expect(fn () => $case->update(['status' => CaseStatus::Open]))->toThrow(ImmutableRecordException::class);
    $case->refresh();
    expect(fn () => $case->forceFill(['status' => CaseStatus::Open])->save())->toThrow(ImmutableRecordException::class);
    $case->refresh();
    expect(fn () => $case->forceFill(['status' => CaseStatus::Open])->saveQuietly())->toThrow(QueryException::class);
    $case->refresh();
    expect(fn () => DB::table('cases')->where('id', $case->id)->update(['status' => 'open']))->toThrow(QueryException::class);
    expect(fn () => DB::statement("UPDATE cases SET status = 'open' WHERE id = {$case->id}"))->toThrow(QueryException::class);
    expect($case->fresh()->status)->toBe(CaseStatus::Draft);

    $workflow = app(CaseWorkflow::class);
    $workflow->transition($case, CaseStatus::Open, $actor, 'Opened');
    expect(fn () => $workflow->transition($case, CaseStatus::Open, $actor, 'Stale call'))->toThrow(IllegalTransition::class);
    expect($case->fresh()->status)->toBe(CaseStatus::Open);
});

test('only the wrapper references DocumentIssuer in application callers', function (): void {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));
    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php'
            || in_array($file->getFilename(), ['CaseDocumentIssuance.php', 'DocumentIssuer.php'], true)) {
            continue;
        }
        expect(file_get_contents($file->getPathname()))->not->toContain('DocumentIssuer');
    }
});
