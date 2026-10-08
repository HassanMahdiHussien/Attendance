<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\Statistics\ReportExporter;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserAccountService
{
    public function create(array $data, User $actor): User
    {
        $this->authorizeTarget($actor, $data['primary_role'] ?? null, $data['company_id'] ?? null);
        $this->authorizeAddons($actor, $data);

        return DB::transaction(function () use ($data, $actor): User {
            $addons = $data['addons'] ?? [];
            $branchIds = $data['branch_ids'] ?? [];
            unset($data['addons'], $data['branch_ids']);
            $user = new User;
            $user->fill(Arr::only($data, ['name', 'email', 'password']));
            $user->forceFill(Arr::only($data, ['company_id', 'employee_id', 'primary_role', 'law_firm_partner_id', 'hr_partner_id', 'is_active']));
            $user->save();
            if ($actor->primary_role !== UserRole::HrStaff) {
                $this->setAddons($user, $addons, $actor);
            }
            if ($branchIds !== []) {
                $this->setBranchScope($user, $branchIds, $actor);
            }

            return $user;
        });
    }

    public function update(User $user, array $data, User $actor): User
    {
        abort_unless($actor->can('update', $user), 403);
        $this->authorizeTarget($actor, $data['primary_role'] ?? $user->primary_role, $data['company_id'] ?? $user->company_id);
        $this->authorizeAddons($actor, $data);

        return DB::transaction(function () use ($user, $data, $actor): User {
            $wasActive = $user->is_active;
            $explicitAddons = array_key_exists('addons', $data);
            $addons = $data['addons'] ?? $user->getPermissionNames()->all();
            $branchIds = $data['branch_ids'] ?? null;
            unset($data['addons'], $data['branch_ids']);
            $newRole = $data['primary_role'] ?? $user->primary_role;
            $newRole = $newRole instanceof UserRole ? $newRole : UserRole::from($newRole);
            if ($newRole !== $user->primary_role && ! $explicitAddons) {
                $addons = array_values(array_intersect($addons, $this->allowedAddons($newRole)));
            }
            if ($newRole !== UserRole::CompanyAdmin || ($data['is_active'] ?? $user->is_active) === false) {
                $this->guardLastAdmin($user);
            }
            $user->fill(Arr::only($data, ['name', 'email', 'password']));
            $user->forceFill(Arr::only($data, ['company_id', 'employee_id', 'primary_role', 'law_firm_partner_id', 'hr_partner_id', 'is_active']));
            $user->save();
            if (! in_array($user->primary_role, [UserRole::Reporter, UserRole::DeptManager], true)
                && DB::table('branch_user')->where('user_id', $user->id)->exists()) {
                $this->setBranchScope($user, [], $actor);
            }
            if ($branchIds !== null) {
                $this->setBranchScope($user, $branchIds, $actor);
            }
            if ($actor->primary_role !== UserRole::HrStaff) {
                $this->setAddons($user, $addons, $actor);
            }
            if ($wasActive && ! $user->is_active) {
                $this->purgeSessions($user, $actor);
                app(ReportExporter::class)->revokeAllFor($user);
            }

            return $user;
        });
    }

    public function setAddons(User $user, array $names, User $actor): void
    {
        abort_unless(in_array($actor->primary_role, [UserRole::PlatformAdmin, UserRole::CompanyAdmin], true)
            && ($actor->primary_role === UserRole::PlatformAdmin || $actor->company_id === $user->company_id), 403);
        $role = $user->primary_role;
        $allowed = $this->allowedAddons($role);
        if (array_diff($names, $allowed)) {
            throw ValidationException::withMessages(['addons' => __('messages.user.addon_unavailable_for_role')]);
        }
        $before = $user->getPermissionNames()->all();
        $user->syncPermissions($names);
        if ($before !== $names) {
            app(AuditLogger::class)->log('user.addons_changed', $user, ['before' => $before, 'after' => $names], $actor);
        }
    }

    public function setBranchScope(User $user, array $branchIds, User $actor): void
    {
        abort_unless($actor->can('update', $user), 403);
        if (! in_array($user->primary_role, [UserRole::Reporter, UserRole::DeptManager], true) && $branchIds !== []) {
            throw ValidationException::withMessages(['branches' => __('messages.user.branch_scope_unavailable')]);
        }
        foreach ($branchIds as $id) {
            if (! DB::table('branches')->where('id', $id)->where('company_id', $user->company_id)->exists()) {
                throw ValidationException::withMessages(['branches' => __('messages.user.branch_other_company')]);
            }
        }
        DB::transaction(function () use ($user, $branchIds, $actor): void {
            $before = array_map('intval', DB::table('branch_user')->where('user_id', $user->id)->pluck('branch_id')->all());
            $branchIds = array_map('intval', $branchIds);
            sort($before);
            sort($branchIds);
            if ($before === $branchIds) {
                return;
            }
            DB::table('branch_user')->where('user_id', $user->id)->delete();
            foreach ($branchIds as $id) {
                DB::table('branch_user')->insert(['company_id' => $user->company_id, 'branch_id' => $id, 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
            }
            app(AuditLogger::class)->log('user.branch_scope_changed', $user, ['before' => $before, 'after' => $branchIds], $actor);
        });
    }

    private function guardLastAdmin(User $user): void
    {
        if ($user->primary_role === UserRole::CompanyAdmin && $user->is_active && ! User::inCompany($user->company_id)
            ->where('primary_role', UserRole::CompanyAdmin->value)->where('is_active', true)->whereKeyNot($user->id)->exists()) {
            throw ValidationException::withMessages(['primary_role' => __('messages.user.last_active_admin_required')]);
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

    private function allowedAddons(UserRole $role): array
    {
        $allowed = in_array($role, [UserRole::CompanyAdmin, UserRole::HrStaff, UserRole::DeptManager, UserRole::Reporter, UserRole::CommitteeMember], true)
            ? ['review_internal_regulation'] : [];
        if ($role === UserRole::HrStaff) {
            $allowed[] = 'view_all_company_cases';
        }

        return $allowed;
    }

    private function authorizeTarget(User $actor, UserRole|string|null $role, ?int $companyId): void
    {
        $role = $role instanceof UserRole ? $role : ($role === null ? null : UserRole::from($role));
        abort_unless($role !== null, 422);
        if ($actor->primary_role === UserRole::PlatformAdmin) {
            return;
        }
        abort_unless($actor->company_id === $companyId, 403);
        if ($actor->primary_role === UserRole::CompanyAdmin) {
            abort_unless(in_array($role, [UserRole::CompanyAdmin, UserRole::HrStaff, UserRole::DeptManager, UserRole::Reporter, UserRole::CommitteeMember], true), 403);

            return;
        }
        abort_unless($actor->primary_role === UserRole::HrStaff && in_array($role, [UserRole::Reporter, UserRole::DeptManager], true), 403);
    }

    private function authorizeAddons(User $actor, array $data): void
    {
        if (array_key_exists('addons', $data)) {
            abort_unless(in_array($actor->primary_role, [UserRole::PlatformAdmin, UserRole::CompanyAdmin], true), 403);
        }
    }
}
