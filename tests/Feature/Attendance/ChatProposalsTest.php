<?php

use App\Enums\UserRole;
use App\Jobs\Attendance\AssessDaysJob;
use App\Jobs\Attendance\RunChatTurnJob;
use App\Models\Attendance\ChatMessage;
use App\Models\Attendance\ChatProposal;
use App\Models\Attendance\ChatProposalEvent;
use App\Models\Attendance\ChatToolCall;
use App\Models\Attendance\DataIssue;
use App\Models\Attendance\Day;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeVersion;
use App\Models\User;
use App\Services\Attendance\Ai\ChatModel;
use App\Services\Attendance\Ai\ChatProposals;
use App\Services\Attendance\Ai\ChatSettings;
use App\Services\Attendance\Ai\ChatTools;
use App\Services\Attendance\Ai\ChatTurns;
use App\Services\Attendance\Ai\FakeChatModel;
use App\Services\Attendance\DataIssues;
use App\Services\Employees\EmployeeHistory;
use App\Support\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-25 12:00:00');
    Queue::fake();
    $this->company = Company::factory()->create();
    $this->otherCompany = Company::factory()->create();
    $this->otherEmployee = app(TenantContext::class)->runForCompany($this->otherCompany,
        fn () => Employee::factory()->create(['employee_code' => '90000123']));
    app(TenantContext::class)->setCompany($this->company);
    $this->hr = User::factory()->create(['company_id' => $this->company->id, 'primary_role' => UserRole::HrStaff]);
    $this->otherHr = User::factory()->create(['company_id' => $this->company->id, 'primary_role' => UserRole::HrStaff]);
    $this->employee = Employee::factory()->create(['employee_code' => '00000123', 'hire_date' => '2026-10-01', 'schedule_start' => '08:00:00',
        'schedule_end' => '17:00:00', 'rest_days' => [5, 6], 'work_pattern' => 'office']);
    app(EmployeeHistory::class)->record($this->employee, ['schedule_start' => '08:00',
        'schedule_end' => '17:00', 'rest_days' => [5, 6], 'work_pattern' => 'office'],
        '2026-10-01', 'initial', $this->hr);
    $this->actingAs($this->hr);
    $this->conversation = app(ChatTurns::class)->start($this->company, $this->hr);
    $this->turn = app(ChatTurns::class)->enqueue($this->conversation, $this->hr, 'Change the schedule');
});

afterEach(function (): void {
    Carbon::setTestNow();
    app(TenantContext::class)->clear();
});

function changeInput(array $employees, ?string $date = '2026-10-21'): array
{
    return ['employees' => $employees] + ($date === null ? [] : ['effective_from' => $date]);
}

function oneEndChange(Employee $employee, string $end): array
{
    return ['employee_id' => $employee->id, 'changes' => ['schedule_end' => $end]];
}

test('changes are disabled by default and the tool stores nothing', function (): void {
    $result = app(ChatTools::class)->execute('propose_employee_change',
        changeInput([oneEndChange($this->employee, '18:00')]), $this->company, $this->hr, $this->turn);
    expect($result['error'])->toBe('changes_disabled')
        ->and(ChatProposal::query()->count())->toBe(0);
});

test('an omitted effective date uses today in Baghdad', function (): void {
    app(ChatSettings::class)->setEnabled($this->company, true, $this->hr, 'Test approval');
    $proposal = app(ChatProposals::class)->propose($this->company, $this->turn, $this->hr,
        changeInput([oneEndChange($this->employee, '18:00')], null));
    expect($proposal->payload['effective_from'])->toBe('2026-10-25');
});

test('one end time changes only after confirmation and queues reassessment', function (): void {
    app(ChatSettings::class)->setEnabled($this->company, true, $this->hr, 'Test approval');
    Day::factory()->create(['employee_id' => $this->employee->id, 'work_date' => '2026-10-21', 'status' => 'present']);
    $result = app(ChatTools::class)->execute('propose_employee_change',
        changeInput([oneEndChange($this->employee, '18:00')]), $this->company, $this->hr, $this->turn);
    $proposal = ChatProposal::findOrFail($result['proposal_id']);
    expect($proposal->payload['employees'][0]['before'])->toBe(['schedule_end' => '17:00:00'])
        ->and($proposal->payload['employees'][0]['after'])->toBe(['schedule_end' => '18:00:00'])
        ->and($this->employee->fresh()->schedule_end)->toBe('17:00:00')
        ->and(ChatProposalEvent::query()->where('proposal_id', $proposal->id)->count())->toBe(1);

    app(ChatProposals::class)->confirm($proposal, $this->hr);
    expect($this->employee->fresh()->schedule_end)->toBe('18:00:00')
        ->and($this->employee->fresh()->schedule_start)->toBe('08:00:00')
        ->and($this->employee->fresh()->rest_days)->toBe([5, 6])
        ->and($this->employee->fresh()->work_pattern->value)->toBe('office')
        ->and(EmployeeVersion::query()->where('employee_id', $this->employee->id)->where('source', 'chat')->count())->toBe(1)
        ->and(ChatProposalEvent::query()->where('proposal_id', $proposal->id)->count())->toBe(2)
        ->and(AuditLog::query()->where('action', 'attendance.chat_proposal.applied')->count())->toBe(1);
    Queue::assertPushed(AssessDaysJob::class, fn (AssessDaysJob $job): bool => in_array($this->employee->id, $job->scope['employee_ids'] ?? [], true));
});

