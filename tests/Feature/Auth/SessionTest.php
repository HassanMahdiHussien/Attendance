<?php

use App\Enums\UserRole;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\CompanyStatusService;
use App\Services\UserAccountService;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Filament\Http\Middleware\IdentifyTenant;
use Filament\Pages\Dashboard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

class AuthPanelProbe extends Dashboard
{
    public ?int $observedContext = null;

    public ?int $employeeCount = null;

    public function checkContext(): void
    {
        $this->observedContext = app(TenantContext::class)->companyId();
        $this->employeeCount = Employee::count();
    }
}

function panelSnapshot(TestCase $test, string $url): string
{
    $html = $test->get($url)->assertOk()->getContent();
    preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches);
    foreach ($matches[1] as $encoded) {
        $snapshot = html_entity_decode($encoded, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        if (array_key_exists('observedContext', json_decode($snapshot, true)['data'] ?? [])) {
            return $snapshot;
        }
    }

    throw new RuntimeException('The panel probe snapshot was not rendered.');
}

function postPanelUpdate(TestCase $test, string $snapshot, string $method = 'checkContext'): TestResponse
{
    return $test->postJson(Livewire::getUpdateUri(), [
        'components' => [[
            'snapshot' => $snapshot,
            'updates' => [],
            'calls' => [['path' => '', 'method' => $method, 'params' => []]],
        ]],
    ], ['X-Livewire' => 'true']);
}

beforeEach(function (): void {
    Route::middleware('web')->get('/session-probe', fn () => response('private'));
    $admin = Filament::getPanel('admin');
    Route::middleware([...$admin->getMiddleware(), ...$admin->getAuthMiddleware(), ...$admin->getTenantMiddleware()])
        ->get('/admin/{tenant}/auth-panel-probe', AuthPanelProbe::class)
        ->name($admin->generateRouteName('pages.auth-panel-probe'));
    $platform = Filament::getPanel('platform');
    Route::middleware([...$platform->getMiddleware(), ...$platform->getAuthMiddleware()])
        ->get('/platform/auth-panel-probe', AuthPanelProbe::class)
        ->name($platform->generateRouteName('pages.auth-panel-probe'));
});

dataset('session request paths', [
    'platform GET' => ['/platform', '/platform/login'],
    'admin GET' => ['/admin/login', '/admin/login'],
    'ordinary web GET' => ['/session-probe', '/admin/login'],
]);

test('inactive user is logged out before every page response', function (string $path, string $login): void {
    $user = User::factory()->create(['is_active' => false]);
    $this->actingAs($user)->get($path)->assertRedirect($login);
    $this->assertGuest();
})->with('session request paths');

test('inactive company user is logged out before every page response', function (string $path, string $login): void {
    $company = Company::factory()->create(['is_active' => false]);
    $user = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::CompanyAdmin]);
    $this->actingAs($user)->get($path)->assertRedirect($login);
    $this->assertGuest();
})->with('session request paths');

test('inactive company and user are refused on admin tenant and direct panel URLs', function (bool $deactivateCompany, string $pathSuffix): void {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::CompanyAdmin]);
    if ($deactivateCompany) {
        $company->update(['is_active' => false]);
    } else {
        $user->forceFill(['is_active' => false])->save();
    }
    $this->actingAs($user)->get('/admin/'.$company->id.$pathSuffix)->assertRedirect('/admin/login');
    $this->assertGuest();
})->with([
    'inactive user dashboard' => [false, ''],
    'inactive user direct URL' => [false, '/auth-panel-probe'],
    'inactive company dashboard' => [true, ''],
    'inactive company direct URL' => [true, '/auth-panel-probe'],
]);

test('deactivation through the account service deletes database sessions', function (): void {
    $company = Company::factory()->create();
    $actor = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::CompanyAdmin]);
    $user = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::HrStaff]);
    DB::table('sessions')->insert(['id' => 'test-session', 'user_id' => $user->id, 'payload' => 'x', 'last_activity' => now()->timestamp]);
    app(UserAccountService::class)->update($user, ['is_active' => false], $actor);
    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);
});

test('account checks precede Filament tenant identification', function (): void {
    $priority = app('router')->middlewarePriority;
    expect(array_search(EnsureAccountIsActive::class, $priority, true))
        ->toBeLessThan(array_search(IdentifyTenant::class, $priority, true));
});

test('company users expire after 60 minutes of inactivity', function (): void {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::HrStaff]);
    $last = now()->timestamp;
    $this->travel(60)->minutes();
    $this->actingAs($user)->withSession(['last_activity_at' => $last])->get('/session-probe')->assertOk();
    $this->travel(1)->minutes();
    $this->withSession(['last_activity_at' => $last])->get('/session-probe')->assertRedirect('/admin/login');
    $this->assertGuest();
});

test('platform users expire after 30 minutes of inactivity', function (): void {
    $user = User::factory()->create();
    $last = now()->timestamp;
    $this->travel(30)->minutes();
    $this->actingAs($user)->withSession(['last_activity_at' => $last])->get('/platform')->assertOk();
    $this->travel(1)->minutes();
    $this->withSession(['last_activity_at' => $last])->get('/platform')->assertRedirect('/platform/login');
    $this->assertGuest();
});

test('inactive users and companies are refused on Livewire panel updates', function (bool $deactivateCompany): void {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::CompanyAdmin]);
    $this->actingAs($user);
    $snapshot = panelSnapshot($this, '/admin/'.$company->id.'/auth-panel-probe');
    if ($deactivateCompany) {
        DB::table('sessions')->insert(['id' => 'company-livewire-session', 'user_id' => $user->id, 'payload' => 'x', 'last_activity' => now()->timestamp]);
        app(CompanyStatusService::class)->setActive($company, false, User::factory()->create());
        expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);
        $response = postPanelUpdate($this, $snapshot);
        expect($response->baseResponse->getStatusCode())->toBeIn([401, 404]);
        Auth::forgetGuards();
        $this->get('/admin/'.$company->id)->assertRedirect('/admin/login');
        $this->assertGuest();
    } else {
        $user->forceFill(['is_active' => false])->save();
        postPanelUpdate($this, $snapshot)->assertUnauthorized();
        $this->assertGuest();
    }
})->with(['inactive user' => [false], 'inactive company' => [true]]);

test('platform panel Livewire update also enforces account deactivation', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);
    $snapshot = panelSnapshot($this, '/platform/auth-panel-probe');
    $user->forceFill(['is_active' => false])->save();
    postPanelUpdate($this, $snapshot)->assertUnauthorized();
    $this->assertGuest();
});

test('admin panel Livewire update uses the selected company for platform admin', function (): void {
    $selected = Company::factory()->create();
    $other = Company::factory()->create();
    app(TenantContext::class)->runForCompany($selected, fn () => Employee::factory()->count(2)->create());
    app(TenantContext::class)->runForCompany($other, fn () => Employee::factory()->create());
    $platformAdmin = User::factory()->create();
    $this->actingAs($platformAdmin);
    $snapshot = panelSnapshot($this, '/admin/'.$selected->id.'/auth-panel-probe');
    Filament::setTenant(null);
    app(TenantContext::class)->setAllCompanies();
    $response = postPanelUpdate($this, $snapshot)->assertOk();
    $updated = json_decode($response->json('components.0.snapshot'), true);
    expect($updated['data']['observedContext'])->toBe($selected->id);
    expect($updated['data']['employeeCount'])->toBe(2);
});
