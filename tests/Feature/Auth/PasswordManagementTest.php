<?php

use App\Enums\UserRole;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Livewire\Auth\ChangePassword;
use App\Livewire\Auth\Login;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Services\UserAccountService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

function passwordSession(User $user, string $id): void
{
    DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id, 'payload' => 'x', 'last_activity' => now()->timestamp]);
}

test('own password change validates current, strength, confirmation and difference', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);
    Livewire::test(ChangePassword::class)->set('current_password', 'wrong')
        ->set('new_password', 'AnotherPassword12')->set('new_password_confirmation', 'AnotherPassword12')
        ->call('save')->assertHasErrors(['current_password']);
    Livewire::test(ChangePassword::class)->set('current_password', 'password')
        ->set('new_password', 'short')->set('new_password_confirmation', 'short')
        ->call('save')->assertHasErrors(['new_password']);
    Livewire::test(ChangePassword::class)->set('current_password', 'password')
        ->set('new_password', 'AnotherPassword12')->set('new_password_confirmation', 'MismatchPassword12')
        ->call('save')->assertHasErrors(['new_password']);
    app(UserAccountService::class)->update($user, ['password' => 'LongEnoughPassword12'], $user);
    Livewire::test(ChangePassword::class)->set('current_password', 'LongEnoughPassword12')
        ->set('new_password', 'LongEnoughPassword12')->set('new_password_confirmation', 'LongEnoughPassword12')
        ->call('save')->assertHasErrors(['new_password']);
});

test('own password change preserves the current session, revokes others and audits without secrets', function (): void {
    $user = User::factory()->create();
    $user->forceFill(['must_change_password' => true])->save();
    $this->actingAs($user);
    $currentId = session()->getId();
    passwordSession($user, $currentId);
    passwordSession($user, 'another-session');
    $oldToken = $user->remember_token;

    app(UserAccountService::class)->changeOwnPassword($user, 'password', 'BrandNewPassword12');

    expect(Hash::check('BrandNewPassword12', $user->fresh()->password))->toBeTrue()
        ->and($user->fresh()->must_change_password)->toBeFalse()
        ->and($user->fresh()->password_changed_at)->not->toBeNull()
        ->and($user->fresh()->remember_token)->not->toBe($oldToken)
        ->and(DB::table('sessions')->where('id', $currentId)->exists())->toBeTrue()
        ->and(DB::table('sessions')->where('id', 'another-session')->exists())->toBeFalse();
    $audit = AuditLog::query()->where('action', 'user.password_changed')->latest('id')->firstOrFail();
    expect($audit->actor_user_id)->toBe($user->id)
        ->and(json_encode($audit->metadata))->not->toContain('BrandNewPassword12');
});

test('repeated wrong current passwords throttle even a later correct password', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);
    $key = 'password-change:'.$user->id.':'.hash('sha256', (string) request()->ip());
    RateLimiter::clear($key);
    for ($i = 0; $i < 5; $i++) {
        try {
            app(UserAccountService::class)->changeOwnPassword($user, 'wrong', 'BrandNewPassword12');
        } catch (ValidationException) {
        }
    }
    expect(fn () => app(UserAccountService::class)->changeOwnPassword($user, 'password', 'BrandNewPassword12'))
        ->toThrow(ValidationException::class);
    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
    RateLimiter::clear($key);
});

