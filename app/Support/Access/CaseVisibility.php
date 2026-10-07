<?php

namespace App\Support\Access;

use App\Enums\CaseStatus;
use App\Enums\UserRole;
use App\Models\DisciplinaryCase;
use App\Models\User;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Database\Eloquent\Builder;

class CaseVisibility
{
    public static function aggregateFor(User $user): Builder
    {
        if ($user->primary_role === UserRole::HrPartnerStaff) {
            return DisciplinaryCase::query()->withoutGlobalScope(CompanyScope::class)
                ->whereIn('cases.company_id', fn ($query) => $query->select('company_id')->from('company_hr_partner')
                    ->where('hr_partner_id', $user->hr_partner_id));
        }

        if ($user->primary_role === UserRole::ExternalLegalReviewer) {
            return DisciplinaryCase::query()->withoutGlobalScope(CompanyScope::class)
                ->whereNotNull('legal_review_requested_at')
                ->whereIn('cases.company_id', fn ($query) => $query->select('company_id')->from('company_law_firm_partner')
                    ->where('law_firm_partner_id', $user->law_firm_partner_id));
        }

        return self::for($user);
    }

    public static function for(User $user): Builder
    {
        $query = DisciplinaryCase::query();

        return match ($user->primary_role) {
            UserRole::PlatformAdmin => $query->withoutGlobalScope(CompanyScope::class),
            UserRole::CompanyAdmin => $query->where('cases.company_id', $user->company_id),
            UserRole::HrStaff => $user->can('view_all_company_cases')
                ? $query->where('cases.company_id', $user->company_id)
                : $query->where('cases.company_id', $user->company_id)->where(fn (Builder $q) => $q->where('opened_by_user_id', $user->id)
                    ->orWhere('assigned_hr_user_id', $user->id)
                    ->orWhereExists(fn ($sub) => self::pendingApprovalForPoolMember($sub, $user))),
            UserRole::CommitteeMember => $query->where('cases.company_id', $user->company_id)
                ->where(fn (Builder $q) => $q->whereExists(fn ($sub) => $sub->selectRaw('1')->from('committee_session_attendances as a')
                    ->join('committee_pool_members as p', 'p.id', '=', 'a.committee_pool_member_id')
                    ->whereColumn('a.case_id', 'cases.id')->where('p.user_id', $user->id))
                    ->orWhereExists(fn ($sub) => self::pendingApprovalForPoolMember($sub, $user))),
            UserRole::ExternalLegalReviewer => $query->withoutGlobalScope(CompanyScope::class)
                ->whereNotNull('legal_review_requested_at')
                ->whereNull('legal_review_cancelled_at')
                ->whereIn('status', array_map(fn (CaseStatus $status) => $status->value, array_slice(CaseStatus::cases(),
                    array_search(CaseStatus::SignedRecommendationsUploaded, CaseStatus::cases(), true))))
                ->whereIn('cases.company_id', fn ($q) => $q->select('company_id')->from('company_law_firm_partner')->where('law_firm_partner_id', $user->law_firm_partner_id)),
            UserRole::HrPartnerStaff => $query->withoutGlobalScope(CompanyScope::class)
                ->whereIn('cases.company_id', fn ($q) => $q->select('company_id')->from('company_hr_partner')->where('hr_partner_id', $user->hr_partner_id)->where('full_case_access', true)),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /**
     * CLAUDE.md §3: a recommendations document awaiting approval is visible to every pool member
     * of the case's branch under the mandate in effect, whatever their main role (HR-pool approvers
     * are usually HR staff, not committee-member accounts).
     */
    private static function pendingApprovalForPoolMember($sub, User $user)
    {
        return $sub->selectRaw('1')->from('committee_pool_members as p')
            ->where(fn ($stage) => $stage->where('cases.status', CaseStatus::RecommendationsAwaitingApprovals->value)
                ->orWhereExists(fn ($correction) => $correction->selectRaw('1')->from('documents as d')
                    ->whereColumn('d.case_id', 'cases.id')->where('d.document_type', 'recommendations')
                    ->where('d.version', '>', 1)->where('d.status', 'ready_for_review')->where('d.is_locked', false)))
            ->whereColumn('p.company_id', 'cases.company_id')->where('p.user_id', $user->id)
            ->where(fn ($branch) => $branch->whereNull('p.branch_id')->orWhereColumn('p.branch_id', 'cases.branch_id'))
            ->whereExists(fn ($mandate) => $mandate->selectRaw('1')->from('committee_mandates as m')
                ->whereColumn('m.id', 'p.mandate_id')
                ->whereColumn('m.effective_from', '<=', 'cases.defense_letter_issued_at')
                ->where(fn ($end) => $end->whereNull('m.superseded_at')->orWhereColumn('m.superseded_at', '>', 'cases.defense_letter_issued_at')));
    }
}