test('a proposal changing several fields confirms after the stored snapshot is read back', function (): void {
    app(ChatSettings::class)->setEnabled($this->company, true, $this->hr, 'Test approval');
    $proposal = app(ChatProposals::class)->propose($this->company, $this->turn, $this->hr, changeInput([[
        'employee_id' => $this->employee->id,
        'changes' => ['work_pattern' => 'shift', 'schedule_start' => '07:00', 'schedule_end' => '15:00', 'rest_days' => [6, 7]],
    ]]));

    $confirmed = app(ChatProposals::class)->confirm($proposal->fresh(), $this->hr);
    expect($confirmed->status)->toBe('applied')
        ->and($this->employee->fresh()->schedule_start)->toBe('07:00:00')
        ->and($this->employee->fresh()->schedule_end)->toBe('15:00:00')
        ->and($this->employee->fresh()->rest_days)->toBe([6, 7]);
});

test('a bulk proposal is atomic and invalid values reject the whole request', function (): void {
    app(ChatSettings::class)->setEnabled($this->company, true, $this->hr, 'Test approval');
    $employees = collect([$this->employee]);
    for ($index = 0; $index < 2; $index++) {
        $employee = Employee::factory()->create(['hire_date' => '2026-10-01', 'schedule_end' => '17:00:00']);
        app(EmployeeHistory::class)->record($employee, [], '2026-10-01', 'initial', $this->hr);
        $employees->push($employee);
    }
    $entries = $employees->map(fn (Employee $employee): array => oneEndChange($employee, '18:00'))->all();
    $entries[2]['changes'] = ['work_pattern' => 'invalid'];
    expect(fn () => app(ChatProposals::class)->propose($this->company, $this->turn, $this->hr,
        changeInput($entries)))->toThrow(ValidationException::class);
    expect(ChatProposal::query()->count())->toBe(0);

    $entries[2]['changes'] = ['schedule_end' => '18:00'];
    $proposal = app(ChatProposals::class)->propose($this->company, $this->turn, $this->hr, changeInput($entries));
    expect($proposal->payload['employees'])->toHaveCount(3);
    app(ChatProposals::class)->confirm($proposal, $this->hr);
    foreach ($employees as $employee) {
        expect($employee->fresh()->schedule_end)->toBe('18:00:00');
    }
    expect(EmployeeVersion::query()->where('source', 'chat')->count())->toBe(3);
});

test('field validation rejects ambiguous or unsafe changes before creating a proposal', function (): void {
    app(ChatSettings::class)->setEnabled($this->company, true, $this->hr, 'Test approval');
    foreach ([
        ['schedule_end' => '07:00'],
        ['rest_days' => [5, 5]],
        ['work_pattern' => 'unknown'],
        ['manager_employee_id' => $this->employee->id],
        ['manager_employee_id' => $this->otherEmployee->id],
        ['email' => 'not an email'],
    ] as $changes) {
        expect(fn () => app(ChatProposals::class)->propose($this->company, $this->turn, $this->hr,
            changeInput([['employee_id' => $this->employee->id, 'changes' => $changes]])))
            ->toThrow(ValidationException::class);
    }
    expect(ChatProposal::query()->count())->toBe(0);
});

test('a changed snapshot refuses the proposal without a chat employee write', function (): void {
    app(ChatSettings::class)->setEnabled($this->company, true, $this->hr, 'Test approval');
    $proposal = app(ChatProposals::class)->propose($this->company, $this->turn, $this->hr,
        changeInput([oneEndChange($this->employee, '18:00')]));
    app(EmployeeHistory::class)->record($this->employee, ['schedule_end' => '16:00'], '2026-10-21',
        'manual', $this->hr, 'HR correction');
    $decided = app(ChatProposals::class)->confirm($proposal, $this->hr);
    expect($decided->status)->toBe('refused')
        ->and($decided->result['reason'])->toBe('stale')
        ->and(EmployeeVersion::query()->where('employee_id', $this->employee->id)->where('source', 'chat')->count())->toBe(0)
        ->and($this->employee->fresh()->schedule_end)->toBe('16:00:00');
});

