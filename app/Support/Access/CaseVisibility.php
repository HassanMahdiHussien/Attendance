<?php

namespace App\Support\Access;

use App\Enums\UserRole;
use App\Models\DisciplinaryCase;
use App\Models\User;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Database\Eloquent\Builder;

class CaseVisibility
{
    public static function for(User $user): Builder
    {
        $query = DisciplinaryCase::query();

        return match ($user->primary_role) {
            UserRole::PlatformAdmin => $query->withoutGlobalScope(CompanyScope::class),
            UserRole::CompanyAdmin => $query->where('company_id', $user->company_id),
            UserRole::HrStaff => $user->can('view_all_company_cases')
                ? $query->where('company_id', $user->company_id)
                : $query->where('company_id', $user->company_id)->where(fn (Builder $q) => $q->where('opened_by_user_id', $user->id)->orWhere('assigned_hr_user_id', $user->id)),
            UserRole::CommitteeMember => $query->where('company_id', $user->company_id)
                ->whereExists(fn ($q) => $q->selectRaw('1')->from('committee_session_attendances as a')
                    ->join('committee_pool_members as p', 'p.id', '=', 'a.committee_pool_member_id')
                    ->whereColumn('a.case_id', 'cases.id')->where('p.user_id', $user->id)),
            UserRole::ExternalLegalReviewer => $query->withoutGlobalScope(CompanyScope::class)
                ->whereNotNull('legal_review_requested_at')->whereIn('company_id', fn ($q) => $q->select('company_id')->from('company_law_firm_partner')->where('law_firm_partner_id', $user->law_firm_partner_id)),
            UserRole::HrPartnerStaff => $query->withoutGlobalScope(CompanyScope::class)
                ->whereIn('company_id', fn ($q) => $q->select('company_id')->from('company_hr_partner')->where('hr_partner_id', $user->hr_partner_id)->where('full_case_access', true)),
            default => $query->whereRaw('1 = 0'),
        };
    }
}
