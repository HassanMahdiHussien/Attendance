<?php

use App\Enums\CaseStatus;
use App\Models\Company;
use App\Models\DisciplinaryCase;
use App\Models\Law;
use App\Models\User;
use App\Services\Cases\NextAction;
use App\Services\Home\MyWork;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\File;

test('each actionable case step points to an on-page anchor or a screen', function (): void {
    $viewer = User::factory()->make(['primary_role' => 'company_admin']);
    $source = File::get(resource_path('views/livewire/cases/show-case.blade.php'));
    $service = app(NextAction::class);
    foreach (CaseStatus::cases() as $status) {
        $case = new DisciplinaryCase(['status' => $status]);
        $case->id = 17;
        $case->setRelation('documents', collect());
        $case->setRelation('signatures', collect());
        $action = $service->for($case, $viewer);
        if ($action === null || $action->actor === 'waiting') {
            expect($action?->target)->toBeNull();

            continue;
        }
        expect($action->target)->not->toBeNull($status->value);
        if (str_starts_with($action->target, '#')) {
            expect($source)->toContain('id="'.substr($action->target, 1).'"');
        } else {
            expect($action->target)->toStartWith('/cases/');
        }
    }
});

test('the case card shares its label and actor with My work and hides after archive', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $admin = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    // Open and archived cases must carry their law version (and archived ones an outcome), as the model requires.
    $law = Law::factory()->create();
    $case = DisciplinaryCase::factory()->create(['status' => CaseStatus::Open, 'opened_by_user_id' => $admin->id,
        'law_version_id' => $law->id]);
    $action = app(NextAction::class)->for($case, $admin);
    $work = app(MyWork::class)->for($admin);
    $row = $work['actions']->first(fn ($item) => ($item['case']?->id ?? null) === $case->id);
    expect($row['action']->label)->toBe($action->label)
        ->and($row['action']->actor)->toBe($action->actor);
    $this->actingAs($admin)->get('/cases/'.$case->id)->assertOk()
        ->assertSee(__('messages.search.next_step'))->assertSee($action->label)->assertSee('id="next-actions"', false);
    $archived = DisciplinaryCase::factory()->create(['status' => CaseStatus::ArchivedFinal, 'opened_by_user_id' => $admin->id,
        'law_version_id' => $law->id, 'outcome' => 'warning', 'closed_at' => now()]);
    $this->get('/cases/'.$archived->id)->assertOk()->assertDontSee(__('messages.search.next_step'));
});
