<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
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
            $user->fill(Arr::only($data, ['name', 'email', 'password']));
            $user->forceFill(Arr::only($data, ['company_id', 'employee_id', 'primary_role', 'is_active']));
            $user->save();
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
