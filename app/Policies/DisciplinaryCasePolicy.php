<?php

namespace App\Policies;

use App\Models\DisciplinaryCase;
use App\Models\User;
use App\Support\Access\CaseVisibility;

class DisciplinaryCasePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, DisciplinaryCase $case): bool
    {
        return CaseVisibility::for($user)->whereKey($case->getKey())->exists();
    }
}
