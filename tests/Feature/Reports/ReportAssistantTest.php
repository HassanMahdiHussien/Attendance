<?php

use App\Ai\InvalidAiOutput;
use App\Ai\PayloadBuilder;
use App\Jobs\AskReportAssistant;
use App\Livewire\Reports\SubmitReport;
use App\Models\Branch;
use App\Models\BranchUser;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Location;
use App\Models\ReportAssistantSession;
use App\Models\User;
use App\Services\Reports\ReportAssistant;
use App\Services\Reports\ReportService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Fakes\FakeClaudeClient;

function reportAssistantFixture(): array
{
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $branch = Branch::factory()->create(['company_id' => $company->id]);
    $otherBranch = Branch::factory()->create(['company_id' => $company->id]);
    $reporter = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'reporter']);
    BranchUser::factory()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'user_id' => $reporter->id]);
    $employee = Employee::factory()->create(['company_id' => $company->id, 'branch_id' => $branch->id,
        'name' => 'رنا ياسر جبار', 'name_en' => 'Rana Yaser Jabbar', 'employee_code' => 'EMP-0002']);
    Employee::factory()->create(['company_id' => $company->id, 'branch_id' => $otherBranch->id,
        'name' => 'رنا أخرى', 'name_en' => 'Rana Other']);

    return compact('company', 'branch', 'reporter', 'employee');
}

function reportTurn(array $facts = [], array $other = []): array
{
    return array_replace([
        'facts' => array_replace(['what' => null, 'place_text' => null, 'location_name' => null,
            'when_text' => null, 'when_iso' => null, 'people' => [], 'how_started' => null,
            'injuries_or_damage' => null], $facts),
        'missing' => ['who', 'what', 'where', 'when', 'witnesses'],
        'next_question' => ['text' => 'Who was involved?', 'quick_replies' => ['I do not know']],
        'done' => false, 'summary' => null,
    ], $other);
}

test('Arabic opening is read as context and the place is filled before another question', function (): void {
    $f = reportAssistantFixture();
    config()->set('services.anthropic.key', 'test-key');
    Bus::fake([AskReportAssistant::class]);
    $assistant = app(ReportAssistant::class);
    $session = $assistant->start($f['reporter'], $f['branch']->id, 'حدث شجار في الخط 1');
    $fake = new FakeClaudeClient;
    $fake->push(reportTurn(['what' => 'حدث شجار', 'place_text' => 'الخط 1'],
        ['missing' => ['who', 'when', 'witnesses'],
            'next_question' => ['text' => 'من كان طرفاً في الشجار؟', 'quick_replies' => ['لا أعرف']]]));
    (new AskReportAssistant($session->id, $f['company']->id, $session->request_token))->handle($fake, $assistant);
    $session->refresh();
    expect($session->facts['place'])->toBe('الخط 1')
        ->and($session->facts['description'])->toBe('حدث شجار')
        ->and($session->transcript[array_key_last($session->transcript)]['text'])->toBe('من كان طرفاً في الشجار؟')
        ->and($session->pending_aspect)->toBe('who');
    $payload = json_decode($fake->requests[0]['messages'][0]['content'], true);
    expect($payload['conversation'][1]['text'])->toBe('حدث شجار في الخط 1')
        ->and($payload['language'])->toBe('ar')
        ->and($payload['timezone'])->toBe('Asia/Baghdad')
        ->and($fake->requests[0]['model'])->toBe(config('displanery.ai.report_assistant_model'));
    $assistant->answer($session, $f['reporter'], 'سائق شركة النقل');
    $next = $assistant->context($session->refresh(), app(PayloadBuilder::class));
    expect($next['masked'][2]['text'])->toBe('من كان طرفاً في الشجار؟')
        ->and($next['masked'][3]['text'])->toBe('سائق شركة النقل');
});

test('a reporter place maps to the branch location list entry but a location needs a traced place', function (): void {
    $f = reportAssistantFixture();
    config()->set('services.anthropic.key', 'test-key');
    Bus::fake([AskReportAssistant::class]);
    $location = Location::factory()->create(['company_id' => $f['company']->id, 'branch_id' => $f['branch']->id,
        'name' => 'خط الإنتاج 1', 'is_active' => true]);
    $assistant = app(ReportAssistant::class);
    $session = $assistant->start($f['reporter'], $f['branch']->id, 'حدث شجار في الخط 1');
    $builder = app(PayloadBuilder::class);
    $context = $assistant->context($session, $builder);
    $other = ['missing' => ['who', 'when', 'witnesses'], 'next_question' => ['text' => 'متى حدث الشجار؟', 'quick_replies' => []]];
    expect(fn () => $assistant->acceptTurn($session, reportTurn(['what' => 'حدث شجار', 'location_name' => 'خط الإنتاج 1'], $other),
        $context, $builder))->toThrow(InvalidAiOutput::class);
    $assistant->acceptTurn($session, reportTurn(['what' => 'حدث شجار', 'place_text' => 'الخط 1', 'location_name' => 'خط الإنتاج 1'], $other),
        $context, $builder);
    expect($session->refresh()->facts['location_id'])->toBe($location->id);
});

