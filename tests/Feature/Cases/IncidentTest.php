<?php

use App\Ai\PayloadBuilder;
use App\Enums\AttachmentKind;
use App\Enums\DocumentType;
use App\Exceptions\ImmutableRecordException;
use App\Exceptions\IssuanceBlocked;
use App\Exceptions\TransitionBlocked;
use App\Livewire\Cases\ShowCase;
use App\Models\Attachment;
use App\Models\Branch;
use App\Models\CommitteePoolMember;
use App\Models\Company;
use App\Models\Document;
use App\Models\Employee;
use App\Models\Incident;
use App\Models\Law;
use App\Models\User;
use App\Models\ViolationReport;
use App\Services\Cases\CaseClosure;
use App\Services\Cases\CaseLinks;
use App\Services\Cases\CommitteeSessionService;
use App\Services\Cases\IncidentService;
use App\Services\Documents\DocumentIssuer;
use App\Services\Reports\ReportService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

function incidentFixture(): array
{
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $branch = Branch::factory()->create(['company_id' => $company->id]);
    $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $employees = Employee::factory()->count(2)->create(['company_id' => $company->id, 'branch_id' => $branch->id]);
    $facts = ['branch_id' => $branch->id, 'violation_time' => now(), 'violation_type' => 'Incident',
        'violation_place_freetext' => 'Office', 'description' => 'Shared incident facts'];

    return compact('company', 'branch', 'hr', 'employees', 'facts');
}

test('incident creates one tenant-scoped draft per employee and membership cannot move', function (): void {
    $f = incidentFixture();
    $service = app(IncidentService::class);
    $incident = $service->createForEmployees($f['employees']->pluck('id')->all(), $f['facts'], $f['hr']);
    $members = $incident->cases()->orderBy('id')->get();
    expect($members)->toHaveCount(2)
        ->and($members->pluck('employee_id')->all())->toBe($f['employees']->pluck('id')->all())
        ->and($members->pluck('case_number')->unique())->toHaveCount(2)
        ->and($members->every(fn ($case) => $case->status->value === 'draft'))->toBeTrue();
    expect(fn () => $members[0]->forceFill(['incident_id' => null])->save())->toThrow(ImmutableRecordException::class);
    expect(fn () => DB::table('cases')->where('id', $members[0]->id)->update(['incident_id' => null]))
        ->toThrow(QueryException::class);

    $foreign = Company::factory()->create();
    $foreignIncident = app(TenantContext::class)->runForCompany($foreign, fn () => Incident::factory()->create());
    expect(fn () => DB::table('cases')->insert([
        'company_id' => $f['company']->id, 'branch_id' => $f['branch']->id,
        'employee_id' => $f['employees'][0]->id, 'incident_id' => $foreignIncident->id,
        'case_number' => 999999, 'status' => 'draft', 'jurisdiction_at_opening' => 'federal_iraq',
        'opened_by_user_id' => $f['hr']->id, 'violation_time' => now(), 'violation_type' => 'Incident',
        'created_at' => now(), 'updated_at' => now(),
    ]))
        ->toThrow(QueryException::class);
});

test('incident member list respects per-case visibility', function (): void {
    $f = incidentFixture();
    $incident = app(IncidentService::class)->createForEmployees($f['employees']->pluck('id')->all(), $f['facts'], $f['hr']);
    $staff = User::factory()->create(['company_id' => $f['company']->id, 'primary_role' => 'hr_staff']);
    $first = $incident->cases()->orderBy('id')->firstOrFail();
    $first->forceFill(['assigned_hr_user_id' => $staff->id])->save();

    expect(app(IncidentService::class)->visibleMembers($incident, $staff)->pluck('id')->all())->toBe([$first->id]);
    Livewire::actingAs($staff)->test(ShowCase::class, ['case' => (string) $first->id])
        ->assertDontSee($f['employees'][1]->name);
});

test('incident membership does not create or accept manual related links', function (): void {
    $f = incidentFixture();
    $incident = app(IncidentService::class)->createForEmployees($f['employees']->pluck('id')->all(), $f['facts'], $f['hr']);
    $members = $incident->cases()->orderBy('id')->get();
    expect(DB::table('case_links')->whereIn('case_id', $members->pluck('id'))->count())->toBe(0)
        ->and(app(CaseLinks::class)->candidates($f['hr'], $members[0], '')->pluck('id')->all())
        ->not->toContain($members[1]->id);
    expect(fn () => app(CaseLinks::class)->linkRelated($members[0], $members[1], null, $f['hr']))
        ->toThrow(DomainException::class);
});

