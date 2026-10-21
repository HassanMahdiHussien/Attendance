<?php

namespace App\Services\Cases;

use App\Ai\AiDrafting;
use App\Enums\CaseStatus;
use App\Enums\DocumentType;
use App\Jobs\ProposeIncidentDescription;
use App\Models\DisciplinaryCase;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class AutoDefenseDraft
{
    public function __construct(private AiDrafting $drafting) {}

    public function afterOpening(DisciplinaryCase $case, User $actor): void
    {
        if (blank(config('services.anthropic.key')) || $case->status !== CaseStatus::Open
            || $case->ai_request_id !== null) {
            return;
        }
        $actor = User::query()->where('company_id', $case->company_id)->find($case->opened_by_user_id);
        if (! $actor || ! $actor->is_active) {
            return;
        }
        if ($case->incident_id !== null && blank($case->case_specific_description)) {
            $dispatch = fn () => ProposeIncidentDescription::dispatch($case->id, $case->company_id, $actor->id);
            if (DB::transactionLevel() > 0) {
                DB::afterCommit($dispatch);
            } else {
                $dispatch();
            }

            return;
        }
        try {
            $this->drafting->request($case, DocumentType::DefenseLetter, $actor);
        } catch (Throwable $exception) {
            Log::warning('Automatic defense draft request skipped', ['case_id' => $case->id, 'exception' => $exception::class]);
        }
    }
}
