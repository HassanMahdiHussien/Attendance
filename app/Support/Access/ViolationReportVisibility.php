<?php

namespace App\Support\Access;

use App\Enums\ReportStatus;
use App\Enums\UserRole;
use App\Models\User;
use App\Models\ViolationReport;
use Illuminate\Database\Eloquent\Builder;

class ViolationReportVisibility
{
    public static function inbox(User $user): Builder
    {
        $query = ViolationReport::query();

        return $user->is_active && $user->company?->is_active
            && in_array($user->primary_role, [UserRole::CompanyAdmin, UserRole::HrStaff], true)
            ? $query->where('company_id', $user->company_id)->whereIn('status', [ReportStatus::Received->value, ReportStatus::UnderReview->value])
            : $query->whereRaw('1 = 0');
    }

    public static function for(User $user): Builder
    {
        $query = ViolationReport::query();

        return match ($user->primary_role) {
            UserRole::CompanyAdmin => $query->where('company_id', $user->company_id),
            UserRole::HrStaff => $user->can('view_all_company_cases')
                ? $query->where('company_id', $user->company_id)
                : $query->where('company_id', $user->company_id)->where(fn (Builder $q) => $q
                    ->where('reporter_user_id', $user->id)
                    ->orWhereIn('case_id', CaseVisibility::for($user)->select('cases.id'))),
            UserRole::Reporter, UserRole::DeptManager => $query->where('company_id', $user->company_id)->where('reporter_user_id', $user->id),
            default => $query->whereRaw('1 = 0'),
        };
    }
}