test('another member name is refused before an AI payload is built', function (): void {
    $f = incidentFixture();
    $incident = app(IncidentService::class)->createForEmployees($f['employees']->pluck('id')->all(), $f['facts'], $f['hr']);
    $members = $incident->cases()->orderBy('id')->get();
    $members[0]->forceFill(['case_specific_description' => 'The employee was present at the office.'])->save();
    $payload = app(PayloadBuilder::class)->build($members[0]->fresh(), DocumentType::DefenseLetter, collect());
    expect(json_encode($payload))->not->toContain($f['employees'][1]->name);
    $members[0]->forceFill(['alleged_responsible_employee_id' => $f['employees'][1]->id])->save();
    $payload = app(PayloadBuilder::class)->build($members[0]->fresh(), DocumentType::DefenseLetter, collect());
    expect($payload['incident']['alleged_responsible_person'])->toBe('');

    $members[0]->forceFill(['case_specific_description' => $f['employees'][1]->name.' was present.'])->save();
    expect(fn () => app(PayloadBuilder::class)->build($members[0]->fresh(), DocumentType::DefenseLetter, collect()))
        ->toThrow(TransitionBlocked::class);
});

test('an employee document cannot be issued with another incident member named in its text', function (): void {
    $f = incidentFixture();
    $incident = app(IncidentService::class)->createForEmployees($f['employees']->pluck('id')->all(), $f['facts'], $f['hr']);
    $case = $incident->cases()->orderBy('id')->firstOrFail();
    $document = Document::factory()->create(['company_id' => $f['company']->id,
        'case_id' => $case->id, 'created_by' => $f['hr']->id]);
    foreach (['ar', 'en'] as $language) {
        $document->contents()->create(['company_id' => $f['company']->id, 'language_code' => $language,
            'body' => 'Statement about '.$f['employees'][1]->name,
            'is_authoritative' => $language === 'ar']);
    }

    expect(fn () => app(DocumentIssuer::class)->issue($document, $f['hr']))->toThrow(IssuanceBlocked::class)
        ->and($document->fresh()->is_locked)->toBeFalse();
});

test('converting a report with two named employees creates two cases in one incident', function (): void {
    $f = incidentFixture();
    $report = ViolationReport::factory()->create(['company_id' => $f['company']->id,
        'branch_id' => $f['branch']->id, 'employee_id' => $f['employees'][0]->id,
        'reporter_user_id' => $f['hr']->id, 'place_freetext' => 'Office']);
    foreach ($f['employees'] as $employee) {
        $report->employees()->attach($employee->id, ['company_id' => $f['company']->id]);
    }

    $first = app(ReportService::class)->convert($report, $f['hr']);
    $members = $first->incident->cases()->orderBy('id')->get();
    expect($members)->toHaveCount(2)
        ->and($members->pluck('employee_id')->all())->toBe($f['employees']->pluck('id')->all())
        ->and($members->every(fn ($case) => $case->violation_report_id === $report->id))->toBeTrue()
        ->and($report->fresh()->case_id)->toBe($first->id);
});

test('a reporter can name several branch employees while the legacy employee points to the first', function (): void {
    $f = incidentFixture();
    $reporter = User::factory()->create(['company_id' => $f['company']->id, 'primary_role' => 'reporter']);
    DB::table('branch_user')->insert(['company_id' => $f['company']->id,
        'branch_id' => $f['branch']->id, 'user_id' => $reporter->id,
        'created_at' => now(), 'updated_at' => now()]);
    $report = app(ReportService::class)->submit([
        'branch_id' => $f['branch']->id, 'employee_ids' => $f['employees']->pluck('id')->all(),
        'occurred_at' => now(), 'violation_type' => 'Incident', 'description' => 'Shared report facts',
        'place_freetext' => 'Office',
    ], $reporter);

    expect($report->employee_id)->toBe($f['employees'][0]->id)
        ->and($report->employees()->orderBy('employees.id')->pluck('employees.id')->all())
        ->toBe($f['employees']->pluck('id')->all());
});