test('an hour written as a word is traced, duplicate chips are dropped and mixed-language questions are rejected', function (): void {
    $f = reportAssistantFixture();
    config()->set('services.anthropic.key', 'test-key');
    Bus::fake([AskReportAssistant::class]);
    $assistant = app(ReportAssistant::class);
    $session = $assistant->start($f['reporter'], $f['branch']->id, 'حدث شجار أمس حوالي الساعة العاشرة صباحاً');
    $builder = app(PayloadBuilder::class);
    $context = $assistant->context($session, $builder);
    $turn = fn (string $iso, string $question) => reportTurn(['what' => 'حدث شجار', 'when_text' => 'أمس حوالي الساعة العاشرة صباحاً',
        'when_iso' => now('Asia/Baghdad')->subDay()->format('Y-m-d').'T'.$iso],
        ['missing' => ['who', 'where', 'witnesses'], 'next_question' => ['text' => $question, 'quick_replies' => ['لا أعرف', 'غير معروف']]]);
    expect(fn () => $assistant->acceptTurn($session, $turn('11:00', 'من كان طرفاً؟'), $context, $builder))->toThrow(InvalidAiOutput::class)
        ->and(fn () => $assistant->acceptTurn($session, $turn('10:00', 'هل كان there أحد؟'), $context, $builder))->toThrow(InvalidAiOutput::class);
    $assistant->acceptTurn($session, $turn('10:00', 'من كان طرفاً؟'), $context, $builder);
    expect($session->refresh()->facts['occurred_at'])->toContain('10:00')
        ->and($session->facts['quick_replies'])->toBe([]);
});

test('company employee names and contact data are tokens in the Claude payload and matches remain scoped', function (): void {
    $f = reportAssistantFixture();
    config()->set('services.anthropic.key', 'test-key');
    Bus::fake([AskReportAssistant::class]);
    $assistant = app(ReportAssistant::class);
    $session = $assistant->start($f['reporter'], $f['branch']->id,
        'Rana Yaser Jabbar pushed a gate worker at '.$f['branch']->name.'. '
        .$f['reporter']->name.' called +964 770 123 4567.');
    expect($session->language)->toBe('en');
    $fake = new FakeClaudeClient;
    $builder = app(PayloadBuilder::class);
    $context = $assistant->context($session, $builder);
    $payload = $context['messages'][0]['content'];
    expect($payload)->toContain('⟦employee_')
        ->toContain('⟦branch_')
        ->not->toContain('Rana Yaser Jabbar', 'رنا ياسر جبار', 'EMP-0002', '+964 770 123 4567',
            $f['branch']->name, $f['reporter']->name);
    preg_match('/⟦employee_\d+⟧/u', $payload, $token);
    $fake->push(reportTurn(['what' => 'pushed a gate worker', 'people' => [['ref' => $token[0], 'role' => 'involved']]],
        ['missing' => ['where', 'when', 'witnesses'],
            'next_question' => ['text' => 'Where did this happen?', 'quick_replies' => []]]));
    (new AskReportAssistant($session->id, $f['company']->id, $session->request_token))->handle($fake, $assistant);
    $session->refresh();
    expect($session->facts['pending_match']['candidate']['id'])->toBe($f['employee']->id);
    $assistant->confirmMatch($session, $f['reporter'], 'yes');
    expect($session->fresh()->facts['employees'][0]['employee_id'])->toBe($f['employee']->id);
});

