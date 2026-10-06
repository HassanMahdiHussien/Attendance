<?php

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Exceptions\IssuanceBlocked;
use App\Models\CommitteeMandate;
use App\Models\CommitteePoolMember;
use App\Models\CommitteeSessionAttendance;
use App\Models\Company;
use App\Models\Document;
use App\Models\Employee;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CaseEventLogger;
use App\Services\Cases\RecommendationsEditor;
use App\Services\Documents\DocumentCorrector;
use App\Services\Documents\DocumentIssuer;
use App\Support\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;

function documentFixture(array $companyAttributes = [], string $type = 'defense_letter'): array
{
    $company = Company::factory()->create($companyAttributes);
    app(TenantContext::class)->setCompany($company);
    $actor = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $document = Document::factory()->create(['document_type' => $type, 'created_by' => $actor->id]);
    if ($type !== 'defense_letter') {
        $case = $document->disciplinaryCase;
        $case->forceFill(['defense_letter_issued_at' => now()->subDays(3),
            'committee_session_held_at' => now(), 'defense_acceptance' => 'rejected',
            'outcome' => $type === 'decision_letter' ? 'warning' : null])->save();
        $mandate = CommitteeMandate::factory()->create(['effective_from' => now()->subDays(10)]);
        foreach (['legal', 'hr', 'labor_union'] as $role) {
            $member = CommitteePoolMember::factory()->create(['mandate_id' => $mandate->id, 'role_type' => $role]);
            CommitteeSessionAttendance::factory()->create(['case_id' => $case->id,
                'committee_pool_member_id' => $member->id, 'is_chair' => $role === 'legal']);
        }
        if ($type === 'decision_letter') {
            $document->update(['penalty_type' => 'warning']);
            Employee::factory()->create(['is_general_manager' => true]);
        }
    }

    return [$company, $actor, $document];
}

function addContents(Document $document, array $languages = ['ar', 'en']): void
{
    // Recommendations drafts may only be written through RecommendationsEditor (which also
    // invalidates approvals), so route them through it like production code does.
    if ($document->document_type === DocumentType::Recommendations) {
        $editor = User::factory()->create(['company_id' => $document->company_id, 'primary_role' => 'company_admin']);
        moveCaseToCommitteeStage($document->disciplinaryCase);
        $current = $document->contents()->pluck('body', 'language_code')->all();
        foreach ($languages as $language) {
            $current[$language] = ['body' => 'Findings in '.$language, 'secondary_body' => 'Recommendations in '.$language];
        }
        app(RecommendationsEditor::class)->update($document, $current, $editor);

        return;
    }

    foreach ($languages as $language) {
        $document->contents()->create(['company_id' => $document->company_id, 'language_code' => $language,
            'body' => 'Text in '.$language, 'is_authoritative' => $language === 'ar']);
    }
}

test('all enabled languages must have content and preview reserves nothing', function (): void {
    [, $actor, $document] = documentFixture(['enabled_languages' => ['ar', 'en', 'ku']]);
    $issuer = app(DocumentIssuer::class);
    expect($issuer->previewReference($document))->toBe('1');
    addContents($document, ['ar', 'en']);
    expect(fn () => $issuer->issue($document, $actor))->toThrow(IssuanceBlocked::class, 'ku');
    addContents($document, ['ku']);
    expect($issuer->previewReference($document))->toBe('1');
    expect(fn () => $issuer->issue($document, $actor))->toThrow(IssuanceBlocked::class, 'letterhead.ku');
});

test('second review needs a time and another user', function (): void {
    [$company, $actor, $document] = documentFixture(['requires_second_review' => true]);
    addContents($document);
    $issuer = app(DocumentIssuer::class);
    expect(fn () => $issuer->issue($document, $actor))->toThrow(IssuanceBlocked::class);
    $document->update(['second_reviewed_by' => $actor->id, 'second_reviewed_at' => now()]);
    expect(fn () => $issuer->issue($document, $actor))->toThrow(IssuanceBlocked::class);
    $reviewer = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'hr_staff']);
    $document->update(['second_reviewed_by' => $reviewer->id, 'second_reviewed_at' => null]);
    expect(fn () => $issuer->issue($document, $actor))->toThrow(IssuanceBlocked::class);
    $document->update(['second_reviewed_at' => now()]);
    expect($issuer->issue($document, $actor)->issued_by)->toBe($actor->id);
});

