<?php

use App\Ai\DraftPrompt;
use App\Documents\IraqiDate;
use App\Enums\CaseStatus;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\BranchResource;
use App\Filament\Admin\Resources\BranchResource\Pages\ListBranchs;
use App\Filament\Auth\Login;
use App\Livewire\Cases\ConfirmDocument;
use App\Livewire\Reports\SubmitReport;
use App\Models\Branch;
use App\Models\Company;
use App\Models\DisciplinaryCase;
use App\Models\Document;
use App\Models\Law;
use App\Models\User;
use App\Support\Format\OutcomeLabel;
use App\Support\Format\Ui;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\HandleRequests;

test('guest pages default to English and keep a session language choice', function (): void {
    $this->get('/')->assertOk()->assertSee('lang="en" dir="ltr"', false)->assertSee('Manage and document')->assertSee('العربية');

    $this->post('/locale', ['locale' => 'ar'])->assertRedirect();
    $this->get('/login')->assertOk()->assertSee('lang="ar" dir="rtl"', false)->assertSee('تسجيل الدخول إلى مساحة العمل')->assertSee('English');
});

test('a user language choice persists and overrides the guest session', function (): void {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::CompanyAdmin]);

    $this->actingAs($user)->withSession(['locale' => 'ar'])->get('/cases')
        ->assertOk()->assertSee('lang="en" dir="ltr"', false);
    $this->post('/locale', ['locale' => 'ar'])->assertRedirect();
    expect($user->fresh()->locale)->toBe('ar');
    $this->get('/cases')->assertOk()->assertSee('lang="ar" dir="rtl"', false)->assertSee('English');
});

test('locale switch validates supported languages', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/locale', ['locale' => 'ku'])->assertSessionHasErrors('locale');
    expect($user->fresh()->locale)->toBe('en');
});

test('UI numbers and dates follow the active locale in Baghdad time', function (): void {
    app()->setLocale('en');
    expect(Ui::number(123))->toBe('123');
    expect(Ui::date('2026-10-08 22:00:00 UTC'))->toBe('9 Oct 2026');

    app()->setLocale('ar');
    expect(Ui::number(123))->toBe('123');
    expect(Ui::date('2026-10-08 22:00:00 UTC'))->toBe('9 – تشرين الأول – 2026');
});

test('changing the UI locale leaves document wording and AI language instructions intact', function (): void {
    $date = CarbonImmutable::parse('2026-10-08');
    $prompt = new DraftPrompt;
    $render = fn (): array => [
        IraqiDate::format($date, 'ar'),
        OutcomeLabel::arabic('warning'),
        $prompt->messages(['facts' => 'test'], ['ar', 'en'], DocumentType::Recommendations),
    ];

    app()->setLocale('ar');
    $arabicInterface = $render();
    app()->setLocale('en');

    expect($render())->toBe($arabicInterface);
});

test('an Arabic user keeps the locale through a real Livewire update and translated action error', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $branch = Branch::factory()->create(['company_id' => $company->id]);
    $user = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::Reporter, 'locale' => 'ar']);
    $this->actingAs($user);
    app()->setLocale('ar');
    $component = Livewire::test(SubmitReport::class);

    app()->setLocale('en');
    $response = $this->withHeader('X-Livewire', 'true')->postJson(app(HandleRequests::class)->getUpdateUri(), [
        'components' => [[
            'snapshot' => json_encode($component->snapshot, JSON_THROW_ON_ERROR),
            'updates' => [
                'branchId' => (string) $branch->id,
                'occurredAt' => '2026-10-08 09:00',
                'violationType' => 'Late arrival',
                'description' => 'Arrived after the shift began.',
            ],
            'calls' => [['path' => '', 'method' => 'submit', 'params' => []]],
        ]],
    ]);

    $response->assertOk();
    expect($response->json('components.0.effects.html'))->toContain('اختر موقعاً أو اكتب المكان.');
});

