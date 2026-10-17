<?php

use App\Enums\UserRole;
use App\Filament\Admin\Widgets\AdminShortcuts;
use App\Mail\OperationsMessage;
use App\Models\Company;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

test('guest, workspace, and both panel dashboards show the shared mark', function (): void {
    $this->get('/')->assertOk()->assertSee('data-brand-mark', false);
    $this->get('/login')->assertOk()->assertSee('data-brand-mark', false);

    $company = Company::factory()->create();
    $admin = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::CompanyAdmin]);
    $this->actingAs($admin)->get('/home')->assertOk()->assertSee('data-brand-mark', false);
    $this->get('/admin/'.$company->id)->assertOk()->assertSee('data-brand-mark', false)
        ->assertSee(__('Quick links'));

    $platform = User::factory()->create(['company_id' => null, 'primary_role' => UserRole::PlatformAdmin]);
    $this->actingAs($platform)->get('/platform')->assertOk()->assertSee('data-brand-mark', false);
});

test('all error views carry the shared mark in both directions', function (): void {
    foreach (['en', 'ar'] as $locale) {
        app()->setLocale($locale);
        foreach ([403, 404, 419, 500, 503] as $code) {
            $html = view('errors.'.$code)->render();
            expect($html)->toContain('data-brand-mark', 'dir="'.($locale === 'ar' ? 'rtl' : 'ltr').'"');
        }
    }
});

test('server failure views do not read auth, session, or the database', function (): void {
    $auth = Auth::getFacadeRoot();
    $db = DB::getFacadeRoot();
    Auth::shouldReceive('guard')->never();
    DB::shouldReceive('connection')->never();

    try {
        foreach ([500, 503] as $code) {
            expect(view('errors.'.$code)->render())->toContain('data-brand-mark', '/login');
            expect(File::get(resource_path('views/errors/'.$code.'.blade.php')))->not->toContain('@auth', 'session(', 'auth(');
        }
        expect(File::get(resource_path('views/errors/layout.blade.php')))->not->toContain('@auth', 'session(', 'auth(');
    } finally {
        Auth::swap($auth);
        DB::swap($db);
    }
});

test('notification mail uses the inline mark and Arabic boilerplate', function (): void {
    foreach (['en', 'ar'] as $locale) {
        app()->setLocale($locale);
        $html = (string) (new MailMessage)
            ->line('A case needs your attention.')
            ->action('Open case', 'https://example.test/cases/1')
            ->render();

        expect($html)->toContain('data-brand-mark', 'background-color: #F2B33D',
            'dir="'.($locale === 'ar' ? 'rtl' : 'ltr').'"');
        if ($locale === 'ar') {
            expect($html)->toContain('مع التحية', 'جميع الحقوق محفوظة');
        }
    }
});

test('operations mail uses the same branded HTML layout', function (): void {
    $html = (new OperationsMessage('Status', 'The import is complete.'))->render();

    expect($html)->toContain('data-brand-mark', 'The import is complete.');
});

test('admin shortcuts use registered destinations and stay within admin permissions', function (): void {
    $company = Company::factory()->create();
    $admin = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::CompanyAdmin]);
    actingInAdminTenant($this, $admin, $company);

    expect(AdminShortcuts::canView())->toBeTrue();
    $links = app(AdminShortcuts::class)->links();
    expect($links)->not->toBeEmpty()
        ->and(collect($links)->pluck('url'))->toContain('/home');

    $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::HrStaff]);
    actingInAdminTenant($this, $hr, $company);
    expect(AdminShortcuts::canView())->toBeTrue();
    expect(collect(app(AdminShortcuts::class)->links())->pluck('label'))->not->toContain(__('Company settings'));
});

test('brand marks have one web component and one email rendering', function (): void {
    foreach (File::allFiles(resource_path('views')) as $file) {
        $path = str_replace('\\', '/', $file->getPathname());
        if (str_ends_with($path, '/components/ui/brand.blade.php')
            || str_ends_with($path, '/vendor/mail/html/header.blade.php')) {
            continue;
        }

        expect(preg_match('/>D<\/span>/', File::get($file->getPathname())))->toBe(0);
    }
});
