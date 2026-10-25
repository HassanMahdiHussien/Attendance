<?php

use App\Enums\UserRole;
use App\Livewire\Attendance\Assistant;
use App\Models\Attendance\ChatConversation;
use App\Models\Attendance\ChatTurn;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\Attendance\Ai\ChatModel;
use App\Services\Attendance\Ai\ChatProposals;
use App\Services\Attendance\Ai\ChatSettings;
use App\Services\Attendance\Ai\ChatTurns;
use App\Services\Attendance\Ai\FakeChatModel;
use App\Services\Employees\EmployeeHistory;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

function assistantBrowserFixture(TestCase $test, string $locale): array
{
    Queue::fake();
    $company = Company::factory()->create();
    completeCompanySetup($company);
    $actor = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::HrStaff,
        'locale' => $locale]);
    $employee = Employee::factory()->create(['company_id' => $company->id, 'employee_code' => '00073412',
        'schedule_start' => '08:00:00', 'schedule_end' => '17:00:00']);
    app(EmployeeHistory::class)->record($employee, ['schedule_start' => '08:00', 'schedule_end' => '17:00'],
        '2026-01-01', 'initial', $actor);
    $test->actingAs($actor);
    app(TenantContext::class)->setCompany($company);
    app(ChatSettings::class)->setEnabled($company, true, $actor, 'Browser review');
    app()->instance(ChatModel::class, new FakeChatModel([
        ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'Please review the proposal.']]],
    ]));
    Livewire::test(Assistant::class)->set('draft', 'Please review the end time')->call('send');
    $conversation = ChatConversation::query()->where('user_id', $actor->id)->firstOrFail();
    $turn = ChatTurn::query()->where('conversation_id', $conversation->id)->firstOrFail();
    $proposal = app(ChatProposals::class)->propose($company, $turn, $actor, [
        'effective_from' => today('Asia/Baghdad')->toDateString(),
        'employees' => [['employee_id' => $employee->id, 'changes' => ['schedule_end' => '18:00']]],
    ]);
    app(ChatTurns::class)->run($company->id, $turn->id);
    app(TenantContext::class)->clear();
    app('auth')->forgetGuards();

    return compact('actor', 'employee', 'conversation', 'proposal');
}

test('desktop assistant shows a fake-model answer and confirms a proposal', function (): void {
    $data = assistantBrowserFixture($this, 'en');
    visitBound('/login')->fill('email', $data['actor']->email)->fill('password', 'password')
        ->press('Log in')->assertSee('Attendance dashboard')
        ->navigate('/assistant?conversation='.$data['conversation']->id)
        ->assertSee('Please review the proposal.')->assertSee('Changes applied: none')
        ->assertSee('00073412')->assertSee('17:00')->assertSee('18:00')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        // Wait for the applied card's own note: "Applied" alone also matches the "Changes applied: none" footer.
        ->press('Confirm')->assertSee('To reverse this change')->assertNoJavaScriptErrors();
    expect($data['employee']->fresh()->schedule_end)->toBe('18:00:00');
});

test('Arabic assistant and composer fit a 390 pixel phone', function (): void {
    $data = assistantBrowserFixture($this, 'ar');
    visitBound('/login')->on()->mobile()->resize(390, 844)
        ->fill('email', $data['actor']->email)->fill('password', 'password')
        ->press('Log in')->assertSee('لوحة الحضور')
        ->navigate('/assistant?conversation='.$data['conversation']->id)
        ->assertSee('المساعد')->assertSee('00073412')
        ->assertScript('document.documentElement.dir', 'rtl')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->assertVisible('#assistant-draft')->assertNoJavaScriptErrors();
});
