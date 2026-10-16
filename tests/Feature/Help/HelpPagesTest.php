<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\HrPartner;
use App\Models\LawFirmPartner;
use App\Models\User;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\PlatformPanelProvider;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

test('guests cannot open help pages or screenshots', function (): void {
    $this->get('/help')->assertRedirect('/login');
    $this->get('/help/getting-started/welcome')->assertRedirect('/login');
    $this->get('/help/shots/en/home')->assertRedirect('/login');
});

test('all roles can read both language pages and use query string search', function (): void {
    foreach (UserRole::cases() as $role) {
        foreach (['en', 'ar'] as $locale) {
            $attributes = ['primary_role' => $role, 'locale' => $locale];
            if (in_array($role, [UserRole::CompanyAdmin, UserRole::HrStaff, UserRole::DeptManager, UserRole::Reporter, UserRole::CommitteeMember], true)) {
                $attributes['company_id'] = Company::factory()->create()->id;
            } elseif ($role === UserRole::ExternalLegalReviewer) {
                $attributes['law_firm_partner_id'] = LawFirmPartner::factory()->create()->id;
            } elseif ($role === UserRole::HrPartnerStaff) {
                $attributes['hr_partner_id'] = HrPartner::factory()->create()->id;
            }
            $user = User::factory()->create($attributes);
            $this->actingAs($user)->get('/help')->assertOk();
            $this->actingAs($user)->get('/help/getting-started/welcome')->assertOk();
            $this->actingAs($user)->get('/help?q='.rawurlencode($locale === 'ar' ? 'الاجراءات' : 'disciplinary'))->assertOk();
        }
    }
});

test('image route accepts only manifest names and supported locales', function (): void {
    $this->actingAs(User::factory()->create());
    $this->get('/help/shots/en/unknown')->assertNotFound();
    $this->get('/help/shots/xx/home')->assertNotFound();
    $this->get('/help/unknown/missing')->assertNotFound();
    $file = resource_path('help/shots/en/home.webp');
    $created = ! File::exists($file);
    if ($created) {
        File::ensureDirectoryExists(dirname($file));
        File::put($file, 'webp-test');
    }
    try {
        $this->get('/help/shots/en/home')->assertOk()->assertHeader('Content-Type', 'image/webp');
    } finally {
        if ($created) {
            File::delete($file);
        }
    }
});

test('workspace menus and both panels expose help', function (): void {
    $response = $this->actingAs(User::factory()->create(['company_id' => Company::factory()->create()->id, 'primary_role' => UserRole::CompanyAdmin]))
        ->get('/help')->assertOk()->assertSee('workspace-account-menu')->assertSee('workspace-more');
    expect(substr_count($response->getContent(), 'href="'.route('help.index').'"'))->toBeGreaterThanOrEqual(2);
    foreach ([AdminPanelProvider::class, PlatformPanelProvider::class] as $provider) {
        $panel = (new $provider(app()))->panel(Panel::make());
        Filament::setCurrentPanel($panel);
        expect($panel->getUserMenuItems())->toHaveKey('help');
        expect($panel->getUserMenuItems()['help']->getUrl())->toBe('/help');
    }
});

test('screenshot command refuses non-local environments', function (): void {
    expect(Artisan::call('help:screenshots'))->toBe(1);
});

test('screenshot command rejects unknown names and missing demo company', function (): void {
    app()->detectEnvironment(fn (): string => 'local');
    expect(Artisan::call('help:screenshots', ['--only' => 'unknown']))->toBe(1);
    expect(Artisan::call('help:screenshots', ['--only' => 'home']))->toBe(1);
});