test('validator rejects invented place, date, person and summary date', function (): void {
    $f = reportAssistantFixture();
    config()->set('services.anthropic.key', 'test-key');
    Bus::fake([AskReportAssistant::class]);
    $assistant = app(ReportAssistant::class);
    $session = $assistant->start($f['reporter'], $f['branch']->id, 'A gate was damaged today at 10:00.');
    $builder = app(PayloadBuilder::class);
    $context = $assistant->context($session, $builder);
    $base = reportTurn(['what' => 'A gate was damaged', 'when_text' => 'today at 10:00'],
        ['missing' => ['who', 'where', 'witnesses']]);
    expect(fn () => $assistant->acceptTurn($session, reportTurn(['place_text' => 'Warehouse 9']), $context, $builder))
        ->toThrow(InvalidAiOutput::class);
    expect(fn () => $assistant->acceptTurn($session, reportTurn(['people' => [['ref' => 'Unknown person', 'role' => 'involved']]]), $context, $builder))
        ->toThrow(InvalidAiOutput::class);
    $wrongDate = $base;
    $wrongDate['facts']['when_iso'] = now('Asia/Baghdad')->subDays(5)->format('Y-m-d').'T10:00';
    expect(fn () => $assistant->acceptTurn($session, $wrongDate, $context, $builder))
        ->toThrow(InvalidAiOutput::class);
    $summaryDate = $base;
    $summaryDate['done'] = true;
    $summaryDate['summary'] = 'According to the reporter: A gate was damaged on 2025-01-01.';
    expect(fn () => $assistant->acceptTurn($session, $summaryDate, $context, $builder))
        ->toThrow(InvalidAiOutput::class);
});

test('fact traceability accepts source word overlap and rejects a new content word', function (): void {
    $f = reportAssistantFixture();
    config()->set('services.anthropic.key', 'test-key');
    Bus::fake([AskReportAssistant::class]);
    $assistant = app(ReportAssistant::class);
    $session = $assistant->start($f['reporter'], $f['branch']->id, 'The loading gate was badly damaged');
    $builder = app(PayloadBuilder::class);
    $context = $assistant->context($session, $builder);
    // The opening already filled the description, so an untraceable rewording keeps the reporter's words.
    $assistant->acceptTurn($session, reportTurn(['what' => 'warehouse gate damaged']), $context, $builder);
    expect($session->fresh()->facts['description'])->not->toContain('warehouse')
        ->and($assistant->traceable('warehouse gate damaged', 'The loading gate was badly damaged'))->toBeFalse()
        ->and($assistant->traceable('شجاراً بين عاملين', 'حدث شجار بين عاملان'))->toBeTrue();
    $assistant->acceptTurn($session->refresh(), reportTurn(['what' => 'loading gate damaged']), $context, $builder);
    expect($session->fresh()->facts['description'])->toBe('loading gate damaged');
});

test('a reporter supplied free person stays a free name', function (): void {
    $f = reportAssistantFixture();
    config()->set('services.anthropic.key', 'test-key');
    Bus::fake([AskReportAssistant::class]);
    $assistant = app(ReportAssistant::class);
    $session = $assistant->start($f['reporter'], $f['branch']->id, 'سائق شركة النقل تشاجر في الخط 1');
    $builder = app(PayloadBuilder::class);
    $context = $assistant->context($session, $builder);
    $assistant->acceptTurn($session, reportTurn(['what' => 'تشاجر', 'place_text' => 'الخط 1',
        'people' => [['ref' => 'سائق شركة النقل', 'role' => 'involved']]],
        ['missing' => ['when', 'witnesses'],
            'next_question' => ['text' => 'متى حدث ذلك؟', 'quick_replies' => ['لا أعرف']]]), $context, $builder);
    expect($session->fresh()->facts['employees'][0])->toEqual(['employee_id' => null, 'name' => 'سائق شركة النقل']);
});

test('an employee token from another branch cannot become a selected person', function (): void {
    $f = reportAssistantFixture();
    config()->set('services.anthropic.key', 'test-key');
    Bus::fake([AskReportAssistant::class]);
    $assistant = app(ReportAssistant::class);
    $session = $assistant->start($f['reporter'], $f['branch']->id, 'Rana Other was at the gate.');
    $builder = app(PayloadBuilder::class);
    $context = $assistant->context($session, $builder);
    preg_match('/⟦employee_\d+⟧/u', $context['messages'][0]['content'], $token);
    expect(fn () => $assistant->acceptTurn($session, reportTurn(['people' => [['ref' => $token[0], 'role' => 'involved']]]),
        $context, $builder))->toThrow(InvalidAiOutput::class);
});

