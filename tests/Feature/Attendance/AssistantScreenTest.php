<?php

use App\Enums\UserRole;
use App\Filament\Admin\Pages\CompanySettings;
use App\Livewire\Attendance\Assistant;
use App\Models\Attendance\AttendanceExport;
use App\Models\Attendance\AttendanceExportEvent;
use App\Models\Attendance\ChatConversation;
use App\Models\Attendance\ChatToolCall;
use App\Models\Attendance\ChatTurn;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\Attendance\Ai\ChatModel;
use App\Services\Attendance\Ai\ChatProposals;
use App\Services\Attendance\Ai\ChatSettings;
use App\Services\Attendance\Ai\ChatTurns;
use App\Services\Attendance\Ai\ChatUnavailable;
use App\Services\Attendance\Ai\FakeChatModel;
use App\Services\Employees\EmployeeHistory;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    Queue::fake();
    $this->company = Company::factory()->create();
    $this->foreignCompany = Company::factory()->create();
    $this->foreign = app(TenantContext::class)->runForCompany($this->foreignCompany, function (): array {
        $hr = User::factory()->create(['company_id' => $this->foreignCompany->id, 'primary_role' => UserRole::HrStaff]);
        $employee = Employee::factory()->create(['company_id' => $this->foreignCompany->id]);

        return ['hr' => $hr, 'employee' => $employee,
            'conversation' => ChatConversation::factory()->create(['company_id' => $this->foreignCompany->id, 'user_id' => $hr->id])];
    });
    app(TenantContext::class)->setCompany($this->company);
    $this->hr = User::factory()->create(['company_id' => $this->company->id, 'primary_role' => UserRole::HrStaff]);
    $this->otherHr = User::factory()->create(['company_id' => $this->company->id, 'primary_role' => UserRole::HrStaff]);
    $this->employee = Employee::factory()->create(['company_id' => $this->company->id, 'employee_code' => '00012345',
        'schedule_start' => '08:00:00', 'schedule_end' => '17:00:00', 'work_pattern' => 'office', 'rest_days' => [5, 6]]);
    app(EmployeeHistory::class)->record($this->employee, ['schedule_start' => '08:00', 'schedule_end' => '17:00',
        'rest_days' => [5, 6], 'work_pattern' => 'office'], '2026-10-01', 'initial', $this->hr);
    $this->actingAs($this->hr);
    app()->instance(ChatModel::class, new FakeChatModel([
        ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'No change was made.']]],
    ]));
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

test('send queues a turn and polling stops after a verified plain-text reply', function (): void {
    $this->get('/assistant')->assertOk()->assertSee('Assistant');
    $screen = Livewire::test(Assistant::class)
        ->set('draft', 'How many days?')->call('send')
        ->assertSee('How many days?')->assertSeeHtml('wire:poll.3s="poll"');
    $turn = ChatTurn::query()->firstOrFail();
    expect($turn->status)->toBe('queued');
    app(ChatTurns::class)->run($this->company->id, $turn->id);
    $screen->call('poll')->assertSee('No change was made.')
        ->assertSee('Changes applied: none')->assertDontSeeHtml('wire:poll.3s="poll"');
});

test('composer enforces the server-side character limit', function (): void {
    Livewire::test(Assistant::class)->set('draft', str_repeat('x', 4001))
        ->call('send')->assertHasErrors(['draft' => 'max']);
    expect(ChatTurn::query()->count())->toBe(0);
});

test('model HTML and an invented report link remain escaped text', function (): void {
    app()->instance(ChatModel::class, new FakeChatModel([
        ['stop_reason' => 'end_turn', 'content' => [['type' => 'text',
            'text' => '<script>alert(1)</script> Download report https://example.test/fake']]],
    ]));
    $screen = Livewire::test(Assistant::class)->set('draft', 'A report')->call('send');
    $turn = ChatTurn::query()->firstOrFail();
    app(ChatTurns::class)->run($this->company->id, $turn->id);
    $screen->call('poll')->assertSee('<script>alert(1)</script>')
        ->assertDontSeeHtml('<script>alert(1)</script>')->assertDontSeeHtml('href="https://example.test/fake"');
});

