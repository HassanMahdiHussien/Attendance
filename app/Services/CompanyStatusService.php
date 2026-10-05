<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CompanyStatusService
{
    public function setActive(Company $company, bool $active, User $actor): void
    {
        abort_unless($actor->primary_role === UserRole::PlatformAdmin, 403);

        DB::transaction(function () use ($company, $active, $actor): void {
            Company::query()->whereKey($company->getKey())->lockForUpdate()->firstOrFail();
            $company->refresh();
            if ($company->is_active === $active) {
                return;
            }

            $before = $company->is_active;
            $company->forceFill(['is_active' => $active])->save();
            $purged = $active ? 0 : DB::table('sessions')
                ->whereIn('user_id', DB::table('users')->select('id')->where('company_id', $company->id))
                ->delete();

            app(AuditLogger::class)->log($active ? 'company.activated' : 'company.deactivated', $company, [
                'before' => ['is_active' => $before],
                'after' => ['is_active' => $active],
                'sessions_purged' => $purged,
            ], $actor);
        });
    }
}
