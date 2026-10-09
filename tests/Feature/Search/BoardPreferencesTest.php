<?php

use App\Livewire\Cases\Board;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Services\UserPreferences;
use App\Support\Tenancy\TenantContext;
use Livewire\Livewire;

test('board remembers branch and stage, clears them and never stores the search text', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $branch = Branch::factory()->create();
    $admin = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    Livewire::actingAs($admin)->test(Board::class)
        ->set('branch', (string) $branch->id)->set('stage', '3')->set('search', 'private term');
    expect($admin->fresh()->preferences['board_filters'])->toEqual(['branch' => (string) $branch->id, 'stage' => '3']);
    Livewire::actingAs($admin)->test(Board::class)
        ->assertSet('branch', (string) $branch->id)->assertSet('stage', '3')->assertSet('search', '')
        ->call('removeBranchFilter')->assertSet('branch', '')->assertSet('stage', '3');
    expect($admin->fresh()->preferences['board_filters'])->toEqual(['branch' => '', 'stage' => '3']);
    Livewire::actingAs($admin)->test(Board::class)
        ->call('clearFilters')->assertSet('branch', '')->assertSet('stage', '');
    expect($admin->fresh()->preferences)->toBeNull();
});

test('invalid saved filters are cleaned and an explicit empty URL branch wins', function (): void {
    $company = Company::factory()->create();
    $other = Company::factory()->create();
    app(TenantContext::class)->setCompany($other);
    $foreign = Branch::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $branch = Branch::factory()->create();
    $admin = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    expect(app(UserPreferences::class)->valid($admin, ['branch' => (string) $foreign->id, 'stage' => '6', 'search' => str_repeat('a', 101)]))
        ->toBe(['branch' => '', 'stage' => '', 'search' => '']);
    app(UserPreferences::class)->saveBoardFilters($admin, ['branch' => (string) $branch->id, 'stage' => '2']);
    $this->actingAs($admin)->get('/cases?branch=')->assertOk();
    expect($admin->fresh()->preferences['board_filters'])->toEqual(['branch' => '', 'stage' => '2']);
    $admin->preferences = ['board_filters' => ['branch' => (string) $foreign->id, 'stage' => '9']];
    $admin->save();
    Livewire::actingAs($admin)->test(Board::class)->assertSet('branch', '')->assertSet('stage', '');
    expect($admin->fresh()->preferences)->toBeNull();
});

test('saving UI preferences creates no audit entry', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $branch = Branch::factory()->create();
    $admin = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $before = AuditLog::query()->count();
    app(UserPreferences::class)->saveBoardFilters($admin, ['branch' => (string) $branch->id, 'stage' => '2']);
    expect(AuditLog::query()->count())->toBe($before);
});
