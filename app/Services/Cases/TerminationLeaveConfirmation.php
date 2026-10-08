<?php

namespace App\Services\Cases;

use App\Enums\DocumentType;
use App\Enums\PenaltyType;
use App\Enums\UserRole;
use App\Exceptions\TransitionBlocked;
use App\Models\Document;
use App\Models\User;
use App\Services\CaseEventLogger;
use App\Support\Access\CaseVisibility;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class TerminationLeaveConfirmation
{
    public function __construct(private CaseEventLogger $events) {}

    public function confirm(Document $draft, User $hrUser): Document
    {
        return DB::transaction(function () use ($draft, $hrUser): Document {
            $document = Document::query()->lockForUpdate()->findOrFail($draft->id);
            Gate::forUser($hrUser)->authorize('update', $document->disciplinaryCase);
            if ($document->document_type !== DocumentType::DecisionLetter || $document->penalty_type !== PenaltyType::Termination
                || $document->is_locked || $document->leave_confirmed_at !== null
                || ! in_array($hrUser->primary_role, [UserRole::CompanyAdmin, UserRole::HrStaff], true)
                || ! CaseVisibility::for($hrUser)->whereKey($document->case_id)->exists()) {
                throw new TransitionBlocked('يلزم تأكيد حالة الإجازة من الموارد البشرية على كتاب إنهاء خدمة غير مقفل.');
            }
            $document->forceFill(['leave_confirmed_by' => $hrUser->id, 'leave_confirmed_at' => now()])->save();
            $this->events->log($document->disciplinaryCase, 'termination_leave_confirmed', ['document_id' => $document->id], actor: $hrUser);

            return $document;
        });
    }
}