test('an Arabic guest keeps the locale through a Filament login action', function (): void {
    $this->withSession(['locale' => 'ar']);
    $html = $this->get('/admin/login')->assertOk()->assertSee('البريد الإلكتروني')->getContent();
    $snapshot = livewireSnapshotForComponent($html, Login::class);

    app()->setLocale('en');
    $response = $this->withHeader('X-Livewire', 'true')->postJson(app(HandleRequests::class)->getUpdateUri(), [
        'components' => [[
            'snapshot' => $snapshot,
            'updates' => [],
            'calls' => [['path' => '', 'method' => 'authenticate', 'params' => []]],
        ]],
    ])->assertOk();
    expect(app()->getLocale())->toBe('ar')
        ->and($response->json('components.0.effects.html'))->toContain('البريد الإلكتروني');
});

test('both Filament login pages offer the language switch in both locales', function (): void {
    $this->get('/admin/login')->assertOk()->assertSee('Language')->assertSee('Arabic');
    $this->get('/platform/login')->assertOk()->assertSee('Language')->assertSee('Arabic');

    $this->post('/locale', ['locale' => 'ar'])->assertRedirect();
    $this->get('/admin/login')->assertOk()->assertSee('اللغة')->assertSee('العربية');
    $this->get('/platform/login')->assertOk()->assertSee('اللغة')->assertSee('العربية');
});

test('an Arabic admin sees and can run a translated Filament action', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $user = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::CompanyAdmin, 'locale' => 'ar']);
    $branch = Branch::factory()->create(['company_id' => $company->id]);
    actingInAdminTenant($this, $user, $company);
    app()->setLocale('ar');

    $this->get(BranchResource::getUrl('index', panel: 'admin', tenant: $company))
        ->assertOk()->assertSee('تعطيل');
    Livewire::test(ListBranchs::class)->callTableAction('deactivate', $branch);
    expect($branch->fresh()->is_active)->toBeFalse();
});

test('both panel user menus offer the locale actions', function (): void {
    $user = User::factory()->create(['primary_role' => UserRole::PlatformAdmin, 'locale' => 'ar']);
    $this->actingAs($user);
    app()->setLocale('ar');

    foreach (['admin', 'platform'] as $panelId) {
        $groups = Filament::getPanel($panelId)->getUserMenuItemGroups();
        $labels = collect($groups)->flatMap(fn (array $items): array => array_values($items))
            ->map(fn ($item): string => $item->getLabel())->all();
        expect($labels)->toContain('العربية', 'English');
    }
});

test('a real Livewire action displays a service exception in the user locale', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $user = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::CompanyAdmin, 'locale' => 'ar']);
    $law = Law::factory()->create();
    $case = DisciplinaryCase::factory()->create([
        'company_id' => $company->id,
        'opened_by_user_id' => $user->id,
        'law_version_id' => $law->id,
        'status' => CaseStatus::DefenseLetterReadyForReview,
    ]);
    $document = Document::factory()->create([
        'company_id' => $company->id,
        'case_id' => $case->id,
        'status' => DocumentStatus::ReadyForReview,
        'last_edited_by' => $user->id,
    ]);
    $this->actingAs($user);
    app()->setLocale('ar');
    $component = Livewire::test(ConfirmDocument::class, ['case' => (string) $case->id, 'document' => (string) $document->id]);

    app()->setLocale('en');
    $response = $this->withHeader('X-Livewire', 'true')->postJson(app(HandleRequests::class)->getUpdateUri(), [
        'components' => [[
            'snapshot' => json_encode($component->snapshot, JSON_THROW_ON_ERROR),
            'updates' => [],
            'calls' => [['path' => '', 'method' => 'markReviewed', 'params' => []]],
        ]],
    ]);

    $response->assertOk();
    expect($response->json('components.0.effects.html'))->toContain('يجب أن يجري المراجعة شخص غير آخر محرر.');
});
