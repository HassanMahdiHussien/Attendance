<?php

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Services\Branches\BranchMatcher;
use App\Services\Branches\BranchNames;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

test('branch normalisation folds case, spaces, Arabic variants, tatweel and vowels', function (): void {
    expect(BranchMatcher::normalise(' ErBiL  '))->toBe('erbil')
        ->and(BranchMatcher::normalise('أربيل'))->toBe('اربيل')
        ->and(BranchMatcher::normalise('إربيل'))->toBe('اربيل')
        ->and(BranchMatcher::normalise('آرْبـيل'))->toBe('اربيل')
        ->and(BranchMatcher::normalise('كربلة'))->toBe(BranchMatcher::normalise('كربله'))
        ->and(BranchMatcher::normalise('فتى'))->toBe(BranchMatcher::normalise('فتي'));
});

test('alternative names are unique against aliases and other display names within a company', function (): void {
    $company = Company::factory()->create();
    $actor = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::HrStaff]);
    actingInAdminTenant($this, $actor, $company);
    $erbil = Branch::factory()->create(['name' => 'أربيل']);
    $hilla = Branch::factory()->create(['name' => 'الحلة']);
    $names = app(BranchNames::class);
    $names->save($erbil, ['Erbil']);
    expect(app(BranchMatcher::class)->forCompany($company)->match(' ERBIL ')?->id)->toBe($erbil->id);

    foreach (['Erbil', 'إربيل'] as $clash) {
        expect(fn () => $names->save($hilla, [$clash]))->toThrow(ValidationException::class);
    }
    expect(fn () => $names->create($company, 'ERBIL', 'federal_iraq'))->toThrow(ValidationException::class);
    expect(fn () => $names->create($company, 'New Site', ''))->toThrow(ValidationException::class);

    $other = Company::factory()->create();
    $otherActor = User::factory()->create(['company_id' => $other->id, 'primary_role' => UserRole::HrStaff]);
    actingInAdminTenant($this, $otherActor, $other);
    $otherBranch = Branch::factory()->create(['name' => 'جنوب']);
    $names->save($otherBranch, ['Erbil']);
    expect(app(BranchMatcher::class)->forCompany($other)->match('Erbil')?->id)->toBe($otherBranch->id);
    expect(app(BranchMatcher::class)->forCompany($other)->match('الحلة'))->toBeNull();
    app(TenantContext::class)->clear();
});

test('link cannot attach an alias to a branch in another company', function (): void {
    $company = Company::factory()->create();
    $actor = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::HrStaff]);
    actingInAdminTenant($this, $actor, $company);
    $other = Company::factory()->create();
    app(TenantContext::class)->setCompany($other);
    $foreign = Branch::factory()->create(['name' => 'South']);
    app(TenantContext::class)->setCompany($company);

    expect(fn () => app(BranchNames::class)->link($company, 'Erbil', $foreign->id))->toThrow(ModelNotFoundException::class);
});

test('preexisting display-name ambiguity remains visible to importers', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    Branch::factory()->create(['name' => 'أربيل']);
    Branch::factory()->create(['name' => 'إربيل']);
    $matcher = app(BranchMatcher::class)->forCompany($company);
    expect($matcher->match('اربيل'))->toBeNull()->and($matcher->matches('اربيل'))->toHaveCount(2);
    app(TenantContext::class)->clear();
});
