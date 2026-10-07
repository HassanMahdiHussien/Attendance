<?php

use App\Exceptions\TenantMismatch;
use App\Models\AppealTracking;
use App\Models\Attachment;
use App\Models\Branch;
use App\Models\BranchUser;
use App\Models\CaseAlert;
use App\Models\CaseConflictClearance;
use App\Models\CaseEvent;
use App\Models\CaseLink;
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
use App\Models\Employee;
use App\Models\Law;
use App\Models\LegalImportPage;
use App\Models\LegalImportRun;
use App\Models\LegalUnit;
use App\Models\LegalUnitApproval;
use App\Models\Location;
use App\Models\ReportWitness;
use App\Models\Signature;
use App\Models\User;
use App\Models\ViolationReport;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

test('every tenant foreign key rejects a row from another company through models and raw inserts', function (): void {
    $models = [
        'branches' => Branch::class, 'locations' => Location::class, 'employees' => Employee::class,
        'branch_user' => BranchUser::class, 'committee_mandates' => CommitteeMandate::class,
        'committee_pool_members' => CommitteePoolMember::class, 'violation_reports' => ViolationReport::class,
        'report_witnesses' => ReportWitness::class, 'cases' => DisciplinaryCase::class,
        'case_witnesses' => CaseWitness::class, 'case_events' => CaseEvent::class,
        'case_links' => CaseLink::class, 'committee_session_attendances' => CommitteeSessionAttendance::class,
        'appeal_trackings' => AppealTracking::class, 'documents' => Document::class,
        'document_contents' => DocumentContent::class, 'document_approvals' => DocumentApproval::class,
        'signatures' => Signature::class, 'citations' => Citation::class,
        'case_alerts' => CaseAlert::class, 'case_conflict_clearances' => CaseConflictClearance::class,
        'company_letterheads' => CompanyLetterhead::class, 'document_templates' => DocumentTemplate::class,
        'users' => User::class,
        'legal_import_runs' => LegalImportRun::class,
        'legal_import_pages' => LegalImportPage::class,
        'legal_unit_approvals' => LegalUnitApproval::class,
    ];
    $keys = DB::select("SELECT TABLE_NAME AS source_table, CONSTRAINT_NAME AS constraint_name, REFERENCED_TABLE_NAME AS target_table, MAX(CASE WHEN COLUMN_NAME <> 'company_id' THEN COLUMN_NAME END) AS foreign_column FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL GROUP BY TABLE_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME HAVING COUNT(*) = 2 AND SUM(COLUMN_NAME = 'company_id') = 1");
    expect(count($keys))->toBeGreaterThan(25);
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    $context = app(TenantContext::class);

    foreach ($keys as $key) {
        if (in_array($key->source_table, ['citations', 'legal_import_pages', 'legal_unit_approvals'], true)) {
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
        if ($key->foreign_column === 'previous_version_id') {
            $attributes['correction_reason'] = 'Cross-company reference test';
        }
        if (isset($attributes['case_number'])) {
            $attributes['case_number'] += 1000000000;
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
        [Citation::class, 'overridden_by'],
    ];

    app(TenantContext::class)->runForCompany($a, function () use ($links, $userB): void {
        foreach ($links as [$model, $column]) {
            expect(fn () => $model::factory()->create([$column => $userB->id]))->toThrow(TenantMismatch::class);
        }
    });
});
