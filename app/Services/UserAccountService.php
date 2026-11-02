<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class UserAccountService
{
    public function create(array $data, User $actor): User
    {
        $this->authorizeTarget($actor, $data['primary_role'] ?? null, $data['company_id'] ?? null);

        return DB::transaction(function () use ($data): User {
            $user = new User;
            $user->fill(Arr::only($data, ['name', 'email', 'password']));
            $user->forceFill(Arr::only($data, ['company_id', 'employee_id', 'primary_role', 'is_active']));
            $user->save();

            return $user;
        });
    }

    public function changeOwnPassword(User $user, string $current, string $new): void
    {
        abort_unless(auth()->id() === $user->id, 403);
        $key = 'password-change:'.$user->id.':'.hash('sha256', (string) request()->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['current_password' => __('messages.password.throttled')]);
        }
        if (! Hash::check($current, $user->password)) {
            RateLimiter::hit($key, 300);
            throw ValidationException::withMessages(['current_password' => __('messages.password.current_incorrect')]);
        }
        Validator::make(['new_password' => $new], ['new_password' => ['required', Password::defaults()]])->validate();
        if (Hash::check($new, $user->password)) {
            throw ValidationException::withMessages(['new_password' => __('messages.password.must_differ')]);
        }
        DB::transaction(fn () => $this->setPassword($user, $new, false, $user, true));
        RateLimiter::clear($key);
    }

    public function resetPassword(User $target, string $temporary, User $actor): void
    {
        abort_if($actor->is($target), 403);
        abort_unless($actor->can('update', $target), 403);
        $this->authorizeTarget($actor, $target->primary_role, $target->company_id);
        Validator::make(['temporary_password' => $temporary], ['temporary_password' => ['required', Password::defaults()]])->validate();
        if (Hash::check($temporary, $target->password)) {
            throw ValidationException::withMessages(['temporary_password' => __('messages.password.must_differ')]);
        }
        DB::transaction(fn () => $this->setPassword($target, $temporary, true, $actor, false));
    }

    private function setPassword(User $user, string $password, bool $forced, User $actor, bool $keepCurrentSession): void
    {
        $before = [
            'must_change_password' => $user->must_change_password,
            'password_changed_at' => $user->password_changed_at?->toISOString(),
        ];
        $user->forceFill([
            'password' => $password,
            'must_change_password' => $forced,
            'password_changed_at' => $forced ? $user->password_changed_at : now(),
            'remember_token' => Str::random(60),
        ])->save();
        $sessions = DB::table('sessions')->where('user_id', $user->id);
        if ($keepCurrentSession) {
            $sessions->where('id', '!=', session()->getId());
        }
        $count = $sessions->delete();
        app(AuditLogger::class)->log($forced ? 'user.password_reset' : 'user.password_changed', $user,
            ['before' => $before, 'after' => [
                'must_change_password' => $user->must_change_password,
                'password_changed_at' => $user->password_changed_at?->toISOString(),
            ], 'sessions_purged' => $count], $actor);
    }

    public function update(User $user, array $data, User $actor): User
    {
        abort_unless($actor->can('update', $user), 403);
        $this->authorizeTarget($actor, $data['primary_role'] ?? $user->primary_role, $data['company_id'] ?? $user->company_id);

        return DB::transaction(function () use ($user, $data, $actor): User {
            $wasActive = $user->is_active;
            $newRole = $data['primary_role'] ?? $user->primary_role;
            $newRole = $newRole instanceof UserRole ? $newRole : UserRole::from($newRole);
            if ($newRole !== UserRole::HrStaff || ($data['is_active'] ?? $user->is_active) === false
                || ($data['company_id'] ?? $user->company_id) !== $user->company_id) {
                $this->guardLastHrUser($user);
            }
            $user->fill(Arr::only($data, ['name', 'email']));
            $user->forceFill(Arr::only($data, ['company_id', 'employee_id', 'primary_role', 'is_active']));
            $user->save();
            if (filled($data['password'] ?? null)) {
                Validator::make(['password' => $data['password']], ['password' => ['required', Password::defaults()]])->validate();
                if ($actor->is($user)) {
                    if (Hash::check($data['password'], $user->password)) {
                        throw ValidationException::withMessages(['password' => __('messages.password.must_differ')]);
                    }
                    $this->setPassword($user, $data['password'], false, $actor, true);
                } else {
                    $this->resetPassword($user, $data['password'], $actor);
                }
            }
            if ($wasActive && ! $user->is_active) {
                $this->purgeSessions($user, $actor);
            }

            return $user;
        });
    }

    private function guardLastHrUser(User $user): void
    {
        if ($user->primary_role === UserRole::HrStaff && $user->is_active && ! User::inCompany($user->company_id)
            ->where('primary_role', UserRole::HrStaff->value)->where('is_active', true)->whereKeyNot($user->id)->exists()) {
            throw ValidationException::withMessages(['primary_role' => __('messages.user.last_active_hr_required')]);
        }
    }

    private function purgeSessions(User $user, User $actor): void
    {
        $count = DB::table('sessions')->where('user_id', $user->id)->delete();
        app(AuditLogger::class)->log('user.deactivated', $user, [
            'before' => ['is_active' => true],
            'after' => ['is_active' => false],
            'sessions_purged' => $count,
        ], $actor);
    }

    private function authorizeTarget(User $actor, UserRole|string|null $role, ?int $companyId): void
    {
        $role = $role instanceof UserRole ? $role : ($role === null ? null : UserRole::from($role));
        abort_unless($role !== null, 422);
        if ($actor->primary_role === UserRole::PlatformAdmin) {
            abort_unless(($role === UserRole::PlatformAdmin) === ($companyId === null), 403);

            return;
        }
        abort_unless($actor->primary_role === UserRole::HrStaff && $role === UserRole::HrStaff
            && $actor->company_id !== null && $actor->company_id === $companyId, 403);
    }
}