test('recommendations require three valid approvals', function (): void {
    [, $actor, $document] = documentFixture(type: 'recommendations');
    addContents($document);
    $issuer = app(DocumentIssuer::class);
    foreach (['legal', 'hr', 'labor_union'] as $role) {
        expect(fn () => $issuer->issue($document, $actor))->toThrow(IssuanceBlocked::class, $role);
        $approval = $document->approvals()->create(['company_id' => $document->company_id,
            'approver_user_id' => $actor->id, 'role_category' => $role, 'decision' => 'approved',
            'languages_present' => ['ar', 'en'], 'decided_at' => now()]);
        if ($role === 'hr') {
            $approval->update(['invalidated_at' => now()]);
            expect(fn () => $issuer->issue($document, $actor))->toThrow(IssuanceBlocked::class, 'hr');
            $document->approvals()->create(['company_id' => $document->company_id,
                'approver_user_id' => $actor->id, 'role_category' => $role, 'decision' => 'approved',
                'languages_present' => ['ar', 'en'], 'decided_at' => now()]);
        }
    }
    expect($issuer->issue($document, $actor)->is_locked)->toBeTrue();
});

test('a rejected approval does not satisfy the recommendations gate', function (): void {
    [, $actor, $document] = documentFixture(type: 'recommendations');
    addContents($document);
    $document->approvals()->create(['company_id' => $document->company_id,
        'approver_user_id' => $actor->id, 'role_category' => 'legal', 'decision' => 'rejected',
        'comment' => 'Revise the text', 'languages_present' => ['ar', 'en'], 'decided_at' => now()]);
    expect(fn () => app(DocumentIssuer::class)->issue($document, $actor))->toThrow(IssuanceBlocked::class, 'legal');
});

test('a correction copies content, leaves the original valid, and supersedes it only on issuance', function (): void {
    [, $actor, $document] = documentFixture();
    addContents($document);
    $issuer = app(DocumentIssuer::class);
    $issued = $issuer->issue($document, $actor);
    $corrector = app(DocumentCorrector::class);
    $correction = $corrector->startCorrection($issued, 'Correct a typo', $actor);
    expect($issued->fresh()->status)->toBe(DocumentStatus::Issued);
    expect($correction->version)->toBe(2);
    expect($correction->contents()->count())->toBe(2);
    expect($correction->created_by)->toBe($actor->id);
    expect($issuer->previewReference($correction))->toBe('2');
    expect(fn () => $corrector->startCorrection($issued, 'Again', $actor))->toThrow(IssuanceBlocked::class);
    expect($issued->fresh()->superseded_by_id)->toBeNull();
    $issuer->issue($correction, $actor);
    expect($issued->fresh()->status)->toBe(DocumentStatus::Superseded);
    expect($issued->fresh()->superseded_by_id)->toBe($correction->id);
    expect($issued->disciplinaryCase->events()->pluck('event_type')->all())->toBe(['document_issued', 'document_correction_started', 'document_issued']);
    expect(fn () => $corrector->startCorrection($issued, 'Again', $actor))->toThrow(IssuanceBlocked::class);
});

test('a decision letter has no committee approval gate and a completed document cannot be reissued', function (): void {
    [, $actor, $document] = documentFixture(type: 'decision_letter');
    addContents($document);
    $issuer = app(DocumentIssuer::class);
    $issuer->issue($document, $actor);
    expect($document->approvals()->count())->toBe(0);
    expect(fn () => $issuer->issue($document, $actor))->toThrow(IssuanceBlocked::class);
    expect(fn () => app(DocumentCorrector::class)->startCorrection($document, '', $actor))->toThrow(ValidationException::class);
});

test('case and audit loggers capture actor, company, and structured details', function (): void {
    [$company, $actor, $document] = documentFixture();
    $event = app(CaseEventLogger::class)->log($document->disciplinaryCase, 'reviewed', ['step' => 1], 'Ready', $actor);
    expect($event->company_id)->toBe($company->id);
    expect($event->actor_user_id)->toBe($actor->id);
    expect($event->payload)->toBe(['step' => 1]);
    $audit = app(AuditLogger::class)->log('document_reviewed', $document, ['step' => 1], $actor);
    expect($audit->company_id)->toBe($company->id);
    expect($audit->actor_user_id)->toBe($actor->id);
    expect($audit->metadata)->toBe(['step' => 1]);
});