test('a terminal model outcome is shown as a notice', function (): void {
    app()->instance(ChatModel::class, new FakeChatModel([new ChatUnavailable('no_key')]));
    $screen = Livewire::test(Assistant::class)->set('draft', 'Question')->call('send');
    $turn = ChatTurn::query()->firstOrFail();
    app(ChatTurns::class)->run($this->company->id, $turn->id);
    $screen->call('poll')->assertSee('AI reading is unavailable.')
        ->assertDontSeeHtml('wire:poll.3s="poll"');
});

test('only the owner can open or poll a conversation or act on its proposal', function (): void {
    app(ChatSettings::class)->setEnabled($this->company, true, $this->hr, 'Test change');
    $conversation = app(ChatTurns::class)->start($this->company, $this->hr);
    $turn = app(ChatTurns::class)->enqueue($conversation, $this->hr, 'Change end time');
    $proposal = app(ChatProposals::class)->propose($this->company, $turn, $this->hr,
        ['effective_from' => '2026-10-25', 'employees' => [
            ['employee_id' => $this->employee->id, 'changes' => ['schedule_end' => '18:00']],
        ]]);
    Livewire::withQueryParams(['conversation' => $conversation->id])->test(Assistant::class)->assertOk();
    $this->actingAs($this->otherHr);
    // Query parameters persist between Livewire::test calls, so reset them for the action checks.
    Livewire::withQueryParams([])->test(Assistant::class)->call('open', $conversation->id)->assertNotFound();
    Livewire::withQueryParams([])->test(Assistant::class)->call('confirm', $proposal->id)->assertNotFound();
    Livewire::withQueryParams([])->test(Assistant::class)->call('cancel', $proposal->id)->assertNotFound();
    Livewire::withQueryParams(['conversation' => $conversation->id])->test(Assistant::class)->assertNotFound();
    expect($proposal->fresh()->status)->toBe('proposed');
});

test('a proposal card formats fields and confirms, cancels, or displays stale refusal', function (): void {
    app(ChatSettings::class)->setEnabled($this->company, true, $this->hr, 'Test change');
    $conversation = app(ChatTurns::class)->start($this->company, $this->hr);
    $turn = app(ChatTurns::class)->enqueue($conversation, $this->hr, 'Change end time');
    $proposal = app(ChatProposals::class)->propose($this->company, $turn, $this->hr,
        ['effective_from' => '2026-10-25', 'employees' => [
            ['employee_id' => $this->employee->id, 'changes' => ['schedule_end' => '18:00']],
        ]]);
    $screen = Livewire::withQueryParams(['conversation' => $conversation->id])->test(Assistant::class)
        ->assertSee('00012345')->assertSee('17:00')->assertSee('18:00')->assertSee('Confirm');
    $screen->call('confirm', $proposal->id)->assertSee('Applied');
    expect($this->employee->fresh()->schedule_end)->toBe('18:00:00');

    $second = app(ChatProposals::class)->propose($this->company, $turn, $this->hr,
        ['effective_from' => '2026-10-25', 'employees' => [
            ['employee_id' => $this->employee->id, 'changes' => ['schedule_end' => '19:00']],
        ]]);
    $screen->call('cancel', $second->id)->assertSee('Cancelled');
    $third = app(ChatProposals::class)->propose($this->company, $turn, $this->hr,
        ['effective_from' => '2026-10-25', 'employees' => [
            ['employee_id' => $this->employee->id, 'changes' => ['schedule_end' => '20:00']],
        ]]);
    app(EmployeeHistory::class)->record($this->employee, ['schedule_end' => '19:00'],
        '2026-10-25', 'manual', $this->hr, 'Changed by HR');
    $screen->call('confirm', $third->id)->assertSee('Employee values changed since this proposal');
    expect($third->fresh()->status)->toBe('refused');
});

test('the confirmation control is hidden when employee editing is denied', function (): void {
    app(ChatSettings::class)->setEnabled($this->company, true, $this->hr, 'Test change');
    $conversation = app(ChatTurns::class)->start($this->company, $this->hr);
    $turn = app(ChatTurns::class)->enqueue($conversation, $this->hr, 'Change end time');
    app(ChatProposals::class)->propose($this->company, $turn, $this->hr,
        ['effective_from' => '2026-10-25', 'employees' => [
            ['employee_id' => $this->employee->id, 'changes' => ['schedule_end' => '18:00']],
        ]]);
    Gate::before(fn ($user, string $ability): ?bool => $ability === 'update' ? false : null);
    Livewire::withQueryParams(['conversation' => $conversation->id])->test(Assistant::class)
        ->assertSee('Waiting for confirmation')->assertDontSeeHtml('wire:click="confirm(');
});