test('done is rejected while a required item is missing and Arabic summaries stay Arabic', function (): void {
    $f = reportAssistantFixture();
    config()->set('services.anthropic.key', 'test-key');
    Bus::fake([AskReportAssistant::class]);
    $assistant = app(ReportAssistant::class);
    $session = $assistant->start($f['reporter'], $f['branch']->id,
        'حدث شجار في الخط 1 اليوم. لا أعرف من شارك ولا يوجد شهود.');
    $builder = app(PayloadBuilder::class);
    $context = $assistant->context($session, $builder);
    $turn = reportTurn(['what' => 'حدث شجار', 'place_text' => 'الخط 1', 'when_text' => 'اليوم'],
        ['done' => true, 'summary' => 'أفاد المُبلّغ بأن حدث شجار في الخط 1 اليوم.', 'next_question' => null]);
    $session->update(['facts' => array_replace($session->facts, ['employees_unknown' => false, 'witnesses_none' => false])]);
    expect(fn () => $assistant->acceptTurn($session, $turn, $context, $builder))->toThrow(InvalidAiOutput::class);
    $session->update(['facts' => array_replace($session->facts, ['employees_unknown' => true, 'witnesses_none' => true])]);
    $wrongLanguage = $turn;
    $wrongLanguage['summary'] = 'According to the reporter: حدث شجار في الخط 1 اليوم.';
    expect(fn () => $assistant->acceptTurn($session->refresh(), $wrongLanguage, $context, $builder))
        ->toThrow(InvalidAiOutput::class);
    $assistant->acceptTurn($session->refresh(), $turn, $context, $builder);
    expect($session->fresh()->facts['summary'])->toStartWith('أفاد المُبلّغ بأن')
        ->and($session->fresh()->status)->toBe('ready');
    $report = app(ReportService::class)->submit(['branch_id' => $f['branch']->id,
        'employees_unknown' => true, 'description' => 'حدث شجار', 'place_freetext' => 'الخط 1',
        'occurred_at_text' => 'اليوم', 'witnesses_none' => true, 'violation_type' => 'Other',
        'assistant_session_id' => $session->id, 'assistant_summary' => $session->fresh()->facts['summary']], $f['reporter']);
    expect($report->summary)->toBe($turn['summary']);
});

test('the six-question limit and empty key retain the fallback path', function (): void {
    $f = reportAssistantFixture();
    config()->set('services.anthropic.key', null);
    Bus::fake([AskReportAssistant::class]);
    $assistant = app(ReportAssistant::class);
    $session = $assistant->start($f['reporter'], $f['branch']->id, 'A worker fell');
    expect($session->status)->toBe('fallback');
    Bus::assertNotDispatched(AskReportAssistant::class);
    $session->update(['status' => 'ready', 'question_count' => 6]);
    $assistant->advance($session->refresh());
    expect($session->fresh()->status)->toBe('fallback');
});

test('the sixth answer is extracted without asking a seventh question', function (): void {
    $f = reportAssistantFixture();
    config()->set('services.anthropic.key', 'test-key');
    Bus::fake([AskReportAssistant::class]);
    $session = ReportAssistantSession::factory()->create(['company_id' => $f['company']->id,
        'user_id' => $f['reporter']->id, 'status' => 'ready', 'question_count' => 6,
        'pending_aspect' => 'when', 'facts' => ['branch_id' => $f['branch']->id,
            'description' => 'A gate was damaged', 'employees' => [], 'witnesses' => []],
        'transcript' => [['role' => 'reporter', 'text' => 'A gate was damaged']]]);
    app(ReportAssistant::class)->answer($session, $f['reporter'], 'I do not know');
    expect($session->fresh()->status)->toBe('pending')
        ->and($session->fresh()->facts['occurred_at_text'])->toBe('I do not know')
        ->and($session->fresh()->question_count)->toBe(6);
    Bus::assertDispatched(AskReportAssistant::class);
});

test('invalid queued output retries and ultimately opens the form without losing the transcript', function (): void {
    $f = reportAssistantFixture();
    config()->set('services.anthropic.key', 'test-key');
    Bus::fake([AskReportAssistant::class]);
    $assistant = app(ReportAssistant::class);
    $session = $assistant->start($f['reporter'], $f['branch']->id, 'A gate was damaged');
    $fake = new FakeClaudeClient;
    $fake->push(reportTurn(['place_text' => 'Invented warehouse']));
    $job = new AskReportAssistant($session->id, $f['company']->id, $session->request_token);
    expect(fn () => $job->handle($fake, $assistant))->toThrow(InvalidAiOutput::class);
    $job->failed(new InvalidAiOutput('Invalid turn.'));
    expect($session->fresh()->status)->toBe('fallback')
        ->and($session->fresh()->transcript[1]['text'])->toBe('A gate was damaged');
});