test('a manager invalidated before bulk confirmation refuses every change', function (): void {
    app(ChatSettings::class)->setEnabled($this->company, true, $this->hr, 'Test approval');
    $second = Employee::factory()->create(['hire_date' => '2026-10-01']);
    app(EmployeeHistory::class)->record($second, [], '2026-10-01', 'initial', $this->hr);
    $manager = Employee::factory()->create(['hire_date' => '2026-10-01']);
    $proposal = app(ChatProposals::class)->propose($this->company, $this->turn, $this->hr,
        changeInput([oneEndChange($this->employee, '18:00'),
            ['employee_id' => $second->id, 'changes' => ['manager_employee_id' => $manager->id]]]));
    $manager->forceFill(['is_active' => false])->save();
    $decided = app(ChatProposals::class)->confirm($proposal, $this->hr);
    expect($decided->status)->toBe('refused')
        ->and($decided->result['reason'])->toBe('invalid_values')
        ->and(EmployeeVersion::query()->where('source', 'chat')->count())->toBe(0)
        ->and($this->employee->fresh()->schedule_end)->toBe('17:00:00');
});

test('cancel, double confirmation and another owner cannot apply a proposal', function (): void {
    app(ChatSettings::class)->setEnabled($this->company, true, $this->hr, 'Test approval');
    $proposal = app(ChatProposals::class)->propose($this->company, $this->turn, $this->hr,
        changeInput([oneEndChange($this->employee, '18:00')]));
    expect(fn () => app(ChatProposals::class)->confirm($proposal, $this->otherHr))->toThrow(HttpException::class);
    app(ChatProposals::class)->cancel($proposal, $this->hr);
    expect(fn () => app(ChatProposals::class)->confirm($proposal->fresh(), $this->hr))->toThrow(HttpException::class);
    expect($this->employee->fresh()->schedule_end)->toBe('17:00:00');

    $second = app(ChatProposals::class)->propose($this->company, $this->turn, $this->hr,
        changeInput([oneEndChange($this->employee, '18:00')]));
    app(ChatProposals::class)->confirm($second, $this->hr);
    expect(fn () => app(ChatProposals::class)->confirm($second->fresh(), $this->hr))->toThrow(HttpException::class);
    expect(EmployeeVersion::query()->where('source', 'chat')->count())->toBe(1);
});

test('an employee-edit denial blocks confirmation without writing history', function (): void {
    app(ChatSettings::class)->setEnabled($this->company, true, $this->hr, 'Test approval');
    $proposal = app(ChatProposals::class)->propose($this->company, $this->turn, $this->hr,
        changeInput([oneEndChange($this->employee, '18:00')]));
    Gate::before(fn (User $user, string $ability): ?bool => $ability === 'update' ? false : null);
    expect(fn () => app(ChatProposals::class)->confirm($proposal, $this->hr))->toThrow(AuthorizationException::class);
    expect(EmployeeVersion::query()->where('source', 'chat')->count())->toBe(0)
        ->and($proposal->fresh()->status)->toBe('proposed');
});

test('a reversal is a new confirmed proposal restoring the old value', function (): void {
    app(ChatSettings::class)->setEnabled($this->company, true, $this->hr, 'Test approval');
    Day::factory()->create(['employee_id' => $this->employee->id, 'work_date' => '2026-10-21', 'status' => 'present']);
    $first = app(ChatProposals::class)->propose($this->company, $this->turn, $this->hr,
        changeInput([oneEndChange($this->employee, '18:00')]));
    app(ChatProposals::class)->confirm($first, $this->hr);
    $second = app(ChatProposals::class)->propose($this->company, $this->turn, $this->hr,
        changeInput([oneEndChange($this->employee, '17:00')]));
    app(ChatProposals::class)->confirm($second, $this->hr);
    expect($this->employee->fresh()->schedule_end)->toBe('17:00:00')
        ->and(app(EmployeeHistory::class)->asOf($this->employee, '2026-10-21')['schedule_end'])->toBe('17:00:00')
        ->and(EmployeeVersion::query()->where('source', 'chat')->count())->toBe(2);
    Queue::assertPushed(AssessDaysJob::class);
});

test('a chat pattern edit resolves the existing rule review issue', function (): void {
    app(ChatSettings::class)->setEnabled($this->company, true, $this->hr, 'Test approval');
    $issue = app(DataIssues::class)->open($this->company, 'pattern_review', 'employee',
        'employee:'.$this->employee->id);
    $proposal = app(ChatProposals::class)->propose($this->company, $this->turn, $this->hr,
        changeInput([['employee_id' => $this->employee->id, 'changes' => ['work_pattern' => 'shift']]]));
    app(ChatProposals::class)->confirm($proposal, $this->hr);
    expect(DataIssue::findOrFail($issue->id)->status)->toBe('resolved');
});

