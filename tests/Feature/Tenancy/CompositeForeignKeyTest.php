<?php

use App\Exceptions\TenantMismatch;
use App\Models\AiDraftNote;
use App\Models\AiGenerationLog;
use App\Models\AppealTracking;
use App\Models\Attachment;
use App\Models\Branch;
use App\Models\BranchUser;
use App\Models\CaseAlert;
use App\Models\CaseConflictClearance;
use App\Models\CaseEvent;
use App\Models\CaseLink;
use App\Models\CaseLinkAnnotation;
use App\Models\CaseWitness;
use App\Models\Citation;
use App\Models\CommitteeMandate;
use App\Models\CommitteePoolMember;
use App\Models\CommitteeSessionAttendance;
use App\Models\Company;
use App\Models\CompanyLetterhead;
use App\Models\DisciplinaryCase;
use App\Models\Document;
use App\Models\DocumentApproval;
use App\Models\DocumentContent;
use App\Models\DocumentTemplate;
use App\Models\EmailDraft;
use App\Models\Employee;
use App\Models\Incident;
use App\Models\IntakeCheck;
use App\Models\IntakeQuestion;
use App\Models\Law;
use App\Models\LegalImportPage;
use App\Models\LegalImportRun;
use App\Models\LegalUnit;
use App\Models\LegalUnitApproval;
use App\Models\Location;
use App\Models\RegulationComparison;
use App\Models\RegulationComparisonPair;
use App\Models\ReportAssistantSession;
use App\Models\ReportWitness;
use App\Models\ResponseTranscription;
use App\Models\Signature;
use App\Models\SignaturePresenceSuggestion;
use App\Models\User;
use App\Models\ViolationReport;
use App\Models\ViolationType;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

test('every tenant foreign key rejects a row from another company through models and raw inserts', function (): void {
    $models = [
        'branches' => Branch::class, 'locations' => Location::class, 'violation_types' => ViolationType::class, 'employees' => Employee::class,
        'branch_user' => BranchUser::class, 'committee_mandates' => CommitteeMandate::class,
        'committee_pool_members' => CommitteePoolMember::class, 'violation_reports' => ViolationReport::class,
        'report_witnesses' => ReportWitness::class, 'cases' => DisciplinaryCase::class,
        'report_assistant_sessions' => ReportAssistantSession::class,
        'case_witnesses' => CaseWitness::class, 'case_events' => CaseEvent::class,
        'case_links' => CaseLink::class, 'case_link_annotations' => CaseLinkAnnotation::class,
        'committee_session_attendances' => CommitteeSessionAttendance::class,
        'appeal_trackings' => AppealTracking::class, 'documents' => Document::class,
        'attachments' => Attachment::class,
        'document_contents' => DocumentContent::class, 'document_approvals' => DocumentApproval::class,
        'signatures' => Signature::class, 'citations' => Citation::class,
        'case_alerts' => CaseAlert::class, 'case_conflict_clearances' => CaseConflictClearance::class,
        'company_letterheads' => CompanyLetterhead::class, 'document_templates' => DocumentTemplate::class,
        'users' => User::class,
        'legal_import_runs' => LegalImportRun::class,
        'legal_import_pages' => LegalImportPage::class,
        'legal_unit_approvals' => LegalUnitApproval::class,
        'ai_draft_notes' => AiDraftNote::class,
        'response_transcriptions' => ResponseTranscription::class,
        'signature_presence_suggestions' => SignaturePresenceSuggestion::class,
        'ai_generation_logs' => AiGenerationLog::class,
        'email_drafts' => EmailDraft::class,
        'intake_checks' => IntakeCheck::class, 'intake_questions' => IntakeQuestion::class,
        'regulation_comparisons' => RegulationComparison::class,
        'regulation_comparison_pairs' => RegulationComparisonPair::class,
        'incidents' => Incident::class,
    ];
    $keys = DB::select("SELECT TABLE_NAME AS source_table, CONSTRAINT_NAME AS constraint_name, REFERENCED_TABLE_NAME AS target_table, MAX(CASE WHEN COLUMN_NAME <> 'company_id' THEN COLUMN_NAME END) AS foreign_column FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL GROUP BY TABLE_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME HAVING COUNT(*) = 2 AND SUM(COLUMN_NAME = 'company_id') = 1");
    expect(count($keys))->toBeGreaterThan(25);
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    $context = app(TenantContext::class);

    foreach ($keys as $key) {
        // Incident pivot and session rows have no factories; their tenant keys are exercised in IncidentTest.
        // File readings and HR file intakes have none either; ReportAttachmentTest exercises their tenant keys.
        if (in_array($key->source_table, ['citations', 'legal_import_pages', 'legal_unit_approvals',
            'incident_sessions', 'violation_report_employees', 'attachment_texts', 'case_file_intakes'], true)) {
            continue;
        }
        $sourceClass = $models[$key->source_table];
        $targetClass = $models[$key->target_table];
        $source = $context->runForCompany($a, fn () => $sourceClass::factory()->create());
        $target = $key->target_table === 'users'
            ? User::factory()->create(['company_id' => $b->id, 'primary_role' => 'hr_staff'])
            : $context->runForCompany($b, fn () => $targetClass::factory()->create());
        $attributes = $source->getAttributes();
        unset($attributes['id']);
        $attributes[$key->foreign_column] = $target->id;
        if ($key->source_table === 'intake_questions'
            && in_array($key->foreign_column, ['violation_report_id', 'email_draft_id', 'case_id'], true)) {
            foreach (['violation_report_id', 'email_draft_id', 'case_id'] as $parentKey) {
                if ($parentKey !== $key->foreign_column) {
                    $attributes[$parentKey] = null;
                }
            }
        }
        if ($key->foreign_column === 'previous_version_id') {
            $attributes['correction_reason'] = 'Cross-company reference test';
        }
        if (isset($attributes['case_number'])) {
            $attributes['case_number'] += 1000000000;
        }
        if (isset($attributes['report_number'])) {
            $attributes['report_number'] += 1000000000;
        }
        if ($key->source_table === 'intake_checks') {
            $attributes['request_token'] = (string) Str::uuid();
        }
        // A link carries at most one annotation per kind; point the copy at a fresh same-company link so only the
        // foreign key under test can fail.
        // Likewise a case pair is linked once per type; give the copy a fresh same-company case.
        if ($key->source_table === 'case_links' && ! in_array($key->foreign_column, ['case_id', 'linked_case_id'], true)) {
            $attributes['linked_case_id'] = $context->runForCompany($a, fn () => DisciplinaryCase::factory()->create())->id;
        }
        if ($key->source_table === 'case_link_annotations' && $key->foreign_column !== 'case_link_id') {
            $attributes['case_link_id'] = $context->runForCompany($a, fn () => CaseLink::factory()->create())->id;
        }
        if (isset($attributes['employee_code'])) {
            $attributes['employee_code'] = 'CROSS-'.$target->id;
        }
        if (isset($attributes['version'])) {
            $attributes['version'] += 1000;
        }

        $context->runForCompany($a, function () use ($sourceClass, $attributes): void {
            try {
                $sourceClass::create($attributes);
                $this->fail('Eloquent accepted a cross-company link.');
            } catch (TenantMismatch $exception) {
                expect($exception->getMessage())->toContain('company');
            } catch (QueryException $exception) {
                expect($exception->errorInfo[1])->toBe(1452);
            }
        });

        $columns = array_keys($attributes);
        $names = implode(', ', array_map(fn (string $column): string => "`{$column}`", $columns));
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        try {
            DB::insert("INSERT INTO `{$key->source_table}` ({$names}) VALUES ({$placeholders})", array_values($attributes));
            $this->fail('Raw SQL accepted a cross-company link.');
        } catch (QueryException $exception) {
            expect($exception->errorInfo[1])->toBe(1452);
        }
    }
    $context->clear();
});

