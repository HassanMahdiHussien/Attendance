<?php

namespace App\Ai;

use App\Enums\CaseStatus;
use App\Enums\DocumentType;
use App\Exceptions\TransitionBlocked;
use App\Models\DisciplinaryCase;
use App\Models\Document;
use App\Models\LegalUnit;
use App\Models\RegulationComparison;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Cases\AutoDefenseDraft;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RegulationComparisons
{
    public function required(DisciplinaryCase $case): bool
    {
        return LegalUnit::query()->citableFor($case)
            ->whereHas('law', fn ($law) => $law->where('kind', 'internal_regulation'))->exists();
    }

    public function pending(DisciplinaryCase $case, DocumentType $type): ?RegulationComparison
    {
        return RegulationComparison::query()->where('case_id', $case->id)->where('document_type', $type->value)
            ->whereNull('consumed_at')->latest('id')->first();
    }

    public function startSupplementary(Document $document, User $actor): RegulationComparison
    {
        return DB::transaction(function () use ($document, $actor): RegulationComparison {
            $document = Document::query()->lockForUpdate()->findOrFail($document->id);
            Gate::forUser($actor)->authorize('manageDocuments', $document->disciplinaryCase);
            if ($document->document_type !== DocumentType::DecisionLetter || $document->is_locked
                || ! $document->regulation_comparison_id) {
                throw new TransitionBlocked(__('messages.regulation.supplement_required'));
            }
            if ($document->supplementaryComparison) {
                return $document->supplementaryComparison;
            }
            $comparison = RegulationComparison::create(['company_id' => $document->company_id,
                'case_id' => $document->case_id, 'document_type' => DocumentType::DecisionLetter->value,
                'status' => 'ready', 'origin' => 'manual']);
            $document->forceFill(['supplementary_comparison_id' => $comparison->id])->save();

            return $comparison;
        });
    }

    public function startForDocument(Document $document, User $actor): RegulationComparison
    {
        return DB::transaction(function () use ($document, $actor): RegulationComparison {
            $document = Document::query()->lockForUpdate()->findOrFail($document->id);
            Gate::forUser($actor)->authorize('manageDocuments', $document->disciplinaryCase);
            if ($document->is_locked || $document->regulation_comparison_id) {
                throw new TransitionBlocked(__('messages.regulation.confirm_first'));
            }
            $comparison = RegulationComparison::create(['company_id' => $document->company_id,
                'case_id' => $document->case_id, 'document_type' => $document->document_type->value,
                'status' => 'ready', 'origin' => 'manual']);
            $document->forceFill(['regulation_comparison_id' => $comparison->id])->save();

            return $comparison;
        });
    }

    public function startManual(DisciplinaryCase $case, DocumentType $type, User $actor): RegulationComparison
    {
        Gate::forUser($actor)->authorize('manageDocuments', $case);
        $pending = $this->pending($case, $type);
        if ($pending) {
            return $pending;
        }

        return RegulationComparison::create(['company_id' => $case->company_id,
            'case_id' => $case->id, 'document_type' => $type->value,
            'status' => 'ready', 'origin' => 'manual']);
    }

    public function validatePair(DisciplinaryCase $case, int $lawId, int $regulationId): void
    {
        $units = LegalUnit::query()->citableFor($case)->with('law')->whereKey([$lawId, $regulationId])->get()->keyBy('id');
        if ($units->get($lawId)?->law?->kind?->value !== 'statute'
            || $units->get($regulationId)?->law?->kind?->value !== 'internal_regulation'
            || (int) $units->get($regulationId)?->company_id !== (int) $case->company_id) {
            throw ValidationException::withMessages(['pair' => __('messages.regulation.invalid_pair')]);
        }
    }

    public function addPair(RegulationComparison $comparison, int $lawId, int $regulationId, string $verdict, User $actor): void
    {
        Gate::forUser($actor)->authorize('manageDocuments', $comparison->disciplinaryCase);
        if (! in_array($comparison->status, ['ready', 'failed'], true)
            || ! in_array($verdict, ['consistent', 'conflict', 'more_favourable', 'not_relevant'], true)) {
            throw ValidationException::withMessages(['pair' => __('messages.regulation.invalid_pair')]);
        }
        $this->validatePair($comparison->disciplinaryCase, $lawId, $regulationId);
        if ($comparison->status === 'failed' && $comparison->origin !== 'manual') {
            $comparison->forceFill(['origin' => 'manual'])->save();
        }
        $comparison->pairs()->firstOrCreate(['law_unit_id' => $lawId, 'regulation_unit_id' => $regulationId],
            ['company_id' => $comparison->company_id, 'verdict' => $verdict, 'changed_by_hr' => true]);
    }

    public function confirm(RegulationComparison $comparison, User $actor, array $verdicts): void
    {
        DB::transaction(function () use ($comparison, $actor, $verdicts): void {
            $comparison = RegulationComparison::query()->lockForUpdate()->findOrFail($comparison->id);
            Gate::forUser($actor)->authorize('manageDocuments', $comparison->disciplinaryCase);
            if (! in_array($comparison->status, ['ready', 'failed'], true)) {
                throw new TransitionBlocked(__('messages.regulation.in_progress'));
            }
            if (! $comparison->pairs()->exists()) {
                throw ValidationException::withMessages(['pair' => __('messages.regulation.pair_required')]);
            }
            foreach ($comparison->pairs as $pair) {
                $verdict = $verdicts[$pair->id] ?? $pair->verdict;
                if (! in_array($verdict, ['consistent', 'conflict', 'more_favourable', 'not_relevant'], true)) {
                    throw ValidationException::withMessages(['verdict' => __('messages.regulation.invalid_verdict')]);
                }
                $this->validatePair($comparison->disciplinaryCase, $pair->law_unit_id, $pair->regulation_unit_id);
                $pair->forceFill(['verdict' => $verdict, 'changed_by_hr' => $pair->ai_verdict === null || $pair->ai_verdict !== $verdict])->save();
            }
            $linked = Document::query()->where('regulation_comparison_id', $comparison->id)
                ->orWhere('supplementary_comparison_id', $comparison->id)->exists();
            $comparison->forceFill(['status' => 'confirmed', 'confirmed_by' => $actor->id, 'confirmed_at' => now(),
                'consumed_at' => $linked ? now() : null])->save();
            app(AuditLogger::class)->log('regulation_comparison.confirmed', $comparison,
                ['pairs' => $comparison->pairs->map(fn ($pair) => ['law_unit_id' => $pair->law_unit_id,
                    'regulation_unit_id' => $pair->regulation_unit_id, 'verdict' => $pair->verdict,
                    'changed_by_hr' => $pair->changed_by_hr])->all()], $actor);
        });
        $this->resumeDefense($comparison, $actor);
    }

    public function skip(RegulationComparison $comparison, User $actor, string $reason): void
    {
        if (blank(trim($reason))) {
            throw ValidationException::withMessages(['skipReason' => __('messages.regulation.reason_required')]);
        }
        DB::transaction(function () use ($comparison, $actor, $reason): void {
            $comparison = RegulationComparison::query()->lockForUpdate()->findOrFail($comparison->id);
            Gate::forUser($actor)->authorize('manageDocuments', $comparison->disciplinaryCase);
            if (! in_array($comparison->status, ['ready', 'failed'], true)) {
                throw new TransitionBlocked(__('messages.regulation.in_progress'));
            }
            $linked = Document::query()->where('regulation_comparison_id', $comparison->id)
                ->orWhere('supplementary_comparison_id', $comparison->id)->exists();
            $comparison->forceFill(['status' => 'skipped', 'skip_reason' => trim($reason),
                'confirmed_by' => $actor->id, 'confirmed_at' => now(), 'consumed_at' => $linked ? now() : null])->save();
            app(AuditLogger::class)->log('regulation_comparison.skipped', $comparison, [], $actor);
        });
        $this->resumeDefense($comparison, $actor);
    }

    private function resumeDefense(RegulationComparison $comparison, User $actor): void
    {
        if ($comparison->document_type !== DocumentType::DefenseLetter->value) {
            return;
        }
        $case = $comparison->disciplinaryCase;
        if ($case->status === CaseStatus::Open
            && ! $case->documents()->where('document_type', DocumentType::DefenseLetter->value)->exists()) {
            app(AutoDefenseDraft::class)->afterOpening($case, $actor);
        }
    }
}