test('employee lookup is exact by code and asks on similar names before proposing', function (): void {
    $similar = Employee::factory()->create(['name' => $this->employee->name]);
    $exact = app(ChatTools::class)->execute('find_employees', ['query' => '00000123'], $this->company, $this->hr);
    $names = app(ChatTools::class)->execute('find_employees', ['query' => $this->employee->name], $this->company, $this->hr);
    expect($exact['exact_code'])->toBeTrue()
        ->and($exact['matches'][0]['id'])->toBe($this->employee->id)
        ->and(collect($names['matches'])->pluck('id')->all())->toContain($similar->id, $this->employee->id);
    app()->instance(ChatModel::class, new FakeChatModel([
        ['model' => 'fake', 'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => 'Which employee do you mean?']],
            'input_tokens' => 1, 'output_tokens' => 1],
    ]));
    (new RunChatTurnJob($this->company->id, $this->turn->id))->handle(app(ChatTurns::class));
    expect(ChatProposal::query()->count())->toBe(0)
        ->and($this->turn->messages()->where('status', 'final')->firstOrFail()->content['text'])
        ->toContain('Which employee');
});

test('a retried tool call reuses the proposal and the footer reports it as waiting', function (): void {
    app(ChatSettings::class)->setEnabled($this->company, true, $this->hr, 'Test approval');
    $input = changeInput([oneEndChange($this->employee, '18:00')]);
    $result = app(ChatTools::class)->execute('propose_employee_change', $input, $this->company, $this->hr, $this->turn);
    ChatToolCall::factory()->create(['turn_id' => $this->turn->id, 'name' => 'propose_employee_change',
        'tool_use_id' => 'proposal-tool', 'input' => $input, 'result' => $result]);
    ChatMessage::query()->create(['company_id' => $this->company->id, 'conversation_id' => $this->conversation->id,
        'turn_id' => $this->turn->id, 'role' => 'assistant', 'status' => 'tool_use',
        'content' => ['blocks' => [['type' => 'tool_use', 'id' => 'proposal-tool',
            'name' => 'propose_employee_change', 'input' => $input]]], 'created_at' => now()]);
    app()->instance(ChatModel::class, new FakeChatModel([
        ['model' => 'fake', 'stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'I changed it.']],
            'input_tokens' => 1, 'output_tokens' => 1],
    ]));
    (new RunChatTurnJob($this->company->id, $this->turn->id))->handle(app(ChatTurns::class));
    $final = $this->turn->messages()->where('status', 'final')->firstOrFail()->content;
    expect(ChatProposal::query()->count())->toBe(1)
        ->and(ChatToolCall::query()->where('tool_use_id', 'proposal-tool')->count())->toBe(1)
        ->and($final['verified']['changes_applied'])->toBe(0)
        ->and($final['verified']['proposal_ids'])->toBe([$result['proposal_id']])
        ->and($final['verified']['footer'])->toContain('waiting for your Confirm');
});

test('proposal triggers block payload edits and illegal transitions', function (): void {
    app(ChatSettings::class)->setEnabled($this->company, true, $this->hr, 'Test approval');
    $proposal = app(ChatProposals::class)->propose($this->company, $this->turn, $this->hr,
        changeInput([oneEndChange($this->employee, '18:00')]));
    expect(fn () => DB::table('attendance_chat_proposals')->where('id', $proposal->id)
        ->update(['payload' => json_encode(['employees' => []], JSON_THROW_ON_ERROR)]))->toThrow(QueryException::class);
    expect(fn () => DB::table('attendance_chat_proposals')->where('id', $proposal->id)
        ->update(['status' => 'queued']))->toThrow(QueryException::class);
    app(ChatProposals::class)->cancel($proposal, $this->hr);
    expect(fn () => DB::table('attendance_chat_proposals')->where('id', $proposal->id)
        ->update(['status' => 'applied']))->toThrow(QueryException::class);
});

test('other-company employees and proposal rows stay isolated', function (): void {
    app(ChatSettings::class)->setEnabled($this->company, true, $this->hr, 'Test approval');
    expect(fn () => app(ChatProposals::class)->propose($this->company, $this->turn, $this->hr,
        changeInput([oneEndChange($this->otherEmployee, '18:00')])))->toThrow(ValidationException::class);
    $proposal = app(ChatProposals::class)->propose($this->company, $this->turn, $this->hr,
        changeInput([oneEndChange($this->employee, '18:00')]));
    expect(app(ChatTools::class)->execute('proposal_status', ['proposal_id' => $proposal->id],
        $this->company, $this->otherHr))->toBe(['error' => 'permission_denied']);
    app(TenantContext::class)->setCompany($this->otherCompany);
    expect(ChatProposal::find($proposal->id))->toBeNull();
    app(TenantContext::class)->setCompany($this->company);
    expect(fn () => app(ChatProposals::class)->status($proposal, $this->otherHr))->toThrow(HttpException::class);
});