test('citations, attachments and users enforce links that cannot use composite keys', function (): void {
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    $context = app(TenantContext::class);
    $documentA = $context->runForCompany($a, fn () => Document::factory()->create());
    $employeeB = $context->runForCompany($b, fn () => Employee::factory()->create());
    $caseB = $context->runForCompany($b, fn () => DisciplinaryCase::factory()->create());
    $unitB = $context->runForCompany($b, function () use ($b): LegalUnit {
        $law = Law::factory()->create(['kind' => 'internal_regulation', 'company_id' => $b->id]);

        return LegalUnit::factory()->create(['law_id' => $law->id]);
    });

    $context->setCompany($a);
    expect(fn () => Citation::factory()->create(['document_id' => $documentA->id, 'legal_unit_id' => $unitB->id]))->toThrow(TenantMismatch::class);
    expect(fn () => Attachment::factory()->create(['company_id' => $a->id, 'attachable_type' => 'case', 'attachable_id' => $caseB->id]))->toThrow(TenantMismatch::class);
    expect(fn () => User::factory()->create(['primary_role' => 'company_admin', 'company_id' => $a->id, 'employee_id' => $employeeB->id]))->toThrow(TenantMismatch::class);
    expect(fn () => User::factory()->create(['primary_role' => 'company_admin', 'company_id' => null]))->toThrow(TenantMismatch::class);
    expect(fn () => User::factory()->create(['primary_role' => 'platform_admin', 'company_id' => $a->id]))->toThrow(TenantMismatch::class);
    expect(fn () => Company::factory()->create(['enabled_languages' => ['ar']]))->toThrow(ValidationException::class);
    $context->clear();
});

test('tenant model user links reject another company user', function (): void {
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    $userB = User::factory()->create(['primary_role' => 'company_admin', 'company_id' => $b->id]);
    $links = [
        [BranchUser::class, 'user_id'], [CommitteePoolMember::class, 'user_id'],
        [ViolationReport::class, 'reporter_user_id'], [DisciplinaryCase::class, 'opened_by_user_id'],
        [DisciplinaryCase::class, 'assigned_hr_user_id'], [DisciplinaryCase::class, 'legal_review_requested_by'],
        [DisciplinaryCase::class, 'employee_response_checked_by'], [CaseEvent::class, 'actor_user_id'],
        [Attachment::class, 'uploaded_by'], [Document::class, 'issued_by'],
        [Document::class, 'second_reviewed_by'], [Document::class, 'created_by'],
        [DocumentApproval::class, 'approver_user_id'], [AppealTracking::class, 'logged_by'],
        [CaseLink::class, 'created_by_user_id'], [CaseLinkAnnotation::class, 'created_by_user_id'],
        [Citation::class, 'overridden_by'],
    ];

    app(TenantContext::class)->runForCompany($a, function () use ($links, $userB): void {
        foreach ($links as [$model, $column]) {
            expect(fn () => $model::factory()->create([$column => $userB->id]))->toThrow(TenantMismatch::class);
        }
    });
});