test('proposal card names weekdays, work patterns, and managers for people', function (): void {
    app(ChatSettings::class)->setEnabled($this->company, true, $this->hr, 'Test change');
    $manager = Employee::factory()->create(['company_id' => $this->company->id, 'name' => 'Sample Manager']);
    $conversation = app(ChatTurns::class)->start($this->company, $this->hr);
    $turn = app(ChatTurns::class)->enqueue($conversation, $this->hr, 'Change the schedule');
    app(ChatProposals::class)->propose($this->company, $turn, $this->hr,
        ['effective_from' => '2026-10-25', 'employees' => [
            ['employee_id' => $this->employee->id, 'changes' => [
                'rest_days' => [5], 'work_pattern' => 'shift', 'manager_employee_id' => $manager->id,
            ]],
        ]]);
    Livewire::withQueryParams(['conversation' => $conversation->id])->test(Assistant::class)
        ->assertSee('Friday')->assertSee('Saturday')->assertSee('Shift')->assertSee('Sample Manager');
});

test('company settings change chat permission through the audited setting service', function (): void {
    actingInAdminTenant($this, $this->hr, $this->company);
    Livewire::test(CompanySettings::class)
        ->set('chatChangesEnabled', true)->set('chatReason', 'Reviewed for HR use')
        ->call('saveChatSettings')->assertHasNoErrors();
    expect(app(ChatSettings::class)->enabled($this->company))->toBeTrue();
});

test('unconfigured assistant keeps history readable and disables sending', function (): void {
    $conversation = app(ChatTurns::class)->start($this->company, $this->hr);
    app()->forgetInstance(ChatModel::class);
    config()->set('services.anthropic.key', null);
    Livewire::withQueryParams(['conversation' => $conversation->id])->test(Assistant::class)
        ->assertSee('AI is not configured')->assertSeeHtml('disabled');
    Livewire::test(Assistant::class)->set('draft', 'Question')->call('send')->assertForbidden();
});

test('report link requires a completed file requested by this user', function (): void {
    Storage::fake('local');
    $conversation = app(ChatTurns::class)->start($this->company, $this->hr);
    $turn = app(ChatTurns::class)->enqueue($conversation, $this->hr, 'Report');
    $export = AttendanceExport::factory()->create(['company_id' => $this->company->id,
        'requested_by' => $this->hr->id, 'file_path' => 'reports/chat-test.xlsx']);
    ChatToolCall::factory()->create(['company_id' => $this->company->id, 'turn_id' => $turn->id,
        'name' => 'create_report', 'result' => ['export_id' => $export->id, 'status' => 'queued']]);
    $screen = Livewire::withQueryParams(['conversation' => $conversation->id])->test(Assistant::class)
        ->assertDontSee('Download report');
    AttendanceExportEvent::factory()->create(['company_id' => $this->company->id,
        'export_id' => $export->id, 'sequence' => 2, 'state' => 'completed']);
    Storage::disk('local')->put('reports/chat-test.xlsx', 'fake');
    $screen->call('poll')->assertSee('Download report')
        ->assertSee(route('attendance.exports.file', [$this->company->id, $export->id]));
    $export->forceFill(['requested_by' => $this->otherHr->id])->save();
    $screen->call('poll')->assertDontSee('Download report');
});

test('foreign-company records and platform company choice stay isolated', function (): void {
    $this->get('/assistant?conversation='.$this->foreign['conversation']->id)->assertNotFound();
    Livewire::test(Assistant::class)->call('open', $this->foreign['conversation']->id)->assertNotFound();
    $platform = User::factory()->create(['company_id' => null, 'primary_role' => UserRole::PlatformAdmin]);
    $this->actingAs($platform);
    Livewire::test(Assistant::class)->assertSee('Choose a company')->call('chooseCompany', $this->company->id)
        ->assertSee($this->company->name);
});
