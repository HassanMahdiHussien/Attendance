<?php

namespace App\Support\Access;

use App\Enums\EmailDraftStatus;
use App\Enums\UserRole;
use App\Models\EmailDraft;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class EmailDraftVisibility
{
    public static function inbox(User $user): Builder
    {
        $query = EmailDraft::query();

        return $user->is_active && $user->company?->is_active
            && in_array($user->primary_role, [UserRole::CompanyAdmin, UserRole::HrStaff], true)
            ? $query->where('company_id', $user->company_id)->where('status', EmailDraftStatus::Received->value)
            : $query->whereRaw('1 = 0');
    }

    public static function for(User $user): Builder
    {
        $query = EmailDraft::query();

        return $user->is_active && $user->company?->is_active
            ? $query->where('company_id', $user->company_id)->where('sender_user_id', $user->id)
            : $query->whereRaw('1 = 0');
    }
}
