<?php

use App\Ai\PayloadBuilder;
use App\Documents\DocumentFacts;
use App\Enums\DocumentType;
use App\Livewire\Cases\OpenFromReport;
use App\Livewire\Reports\Inbox;
use App\Models\Branch;
use App\Models\CommitteeMandate;
use App\Models\CommitteePoolMember;
use App\Models\Company;
use App\Models\DisciplinaryCase;
use App\Models\Document;
use App\Models\Employee;
use App\Models\Law;
use App\Models\ReportAssistantSession;
use App\Models\User;
use App\Models\ViolationReport;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

function openReportFixture(array $attributes = []): array
{
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $branch = Branch::factory()->create(['company_id' => $company->id]);
    $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $reporter = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'reporter']);
    $employee = Employee::factory()->create(['company_id' => $company->id, 'branch_id' => $branch->id,
        'name' => 'Rana Yaser', 'name_en' => 'Rana Yaser']);
    $report = ViolationReport::factory()->create(array_replace([
        'company_id' => $company->id, 'branch_id' => $branch->id, 'reporter_user_id' => $reporter->id,
        'employee_id' => $employee->id, 'place_freetext' => 'Gate 3',
        'occurred_at' => now('Asia/Baghdad')->subDays(4), 'description' => 'A conveyor incident',
        'violation_type' => 'Safety incident',
    ], $attributes));
    $report->employees()->attach($employee->id, ['company_id' => $company->id]);

    return compact('company', 'branch', 'hr', 'reporter', 'employee', 'report');
}

function openReportLaw(array $fixture): void
{
    $law = Law::factory()->create(['category' => 'labor', 'approval_status' => 'approved',
        'effective_from' => '2026-01-01']);
    $law->jurisdictions()->create(['jurisdiction' => $fixture['branch']->jurisdiction->value]);
    completeCompanySetup($fixture['company']);
}

test('inbox opens the dedicated screen and shows the approved summary and conversation', function (): void {
    $f = openReportFixture(['summary' => 'Reporter approved this summary']);
    ReportAssistantSession::factory()->create(['company_id' => $f['company']->id,
        'user_id' => $f['reporter']->id, 'violation_report_id' => $f['report']->id,
        'language' => 'en', 'facts' => ['summary' => 'Reporter approved this summary'],
        'transcript' => [['role' => 'reporter', 'text' => 'The conveyor stopped']]]);
    $this->actingAs($f['hr']);
    Livewire::test(Inbox::class)->call('convert', $f['report']->id)
        ->assertRedirect('/cases/from-report/'.$f['report']->id);
    Livewire::test(OpenFromReport::class, ['report' => $f['report']->id])
        ->assertSee('Reporter approved this summary')->assertSee('The conveyor stopped')
        ->assertDontSee(__('Check my description'));
});

test('assistant intake answers remain visible and carry into the draft', function (): void {
    $f = openReportFixture();
    ReportAssistantSession::factory()->create(['company_id' => $f['company']->id,
        'user_id' => $f['reporter']->id, 'violation_report_id' => $f['report']->id]);
    $f['report']->intakeQuestions()->create(['company_id' => $f['company']->id, 'source' => 'ai',
        'aspect' => 'where', 'question' => 'Which gate?', 'answer' => 'Gate 3', 'sort_order' => 1]);
    $this->actingAs($f['hr']);
    Livewire::test(OpenFromReport::class, ['report' => $f['report']->id])
        ->assertSee('Which gate?')->assertDontSee(__('Check my description'))
        ->call('save')->assertHasNoErrors();
    $case = DisciplinaryCase::query()->where('violation_report_id', $f['report']->id)->firstOrFail();
    expect($case->intakeQuestions()->firstOrFail()->answer)->toBe('Gate 3');
    expect($f['report']->fresh()->status->value)->toBe('converted_to_case')
        ->and(DB::table('audit_logs')->where('action', 'report.converted')->where('entity_id', $f['report']->id)->exists())
        ->toBeTrue();
});

test('matching preselects one exact result, requires a choice for ambiguous and missing names, and merges repeated employees', function (): void {
    $f = openReportFixture(['free_employee_names' => ['Rana Yaser', 'Ali Karim', 'Missing Person']]);
    $ali = Employee::factory()->create(['company_id' => $f['company']->id, 'branch_id' => $f['branch']->id,
        'name' => 'Ali Karim Hassan']);
    Employee::factory()->create(['company_id' => $f['company']->id, 'branch_id' => $f['branch']->id,
        'name' => 'Ali Karim Jasim']);
    $this->actingAs($f['hr']);
    $form = Livewire::test(OpenFromReport::class, ['report' => $f['report']->id])
        ->assertSet('people.1.selected_id', $f['employee']->id)
        ->assertSet('people.2.selected_id', null)
        ->assertSet('people.3.selected_id', null)
        ->call('save')->assertHasErrors(['people.2']);
    $form->call('selectPerson', 2, $ali->id)->call('save')->assertHasErrors(['people.3']);
    $form->call('selectPerson', 3, $f['employee']->id)->call('save')->assertHasNoErrors();
    expect(DisciplinaryCase::query()->where('violation_report_id', $f['report']->id)->count())->toBe(2);
});