test('report employee membership rejects a foreign tenant employee', function (): void {
    $f = incidentFixture();
    $report = ViolationReport::factory()->create(['company_id' => $f['company']->id,
        'branch_id' => $f['branch']->id]);
    $foreign = Company::factory()->create();
    $employee = app(TenantContext::class)->runForCompany($foreign, fn () => Employee::factory()->create());
    expect(fn () => DB::table('violation_report_employees')->insert([
        'company_id' => $f['company']->id, 'violation_report_id' => $report->id,
        'employee_id' => $employee->id, 'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('each converted member case grants its viewer access to shared report evidence', function (): void {
    $f = incidentFixture();
    $report = ViolationReport::factory()->create(['company_id' => $f['company']->id,
        'branch_id' => $f['branch']->id, 'employee_id' => $f['employees'][0]->id,
        'reporter_user_id' => $f['hr']->id, 'place_freetext' => 'Office']);
    foreach ($f['employees'] as $employee) {
        $report->employees()->attach($employee->id, ['company_id' => $f['company']->id]);
    }
    $first = app(ReportService::class)->convert($report, $f['hr']);
    $second = $first->incident->cases()->whereKeyNot($first->id)->firstOrFail();
    $staff = User::factory()->create(['company_id' => $f['company']->id, 'primary_role' => 'hr_staff']);
    $second->forceFill(['assigned_hr_user_id' => $staff->id])->save();
    Storage::disk('local')->put('attachments/incident-report.pdf', '%PDF-1.4');
    $attachment = Attachment::create(['company_id' => $f['company']->id,
        'attachable_type' => 'violation_report', 'attachable_id' => $report->id,
        'kind' => AttachmentKind::ReportEvidence, 'disk' => 'local', 'path' => 'attachments/incident-report.pdf',
        'original_name' => 'incident-report.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 8,
        'uploaded_by' => $f['hr']->id, 'created_at' => now()]);

    $this->actingAs($staff)->get(route('attachments.download', $attachment))->assertOk();
    $foreign = Company::factory()->create();
    $foreignUser = User::factory()->create(['company_id' => $foreign->id, 'primary_role' => 'hr_staff']);
    $this->actingAs($foreignUser)->get(route('attachments.download', $attachment))->assertNotFound();
});

test('shared fact edits update every draft and stop after any defense issuance', function (): void {
    $f = incidentFixture();
    $service = app(IncidentService::class);
    $incident = $service->createForEmployees($f['employees']->pluck('id')->all(), $f['facts'], $f['hr']);
    $service->updateSharedFacts($incident, array_replace($f['facts'], ['description' => 'Corrected facts']), $f['hr']);
    expect($incident->cases()->pluck('description')->all())->toBe(['Corrected facts', 'Corrected facts']);

    $first = $incident->cases()->orderBy('id')->firstOrFail();
    $first->forceFill(['defense_letter_issued_at' => now()])->save();
    expect(fn () => $first->forceFill(['case_specific_description' => 'Changed after issue'])->save())
        ->toThrow(ImmutableRecordException::class);
    expect(fn () => $service->updateSharedFacts($incident,
        array_replace($f['facts'], ['description' => 'Too late']), $f['hr']))
        ->toThrow(ValidationException::class);
    expect($incident->cases()->pluck('description')->all())->toBe(['Corrected facts', 'Corrected facts']);
});

test('incident member closure requires an audit reason', function (): void {
    $f = incidentFixture();
    $incident = app(IncidentService::class)->createForEmployees($f['employees']->pluck('id')->all(), $f['facts'], $f['hr']);
    $case = $incident->cases()->firstOrFail();
    expect(fn () => app(CaseClosure::class)->closeWithoutPenalty($case, $f['hr']))
        ->toThrow(ValidationException::class);
});

test('opening an incident runs the ordinary opening path for every employee', function (): void {
    $f = incidentFixture();
    $law = Law::factory()->create(['category' => 'labor', 'approval_status' => 'approved',
        'effective_from' => '2026-01-01']);
    $law->jurisdictions()->create(['jurisdiction' => $f['branch']->jurisdiction->value]);
    completeCompanySetup($f['company']);
    $incident = app(IncidentService::class)->createForEmployees($f['employees']->pluck('id')->all(), $f['facts'], $f['hr']);
    app(IncidentService::class)->openDrafts($incident, $f['hr']);

    expect($incident->cases()->where('status', 'open')->count())->toBe(2)
        ->and($incident->cases()->where('law_version_id', $law->id)->count())->toBe(2)
        ->and($incident->cases()->whereNotNull('defense_letter_issued_at')->count())->toBe(0)
        ->and(DB::table('case_events')->whereIn('case_id', $incident->cases()->pluck('id'))
            ->where('event_type', 'case_opened')->count())->toBe(2);
    $laterEmployee = Employee::factory()->create(['company_id' => $f['company']->id, 'branch_id' => $f['branch']->id]);
    $later = app(IncidentService::class)->addEmployee($incident, $laterEmployee->id, $f['hr']);
    app(IncidentService::class)->openDrafts($incident, $f['hr']);
    expect($later->fresh()->status->value)->toBe('open')
        ->and($incident->cases()->where('status', 'open')->count())->toBe(3);
});

test('incident session is atomic and records each employee vote separately', function (): void {
    $f = incidentFixture();
    $law = Law::factory()->create(['category' => 'labor', 'approval_status' => 'approved',
        'effective_from' => '2026-01-01']);
    $law->jurisdictions()->create(['jurisdiction' => $f['branch']->jurisdiction->value]);
    completeCompanySetup($f['company']);
    $incident = app(IncidentService::class)->createForEmployees($f['employees']->pluck('id')->all(), $f['facts'], $f['hr']);
    app(IncidentService::class)->openDrafts($incident, $f['hr']);
    $cases = $incident->cases()->orderBy('id')->get();
    $laterEmployee = Employee::factory()->create(['company_id' => $f['company']->id, 'branch_id' => $f['branch']->id]);
    $later = app(IncidentService::class)->addEmployee($incident, $laterEmployee->id, $f['hr']);
    DB::statement('SET @displanery_workflow_transition = 1');
    try {
        DB::table('cases')->whereIn('id', $cases->pluck('id'))->update([
            'status' => 'response_received', 'defense_letter_issued_at' => now(),
        ]);
    } finally {
        DB::statement('SET @displanery_workflow_transition = NULL');
    }
    $pool = CommitteePoolMember::query()->where('company_id', $f['company']->id)->orderBy('id')->get();
    $ids = $pool->pluck('id')->all();
    $votes = array_fill_keys($ids, 'approve');
    $perCase = [
        $cases[0]->id => ['minutes' => 'First employee facts', 'outcome' => 'warning',
            'defense_acceptance' => 'rejected', 'votes' => $votes],
        $cases[1]->id => ['minutes' => 'Second employee facts', 'outcome' => 'recommendation_only',
            'defense_acceptance' => 'accepted_fully', 'votes' => $votes],
    ];
    $invalid = $perCase;
    $invalid[$cases[1]->id]['votes'][$ids[0]] = '';
    expect(fn () => app(CommitteeSessionService::class)->recordForIncident($incident, $ids,
        $ids[0], 'Internal common minutes', $invalid, $f['hr'], $ids[0]))->toThrow(TransitionBlocked::class);
    expect(DB::table('incident_sessions')->count())->toBe(0)
        ->and(DB::table('committee_session_attendances')->whereIn('case_id', $cases->pluck('id'))->count())->toBe(0);
    $privateViolation = $perCase;
    $privateViolation[$cases[0]->id]['minutes'] = $cases[1]->employee->name.' attended.';
    expect(fn () => app(CommitteeSessionService::class)->recordForIncident($incident, $ids,
        $ids[0], 'Internal common minutes', $privateViolation, $f['hr'], $ids[0]))->toThrow(TransitionBlocked::class);

    $result = app(CommitteeSessionService::class)->recordForIncident($incident, $ids, $ids[0],
        'Internal common minutes', $perCase, $f['hr'], $ids[0]);
    expect($cases[0]->fresh()->outcome->value)->toBe('warning')
        ->and($cases[1]->fresh()->outcome->value)->toBe('recommendation_only')
        ->and($result['skipped'])->toBe([['case_id' => $later->id, 'reason' => 'not_ready']])
        ->and(DB::table('incident_sessions')->count())->toBe(1)
        ->and(DB::table('committee_session_attendances')->whereIn('case_id', $cases->pluck('id'))->count())->toBe(6);
});
