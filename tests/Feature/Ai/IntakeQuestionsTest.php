<?php

use App\Ai\ClaudeClient;
use App\Ai\IntakeQuestions;
use App\Ai\InvalidAiOutput;
use App\Ai\PayloadBuilder;
use App\Enums\DocumentType;
use App\Jobs\SuggestIntakeQuestions;
use App\Livewire\Reports\SubmitReport;
use App\Models\Branch;
use App\Models\BranchUser;
use App\Models\Company;
use App\Models\DisciplinaryCase;
use App\Models\Employee;
use App\Models\IntakeCheck;
use App\Models\IntakeQuestion;
use App\Models\User;
use App\Models\ViolationReport;
use App\Services\Intake\IntakeQuestionService;
use App\Services\Reports\ReportService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Fakes\FakeClaudeClient;

test('queued intake asks only from minimal fields and stores validated questions', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $user = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'reporter']);
    config()->set('services.anthropic.key', 'test-key');
    Bus::fake([SuggestIntakeQuestions::class]);
    $check = app(IntakeQuestionService::class)->start($user, [
        'description' => 'A fight happened. Call +964 770 123 4567.', 'violation_type' => 'Fight',
        'place' => 'Line 3', 'time' => '2026-10-09 03:00', 'employee_id' => 123,
    ]);
    Bus::assertDispatched(SuggestIntakeQuestions::class);
    $fake = new FakeClaudeClient;
    $fake->push(['complete' => false, 'questions' => [
        ['aspect' => 'who', 'question' => 'Who was involved?'],
    ]]);
    app()->instance(ClaudeClient::class, $fake);
    (new SuggestIntakeQuestions($check->id, $company->id, $check->request_token))->handle($fake, app(IntakeQuestions::class), app(PayloadBuilder::class));
    expect($check->fresh()->status)->toBe('ready');
    expect($check->fresh()->input)->toBe([]);
    expect($check->fresh()->result['questions'])->toHaveCount(1);
    expect($fake->requests[0]['touchpoint'])->toBe('intake_questions');
    expect($fake->requests[0]['messages'][0]['content'])->not->toContain('employee_id', '+964 770 123 4567');
    expect($fake->requests[0]['logContext'])->toHaveKeys(['company_id', 'actor_user_id']);
});

test('a check is visible only to its requester and its exact form snapshot', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $first = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'reporter']);
    $second = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'reporter']);
    config()->set('services.anthropic.key', null);
    $service = app(IntakeQuestionService::class);
    $fields = ['description' => 'An incident occurred'];
    $check = $service->start($first, $fields);
    expect($service->ownedCheck($check->id, $first, $fields)->id)->toBe($check->id);
    expect(fn () => $service->ownedCheck($check->id, $second, $fields))->toThrow(ModelNotFoundException::class);
    expect(fn () => $service->ownedCheck($check->id, $first, ['description' => 'Changed']))->toThrow(HttpException::class);
});

test('invalid intake shape is rejected and no-key checklist is rate limited without blocking facts', function (): void {
    expect(fn () => app(IntakeQuestions::class)->validate(['complete' => false, 'questions' => [
        ['aspect' => 'who', 'question' => 'Who?', 'answer' => 'An invented answer'],
    ]]))->toThrow(InvalidAiOutput::class);
    expect(fn () => app(IntakeQuestions::class)->validate(['complete' => false, 'questions' => array_map(
        fn ($index) => ['aspect' => 'other', 'question' => 'Question '.$index.'?'], range(1, 7),
    )]))->toThrow(InvalidAiOutput::class);
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $user = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'reporter']);
    config()->set('services.anthropic.key', null);
    $service = app(IntakeQuestionService::class);
    for ($index = 0; $index < 5; $index++) {
        $check = $service->start($user, ['description' => 'An incident occurred']);
        expect($check->status)->toBe('checklist');
        expect($check->result['questions'])->toHaveCount(8);
    }
    expect(fn () => $service->start($user, ['description' => 'An incident occurred']))->toThrow(ValidationException::class);
    expect(IntakeCheck::query()->where('user_id', $user->id)->count())->toBe(5);
});

test('human answers copy from a report to a case and only answered pairs enter defense drafting', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $report = ViolationReport::factory()->create();
    $case = DisciplinaryCase::factory()->create(['violation_report_id' => $report->id, 'branch_id' => $report->branch_id]);
    IntakeQuestion::factory()->create(['violation_report_id' => $report->id,
        'question' => 'Who was involved?', 'answer' => 'Two workers', 'aspect' => 'who']);
    IntakeQuestion::factory()->create(['violation_report_id' => $report->id,
        'question' => 'Who saw it?', 'answer' => null, 'aspect' => 'witnesses']);
    app(IntakeQuestionService::class)->carryToCase($report, $case);
    expect($case->intakeQuestions()->count())->toBe(2);
    $payload = app(PayloadBuilder::class)->build($case, DocumentType::DefenseLetter, collect());
    expect($payload['intake_answers'])->toHaveCount(1);
    expect($payload['intake_answers'][0]['answer'])->toBe('Two workers');
    expect($payload['intake_answers'][0]['question'])->toBe('Who was involved?');
});

test('report submission keeps partial answers and conversion carries the open point', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $branch = Branch::factory()->create(['company_id' => $company->id]);
    $employee = Employee::factory()->create(['company_id' => $company->id, 'branch_id' => $branch->id]);
    $reporter = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'reporter']);
    $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'hr_staff']);
    BranchUser::factory()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'user_id' => $reporter->id]);
    config()->set('services.anthropic.key', null);
    $check = app(IntakeQuestionService::class)->start($reporter, [
        'description' => 'A fight occurred', 'violation_type' => 'Fight', 'place' => 'Line 3', 'time' => '2026-10-09 03:00',
    ]);
    $report = app(ReportService::class)->submit([
        'branch_id' => $branch->id, 'employee_id' => $employee->id, 'place_freetext' => 'Line 3',
        'occurred_at' => '2026-10-09 03:00', 'violation_type' => 'Fight', 'description' => 'A fight occurred',
        'intake_check' => $check, 'intake_answers' => [0 => 'Two workers'],
    ], $reporter);
    expect($report->intakeQuestions()->count())->toBe(8);
    expect($report->intakeQuestions()->whereNotNull('answer')->count())->toBe(1);
    $case = app(ReportService::class)->convert($report, $hr);
    expect($case->intakeQuestions()->count())->toBe(8);
    expect($case->intakeQuestions()->whereNull('answer')->count())->toBe(7);
});

test('a pending queued check never blocks report submission', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $branch = Branch::factory()->create(['company_id' => $company->id]);
    $reporter = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'reporter']);
    BranchUser::factory()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'user_id' => $reporter->id]);
    config()->set('services.anthropic.key', 'test-key');
    Bus::fake([SuggestIntakeQuestions::class]);
    $this->actingAs($reporter);
    Livewire::test(SubmitReport::class)
        ->set('branchId', (string) $branch->id)->set('place', 'Line 3')
        ->set('occurredAt', '2026-10-09T03:00')->set('violationType', 'Fight')
        ->set('description', 'A fight occurred')->call('checkDescription')
        ->call('submit')->assertHasNoErrors()->call('confirmSend')->assertHasNoErrors();
    expect(ViolationReport::query()->where('reporter_user_id', $reporter->id)->count())->toBe(1);
});
