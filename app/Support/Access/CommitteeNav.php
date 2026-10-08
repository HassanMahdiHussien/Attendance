<?php

namespace App\Support\Access;

use App\Models\User;
use App\Services\Cases\PoolEligibility;

/**
 * Whether the committee navigation item should appear: the user is an eligible approver for at least
 * one recommendations document awaiting approval. Uses the same eligibility rule as the approval
 * service so the menu never offers work the service would refuse.
 */
class CommitteeNav
{
    public static function hasPending(?User $user): bool
    {
        if ($user === null || $user->company_id === null) {
            return false;
        }

        return app(PoolEligibility::class)->pendingFor($user)->isNotEmpty();
    }
}