test('a stalled queued turn opens the form', function (): void {
    $f = reportAssistantFixture();
    $session = ReportAssistantSession::factory()->create(['company_id' => $f['company']->id,
        'user_id' => $f['reporter']->id, 'status' => 'pending', 'facts' => ['branch_id' => $f['branch']->id]]);
    DB::table('report_assistant_sessions')->where('id', $session->id)->update(['updated_at' => now()->subMinutes(2)]);
    expect(app(ReportAssistant::class)->poll($session->id, $f['reporter'])->status)->toBe('fallback');
});

test('sent transcripts stay immutable and the plain form enforces required answers', function (): void {
    $f = reportAssistantFixture();
    $service = app(ReportService::class);
    expect(fn () => $service->submit(['branch_id' => $f['branch']->id, 'employee_id' => $f['employee']->id,
        'description' => 'A gate was damaged', 'violation_type' => 'Other'], $f['reporter']))
        ->toThrow(ValidationException::class);
    $session = ReportAssistantSession::factory()->create(['company_id' => $f['company']->id,
        'user_id' => $f['reporter']->id, 'language' => 'en',
        'facts' => ['branch_id' => $f['branch']->id],
        'transcript' => [['role' => 'reporter', 'text' => 'A gate was damaged']]]);
    $report = $service->submit(['branch_id' => $f['branch']->id, 'employee_id' => $f['employee']->id,
        'description' => 'A gate was damaged', 'violation_type' => 'Other',
        'place_freetext' => 'Gate 3', 'occurred_at_text' => 'I do not know',
        'witnesses_unknown' => true, 'assistant_session_id' => $session->id], $f['reporter']);
    expect($report->summary)->toBe('According to the reporter: A gate was damaged')
        ->and($report->assistantSession->confirmed_fields['place'])->toBe('Gate 3');
    expect(fn () => DB::table('report_assistant_sessions')->where('id', $session->id)
        ->update(['transcript' => '[]']))->toThrow(QueryException::class);
});

test('a finished chat continues straight to the summary review with the reporter wording as the type', function (): void {
    $f = reportAssistantFixture();
    config()->set('services.anthropic.key', 'test-key');
    Bus::fake([AskReportAssistant::class]);
    $this->actingAs($f['reporter']);
    $component = Livewire::test(SubmitReport::class)->assertSet('branchId', (string) $f['branch']->id)
        ->set('chatInput', 'حدث شجار في الخط 1 أمس بين عاملين ولا يوجد شهود')->call('startChat');
    $session = ReportAssistantSession::query()->latest('id')->firstOrFail();
    $fake = new FakeClaudeClient;
    $fake->push(reportTurn(['what' => 'حدث شجار', 'place_text' => 'الخط 1', 'when_text' => 'أمس',
        'people' => [['ref' => 'عاملين', 'role' => 'involved'], ['ref' => 'بين عاملين', 'role' => 'involved']]],
        ['missing' => [], 'next_question' => null, 'done' => true,
            'summary' => 'أفاد المُبلّغ بأن شجاراً حدث في الخط 1 أمس بين عاملين ولا يوجد شهود.']));
    (new AskReportAssistant($session->id, $f['company']->id, $session->request_token))->handle($fake, app(ReportAssistant::class));
    $component->call('pollAssistant')->call('continueToReview')->assertHasNoErrors()
        ->assertSet('reviewing', true)->assertSet('violationType', 'حدث شجار')
        ->assertSee('أفاد المُبلّغ بأن شجاراً حدث في الخط 1')
        ->assertDontSee(__('Alleged responsible person'));
    expect($component->get('people')['employees'])->toEqual([['employee_id' => null, 'name' => 'بين عاملين']]);
});

test('fallback form still requires identity and witnesses', function (): void {
    $f = reportAssistantFixture();
    config()->set('services.anthropic.key', null);
    $this->actingAs($f['reporter']);
    Livewire::test(SubmitReport::class)->set('branchId', (string) $f['branch']->id)
        ->set('place', 'Gate 3')->set('occurredAtText', 'I do not know')
        ->set('violationType', 'Other')->set('description', 'The gate was damaged')
        ->call('submit')->assertHasErrors(['people.employees']);
    Livewire::test(SubmitReport::class)->set('branchId', (string) $f['branch']->id)
        ->set('employeesUnknown', true)->set('place', 'Gate 3')
        ->set('occurredAtText', 'I do not know')->set('violationType', 'Other')
        ->set('description', 'The gate was damaged')->call('submit')->assertHasErrors(['witnessesNone']);
});
