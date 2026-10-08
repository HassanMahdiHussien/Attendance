<?php

use App\Enums\CaseStatus;
use App\Enums\ReportStatus;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\CommitteeMandate;
use App\Models\CommitteePoolMember;
use App\Models\Company;
use App\Models\DisciplinaryCase;
use App\Models\Document;
use App\Models\EmailDraft;
use App\Models\User;
use App\Models\ViolationReport;
use App\Support\Access\Nav;
use App\Support\Format\Tone;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;

test('shared controls keep Livewire attributes on their actual elements', function (): void {
    $html = Blade::render('<x-ui.input wire:model.live="reference" type="text" /><x-ui.button wire:click="issue">Issue</x-ui.button><x-ui.select wire:model="stage"><option>One</option></x-ui.select><x-ui.file wire:model="scan" accept=".pdf" />');

    expect($html)->toContain('<input', 'wire:model.live="reference"', 'wire:click="issue"', '<button', 'wire:model="stage"', '<select', 'wire:model="scan"', 'accept=".pdf"', 'type="file"');
});

test('badge tones, responsive table labels, and nested field errors render', function (): void {
    expect(Blade::render('<x-ui.field label="Reference" error="reference"><x-ui.input wire:model="reference" /></x-ui.field>'))
        ->toContain('wire:model="reference"');

    $errors = new ViewErrorBag;
    $errors->put('default', new MessageBag(['presence.7' => 'Choose a state']));
    // Pages receive the error bag as shared view data; components read it from there.
    view()->share('errors', $errors);
    $html = Blade::render('<x-ui.badge tone="red">Urgent</x-ui.badge><x-ui.table><tbody><tr><x-ui.td label="Case">42</x-ui.td></tr></tbody></x-ui.table><x-ui.field label="Presence" error="presence.7"><x-ui.select wire:model="presence.7"></x-ui.select></x-ui.field>');

    expect($html)->toContain('ui-badge-red', 'data-label="Case"', 'wire:model="presence.7"', 'Choose a state');
    expect(Tone::deadline(1))->toBe('red')
        ->and(Tone::approval('approved'))->toBe('green');
});

test('workspace navigation counts only the current company inbox and marks the current page', function (): void {
    $company = Company::factory()->create();
    $other = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $branch = Branch::factory()->create(['company_id' => $company->id]);
    $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::CompanyAdmin]);
    ViolationReport::factory()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'status' => ReportStatus::Received]);
    EmailDraft::factory()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'status' => 'received']);
    app(TenantContext::class)->setCompany($other);
    $foreignBranch = Branch::factory()->create(['company_id' => $other->id]);
    ViolationReport::factory()->create(['company_id' => $other->id, 'branch_id' => $foreignBranch->id, 'status' => ReportStatus::Received]);
    app(TenantContext::class)->setCompany($company);

    $items = Nav::items($hr);
    $inbox = collect($items)->firstWhere('url', '/reports/inbox');
    expect($inbox['count'])->toBe(2)
        ->and(collect($items)->pluck('url'))->toContain('/cases');
    $reporter = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::Reporter]);
    expect(collect(Nav::items($reporter))->pluck('url'))->toContain('/reports')->not->toContain('/reports/inbox');
    $this->actingAs($hr)->get('/cases')->assertOk()->assertSee('aria-current="page"', false);
});

test('Filament panels use the translated brand name', function (): void {
    app()->setLocale('en');
    expect(Filament::getPanel('admin')->getBrandName())->toBe('Displanery');

    app()->setLocale('ar');
    expect(Filament::getPanel('admin')->getBrandName())->toBe(__('Displanery'))
        ->and(Filament::getPanel('platform')->getBrandName())->toBe(__('Displanery'));
});

test('pending approvals use a dot in the eligible committee member navigation', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $user = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::CommitteeMember]);
    $case = DisciplinaryCase::factory()->create(['defense_letter_issued_at' => now()]);
    DB::statement('SET @displanery_workflow_transition = 1');
    try {
        DB::table('cases')->where('id', $case->id)->update(['status' => CaseStatus::RecommendationsAwaitingApprovals->value]);
    } finally {
        DB::statement('SET @displanery_workflow_transition = NULL');
    }
    $mandate = CommitteeMandate::factory()->create(['effective_from' => now()->subDay()]);
    CommitteePoolMember::factory()->create(['mandate_id' => $mandate->id, 'role_type' => 'legal', 'user_id' => $user->id]);
    Document::factory()->create(['case_id' => $case->id, 'document_type' => 'recommendations', 'status' => 'ready_for_review', 'is_locked' => false]);

    $item = collect(Nav::items($user))->firstWhere('url', '/committee');
    expect($item['dot'])->toBeTrue()->and($item['count'])->toBeNull();
});