test('HR reset is scoped to its company and cannot target a platform admin or self', function (): void {
    $company = Company::factory()->create();
    $other = Company::factory()->create();
    $actor = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::HrStaff]);
    $target = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::HrStaff]);
    $foreign = User::factory()->create(['company_id' => $other->id, 'primary_role' => UserRole::HrStaff]);
    $platform = User::factory()->create();
    $this->actingAs($actor);
    passwordSession($target, 'target-session');
    app(UserAccountService::class)->resetPassword($target, 'TemporaryPass12', $actor);
    expect($target->fresh()->must_change_password)->toBeTrue()
        ->and(Hash::check('TemporaryPass12', $target->fresh()->password))->toBeTrue()
        ->and(DB::table('sessions')->where('id', 'target-session')->exists())->toBeFalse();
    $audit = AuditLog::query()->where('action', 'user.password_reset')->latest('id')->firstOrFail();
    expect($audit->actor_user_id)->toBe($actor->id)
        ->and(json_encode($audit->metadata))->not->toContain('TemporaryPass12');
    foreach ([$foreign, $platform, $actor] as $forbidden) {
        try {
            app(UserAccountService::class)->resetPassword($forbidden, 'TemporaryPass12', $actor);
            $this->fail('Reset should be forbidden.');
        } catch (HttpException $exception) {
            expect($exception->getStatusCode())->toBe(403);
        }
    }
});

test('platform admin can reset a platform peer and a company HR user including inactive users', function (): void {
    $actor = User::factory()->create();
    $peer = User::factory()->create();
    $company = Company::factory()->create();
    $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::HrStaff, 'is_active' => false]);
    $this->actingAs($actor);
    foreach ([$peer, $hr] as $target) {
        app(UserAccountService::class)->resetPassword($target, 'TemporaryPass12', $actor);
        expect($target->fresh()->must_change_password)->toBeTrue();
    }
});

test('edit form password changes force other users but not self', function (): void {
    $company = Company::factory()->create();
    $actor = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::HrStaff]);
    $target = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::HrStaff]);
    $this->actingAs($actor);
    app(UserAccountService::class)->update($target, ['password' => 'TemporaryPass12'], $actor);
    app(UserAccountService::class)->update($actor, ['password' => 'BrandNewPassword12'], $actor);
    expect($target->fresh()->must_change_password)->toBeTrue()
        ->and($actor->fresh()->must_change_password)->toBeFalse();
});

test('forced password change blocks workspaces and panels until completed but permits logout', function (): void {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::HrStaff]);
    $user->forceFill(['must_change_password' => true])->save();
    Livewire::test(Login::class)->set('email', $user->email)->set('password', 'password')
        ->call('authenticate')->assertRedirect(route('account.password'));
    $this->get('/home')->assertRedirect(route('account.password'));
    $this->getJson('/home')->assertForbidden()->assertJsonPath('message', __('messages.password.change_required'));
    $this->get('/admin/'.$company->id)->assertRedirect(route('account.password'));
    $this->get('/platform')->assertRedirect(route('account.password'));
    $this->get(route('account.password'))->assertOk();
    Livewire::test(ChangePassword::class)->set('current_password', 'password')
        ->set('new_password', 'BrandNewPassword12')->set('new_password_confirmation', 'BrandNewPassword12')
        ->call('save')->assertHasNoErrors();
    $this->get('/home')->assertOk();
    $user->forceFill(['must_change_password' => true])->save();
    $this->post('/logout')->assertRedirect('/login');
});

test('workspace account menu links to password change', function (): void {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::HrStaff]);
    $this->actingAs($user)->get('/home')->assertOk()->assertSee(route('account.password'), escape: false);
});

test('forced password change still lets only the change form post Livewire updates', function (): void {
    $user = User::factory()->create();
    $user->forceFill(['must_change_password' => true])->save();
    $this->actingAs($user);
    $update = collect(app('router')->getRoutes()->getRoutes())
        ->first(fn ($route): bool => str_ends_with((string) $route->getName(), 'livewire.update'));
    $passes = function (string $component) use ($update, $user): bool {
        $request = Request::create('/'.$update->uri(), 'POST', ['components' => [['snapshot' => json_encode(['memo' => ['name' => $component]])]]]);
        $request->setRouteResolver(fn () => $update);
        $request->setUserResolver(fn () => $user);

        return (new EnsurePasswordChanged)->handle($request, fn () => response('next'))->getContent() === 'next';
    };
    expect($passes('auth.change-password'))->toBeTrue()->and($passes('dashboard.hr-dashboard'))->toBeFalse();
});
