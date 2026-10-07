<?php

use App\LegalLibrary\UnitBuilder;
use App\Models\Law;
use App\Models\LegalImportPage;
use App\Models\LegalImportRun;
use App\Models\LegalUnit;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

function legalRun(Law $law, User $actor, string $a, string $b): LegalImportRun
{
    $run = LegalImportRun::create(['law_id' => $law->id, 'source_path' => 'fixture.pdf',
        'source_sha256' => str_repeat('a', 64), 'page_from' => 1, 'page_to' => 1,
        'mode' => 'text', 'state' => 'splitting', 'started_by' => $actor->id, 'started_at' => now()]);
    LegalImportPage::create(['run_id' => $run->id, 'page_number' => 1,
        'image_path' => 'fixture.png', 'image_sha256' => str_repeat('b', 64),
        'text_status' => 'done', 'ai_status' => 'done', 'text' => $a, 'ai_text' => $b]);

    return $run;
}

test('unchanged reimport keeps verified unit and changed text creates a version', function (): void {
    app(TenantContext::class)->runAcrossAllCompanies(function (): void {
        $actor = User::factory()->create(['primary_role' => 'platform_admin', 'company_id' => null]);
        $law = Law::factory()->create();
        $builder = app(UnitBuilder::class);
        $first = legalRun($law, $actor, 'المادة 1 نص قديم', 'المادة 1 نص قديم');
        $builder->build($first);
        $original = LegalUnit::where('law_id', $law->id)->firstOrFail();
        expect($original->status->value)->toBe('verified_auto');
        $builder->build(legalRun($law, $actor, 'المادة 1 نص قديم', 'المادة 1 نص قديم'));
        expect(LegalUnit::where('law_id', $law->id)->count())->toBe(1);
        $builder->build(legalRun($law, $actor, 'المادة 1 نص جديد', 'المادة 1 نص جديد'));
        $successor = LegalUnit::where('previous_version_id', $original->id)->firstOrFail();
        expect($successor->version)->toBe(2);
        expect($original->fresh()->superseded_by_id)->toBe($successor->id);
        expect($original->fresh()->official_text)->toBe('المادة 1 نص قديم');
    });
});

test('failed page cannot produce an automatically verified unit', function (): void {
    app(TenantContext::class)->runAcrossAllCompanies(function (): void {
        $actor = User::factory()->create(['primary_role' => 'platform_admin', 'company_id' => null]);
        $law = Law::factory()->create();
        $run = legalRun($law, $actor, "المادة 1 نص\nيمتد", "المادة 1 نص\nيمتد");
        LegalImportPage::create(['run_id' => $run->id, 'page_number' => 2,
            'image_path' => 'fixture2.png', 'image_sha256' => str_repeat('c', 64),
            'text_status' => 'failed', 'ai_status' => 'failed']);
        $run->pages()->where('page_number', 1)->update(['text' => 'المادة 1 نص']);
        app(UnitBuilder::class)->build($run);
        expect(LegalUnit::where('law_id', $law->id)->where('status', 'verified_auto')->count())->toBe(0);
    });
});

test('unmatched unverified units are retained as excluded evidence', function (): void {
    app(TenantContext::class)->runAcrossAllCompanies(function (): void {
        $actor = User::factory()->create(['primary_role' => 'platform_admin', 'company_id' => null]);
        $law = Law::factory()->create();
        $builder = app(UnitBuilder::class);
        $builder->build(legalRun($law, $actor, 'المادة 1 نص أول', 'المادة 1 نص مختلف'));
        $old = LegalUnit::where('law_id', $law->id)->firstOrFail();
        expect($old->status->value)->toBe('mismatch');
        $next = legalRun($law, $actor, 'المادة 2 نص جديد', 'المادة 2 نص جديد');
        $builder->build($next);
        expect($old->fresh()->status->value)->toBe('excluded');
        expect($old->fresh()->tags['excluded_reason'])->toBe('replaced_by_run:'.$next->id);
    });
});

test('a later matching second reading can replace an earlier mismatch', function (): void {
    app(TenantContext::class)->runAcrossAllCompanies(function (): void {
        $actor = User::factory()->create(['primary_role' => 'platform_admin', 'company_id' => null]);
        $law = Law::factory()->create();
        $builder = app(UnitBuilder::class);
        $builder->build(legalRun($law, $actor, 'المادة 1 نص', 'المادة 1 نص مختلف'));
        $old = LegalUnit::where('law_id', $law->id)->firstOrFail();
        $builder->build(legalRun($law, $actor, 'المادة 1 نص', 'المادة 1 نص'));
        $new = LegalUnit::where('previous_version_id', $old->id)->firstOrFail();
        expect($new->status->value)->toBe('verified_auto');
        expect($old->fresh()->superseded_by_id)->toBe($new->id);
    });
});
