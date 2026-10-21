<?php

namespace App\Services\Cases;

use App\Ai\AiDrafting;
use App\Enums\CaseStatus;
use App\Enums\DocumentType;
use App\Models\DisciplinaryCase;
use App\Models\User;
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
        try {
            $this->drafting->request($case, DocumentType::DefenseLetter, $actor);
        } catch (Throwable $exception) {
            Log::warning('Automatic defense draft request skipped', ['case_id' => $case->id, 'exception' => $exception::class]);
        }
    }
}
