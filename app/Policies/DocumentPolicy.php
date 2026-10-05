<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;
use App\Support\Access\CaseVisibility;

class DocumentPolicy
{
    public function view(User $user, Document $document): bool
    {
        return CaseVisibility::for($user)->whereKey($document->case_id)->exists();
    }
}