test('unknown place is not copied and date without time is stored and shown without midnight', function (): void {
    $f = openReportFixture(['place_freetext' => __('I do not know'), 'occurred_at' => null,
        'occurred_at_text' => '13-10-2026']);
    $f['report']->witnesses()->create(['company_id' => $f['company']->id, 'name' => 'Outside witness',
        'employee_id' => null]);
    $this->actingAs($f['hr']);
    $form = Livewire::test(OpenFromReport::class, ['report' => $f['report']->id])
        ->assertSet('place', '')->assertSet('incidentDate', '2026-10-13')
        ->set('place', 'Gate 4')->call('save')->assertHasNoErrors();
    $case = DisciplinaryCase::query()->where('violation_report_id', $f['report']->id)->firstOrFail();
    expect($case->violation_time_known)->toBeFalse()
        ->and($case->violation_time)->toStartWith('2026-10-13 00:00')
        ->and($case->violation_place_freetext)->toBe('Gate 4')
        ->and($case->witnesses()->firstOrFail()->name)->toBe('Outside witness');
    $this->get('/cases/'.$case->id)->assertOk()->assertSee(__('Time not stated'))->assertDontSee('00:00');
    $payload = app(PayloadBuilder::class)->build($case, DocumentType::DefenseLetter, collect());
    expect($payload['incident']['time'])->toBeNull()
        ->and($payload['incident']['time_stated'])->toBeFalse();
    $document = Document::factory()->create(['company_id' => $f['company']->id, 'case_id' => $case->id]);
    [$facts] = app(DocumentFacts::class)->collect($document, 'en', true);
    expect($facts['complaint_date'])->toContain('time not stated')->not->toContain('00:00');
});

test('open without findings proceeds, while duplicates require a link or separate choice', function (): void {
    $f = openReportFixture();
    openReportLaw($f);
    $this->actingAs($f['hr']);
    Livewire::test(OpenFromReport::class, ['report' => $f['report']->id])
        ->call('open')->assertHasNoErrors();
    expect(DisciplinaryCase::query()->where('violation_report_id', $f['report']->id)->firstOrFail()->status->value)
        ->toBe('open');

    $next = openReportFixture();
    openReportLaw($next);
    DisciplinaryCase::factory()->create(['company_id' => $next['company']->id, 'branch_id' => $next['branch']->id,
        'employee_id' => $next['employee']->id, 'opened_by_user_id' => $next['hr']->id,
        'violation_time' => $next['report']->occurred_at]);
    $this->actingAs($next['hr']);
    $form = Livewire::test(OpenFromReport::class, ['report' => $next['report']->id])
        ->call('open')->assertSet('findings.duplicates.0.employee', $next['employee']->name);
    $otherId = $form->get('findings')['duplicates'][0]['id'];
    $form->call('confirmOpen', $otherId)->assertHasNoErrors();
    expect(DisciplinaryCase::query()->where('violation_report_id', $next['report']->id)->firstOrFail()->status->value)
        ->toBe('open');
    expect(DB::table('case_links')->where('linked_case_id', $otherId)->where('link_type', 'duplicate_merge')->exists())
        ->toBeTrue();
    $separate = ViolationReport::factory()->create(['company_id' => $next['company']->id,
        'branch_id' => $next['branch']->id, 'reporter_user_id' => $next['reporter']->id,
        'employee_id' => $next['employee']->id, 'place_freetext' => 'Gate 3',
        'occurred_at' => $next['report']->occurred_at, 'violation_type' => 'Safety incident']);
    $separate->employees()->attach($next['employee']->id, ['company_id' => $next['company']->id]);
    Livewire::test(OpenFromReport::class, ['report' => $separate->id])
        ->call('open')->assertSet('findings.duplicates.0.employee', $next['employee']->name)
        ->call('confirmOpen')->assertHasNoErrors();
    expect(DisciplinaryCase::query()->where('violation_report_id', $separate->id)->firstOrFail()->status->value)
        ->toBe('open');
    expect(DB::table('audit_logs')->where('action', 'report.case_opening_choice')->count())->toBeGreaterThanOrEqual(3);
});

test('opening checks committee conflicts before proceeding', function (): void {
    $f = openReportFixture();
    openReportLaw($f);
    $mandate = CommitteeMandate::query()->where('company_id', $f['company']->id)->firstOrFail();
    CommitteePoolMember::factory()->create(['company_id' => $f['company']->id,
        'mandate_id' => $mandate->id, 'branch_id' => $f['branch']->id,
        'person_name' => $f['employee']->name, 'employee_id' => $f['employee']->id]);
    $this->actingAs($f['hr']);
    Livewire::test(OpenFromReport::class, ['report' => $f['report']->id])
        ->call('open')->assertSet('findings.conflicts.0.name', $f['employee']->name)
        ->assertSee(__('Possible conflict of interest'));
    expect(DisciplinaryCase::query()->where('violation_report_id', $f['report']->id)->firstOrFail()->status->value)
        ->toBe('draft');
});

test('another company cannot open or select people from a report', function (): void {
    $f = openReportFixture(['free_employee_names' => ['Missing Person']]);
    $foreign = Company::factory()->create();
    app(TenantContext::class)->runForCompany($foreign, function () use ($f, $foreign): void {
        $outsider = User::factory()->create(['company_id' => $foreign->id, 'primary_role' => 'company_admin']);
        $this->actingAs($outsider);
        $this->get('/cases/from-report/'.$f['report']->id)->assertNotFound();
    });
});
