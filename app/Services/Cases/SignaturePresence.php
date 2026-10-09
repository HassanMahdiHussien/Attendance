<?php

namespace App\Services\Cases;

use App\Enums\AttachmentKind;
use App\Enums\CaseStatus;
use App\Enums\DocumentType;
use App\Enums\PoolRole;
use App\Enums\PresenceCheck;
use App\Enums\SignaturePurpose;
use App\Enums\SignatureStatus;
use App\Enums\UserRole;
use App\Exceptions\TransitionBlocked;
use App\Jobs\SuggestSignaturePresence;
use App\LegalLibrary\PdfPages;
use App\Models\Attachment;
use App\Models\CommitteeSessionAttendance;
use App\Models\DisciplinaryCase;
use App\Models\Document;
use App\Models\Employee;
use App\Models\Signature;
use App\Models\SignaturePresenceSuggestion;
use App\Models\User;
use App\Notifications\ConsultationReady;
use App\Services\Attachments\AttachmentService;
use App\Services\AuditLogger;
use App\Services\CaseEventLogger;
use App\Support\Access\CaseVisibility;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SignaturePresence
{
    public function __construct(private AttachmentService $attachments, private CaseWorkflow $workflow, private CaseEventLogger $events) {}

    public function recordSignedScan(Document $draft, UploadedFile $file, array $presence, User $hr): Document
    {
        return DB::transaction(function () use ($draft, $file, $presence, $hr): Document {
            $attachment = $this->uploadSignedScan($draft, $file, $hr, false);
            $this->recordPresence($draft, $attachment, $presence, $hr);

            return $draft;
        });
    }

    public function uploadSignedScan(Document $draft, UploadedFile $file, User $hr, bool $suggest = true): Attachment
    {
        return DB::transaction(function () use ($draft, $file, $hr, $suggest): Attachment {
            $case = DisciplinaryCase::query()->lockForUpdate()->findOrFail($draft->case_id);
            Gate::forUser($hr)->authorize('uploadAttachment', $case);
            $document = Document::query()->where('case_id', $case->id)->findOrFail($draft->id);
            if ($document->document_type !== DocumentType::Recommendations || ! $document->is_locked
                || $case->status !== CaseStatus::AwaitingCommitteeSignatures
                || ! in_array($hr->primary_role, [UserRole::CompanyAdmin, UserRole::HrStaff], true)) {
                throw new TransitionBlocked(__('messages.signature.issued_recommendations_required'));
            }
            if ($suggest && (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath()) === 'application/pdf'
                && app(PdfPages::class)->count($file->getRealPath()) > 20) {
                throw ValidationException::withMessages(['scan' => __('messages.signature.pdf_page_limit')]);
            }
            $attachment = $this->attachments->store($document, AttachmentKind::SignedDocumentScan, $file, $hr);
            if ($case->ai_request_id !== null) {
                $this->events->log($case, 'ai_request_superseded', ['operation' => $case->ai_request_document_type], actor: $hr);
                $this->clearToken($case);
            }
            if ($suggest) {
                $token = (string) Str::uuid();
                $case->forceFill(['ai_request_id' => $token, 'ai_request_document_type' => 'signature_presence',
                    'ai_request_from_status' => null, 'ai_request_attachment_id' => $attachment->id, 'ai_request_started_at' => now()])->save();
                DB::afterCommit(fn () => SuggestSignaturePresence::dispatch($case->id, $case->company_id, $hr->id, $document->id, $attachment->id, $token));
            }
            if ($suggest) {
                $this->events->log($case, 'signed_recommendations_scan_uploaded', ['document_id' => $document->id, 'attachment_id' => $attachment->id], actor: $hr);
            }

            return $attachment;
        });
    }

    public function recordPresence(Document $draft, Attachment $scan, array $presence, User $hr, ?int $suggestionId = null): Document
    {
        return DB::transaction(function () use ($draft, $scan, $presence, $hr, $suggestionId): Document {
            $case = DisciplinaryCase::query()->lockForUpdate()->findOrFail($draft->case_id);
            Gate::forUser($hr)->authorize('uploadAttachment', $case);
            $document = Document::query()->where('case_id', $case->id)->findOrFail($draft->id);
            if ($document->document_type !== DocumentType::Recommendations || ! $document->is_locked
                || $case->status !== CaseStatus::AwaitingCommitteeSignatures
                || ! in_array($hr->primary_role, [UserRole::CompanyAdmin, UserRole::HrStaff], true)) {
                throw new TransitionBlocked(__('messages.signature.issued_recommendations_required'));
            }
            $newest = $this->latestScan($document);
            if (! $newest || $newest->id !== $scan->id) {
                throw new TransitionBlocked(__('messages.signature.latest_scan_boxes_required'));
            }
            if (Signature::query()->where('attachment_id', $scan->id)
                ->where('purpose', SignaturePurpose::CommitteeSignature->value)->exists()) {
                throw new TransitionBlocked(__('messages.signature.scan_boxes_already_recorded'));
            }
            $attendees = CommitteeSessionAttendance::query()->where('case_id', $case->id)->with('committeePoolMember')->get();
            if ($attendees->count() < 3 || array_diff($attendees->modelKeys(), array_map('intval', array_keys($presence)))
                || array_diff(array_map('intval', array_keys($presence)), $attendees->modelKeys())) {
                throw new TransitionBlocked(__('messages.signature.each_attendee_box_required'));
            }
            foreach ($presence as $value) {
                if (! in_array($value, ['filled', 'empty'], true)) {
                    throw new TransitionBlocked(__('messages.signature.each_box_filled_or_empty'));
                }
            }
            $suggestion = $suggestionId ? SignaturePresenceSuggestion::query()->where('company_id', $case->company_id)
                ->where('case_id', $case->id)->where('document_id', $document->id)
                ->where('attachment_id', $scan->id)->findOrFail($suggestionId) : null;
            if ($case->ai_request_id !== null && $case->ai_request_document_type === 'signature_presence') {
                $this->events->log($case, 'ai_request_superseded', ['operation' => 'signature_presence'], actor: $hr);
                $this->clearToken($case);
            }
            foreach ($attendees as $attendance) {
                $member = $attendance->committeePoolMember;
                $suggestedResults = $suggestion?->results ?? [];
                $ai = $suggestedResults[$attendance->id]['result'] ?? null;
                Signature::create(['company_id' => $case->company_id, 'case_id' => $case->id,
                    'document_id' => $document->id, 'attachment_id' => $scan->id,
                    'presence_suggestion_id' => $suggestion?->id, 'ai_suggestion' => $ai,
                    'committee_pool_member_id' => $member->id,
                    'purpose' => SignaturePurpose::CommitteeSignature, 'status' => SignatureStatus::Pending,
                    'signer_name' => $member->person_name, 'signer_role' => $member->role_type->value,
                    'presence_check' => $presence[$attendance->id] === 'filled' ? PresenceCheck::BoxFilled : PresenceCheck::BoxAppearsEmpty,
                    'presence_checked_at' => now(), 'checked_by' => $hr->id]);
            }
            $this->events->log($case, 'signed_recommendations_scan_recorded', ['document_id' => $document->id,
                'attachment_id' => $scan->id, 'empty_attendee_ids' => $attendees->filter(fn ($attendance) => $presence[$attendance->id] === 'empty')->modelKeys()], actor: $hr);
            if (! in_array('empty', $presence, true)) {
                $this->workflow->transition($case, CaseStatus::SignedRecommendationsUploaded, $hr, 'All signature boxes appear filled');
                app(AutoDecisionDraft::class)->afterSignedRecommendations($case->refresh(), $hr);
                $this->notifyReviewers($case);
            }

            return $document;
        });
    }

    public function latestScan(Document $document): ?Attachment
    {
        return Attachment::query()->where('attachable_type', $document->getMorphClass())
            ->where('attachable_id', $document->id)->where('kind', AttachmentKind::SignedDocumentScan->value)
            ->orderByDesc('id')->first();
    }

    private function clearToken(DisciplinaryCase $case): void
    {
        $case->forceFill(['ai_request_id' => null, 'ai_request_document_type' => null,
            'ai_request_from_status' => null, 'ai_request_attachment_id' => null, 'ai_request_started_at' => null])->save();
    }

    public function overrideEmpty(Document $draft, string $reason, User $admin): DisciplinaryCase
    {
        return DB::transaction(function () use ($draft, $reason, $admin): DisciplinaryCase {
            $case = DisciplinaryCase::query()->lockForUpdate()->findOrFail($draft->case_id);
            Gate::forUser($admin)->authorize('update', $case);
            $document = $case->documents()->findOrFail($draft->id);
            if ($admin->primary_role !== UserRole::CompanyAdmin || blank($reason)
                || $case->status !== CaseStatus::AwaitingCommitteeSignatures
                || $document->document_type !== DocumentType::Recommendations || ! $document->is_locked) {
                throw new TransitionBlocked(__('messages.signature.admin_reason_and_issued_recommendations_required'));
            }
            $latestScan = $this->latestChecks($document);
            if ($this->latestScan($document)?->id !== $latestScan->first()?->attachment_id) {
                throw new TransitionBlocked(__('messages.signature.newer_scan_unrecorded'));
            }
            if ($latestScan->isEmpty() || ! $latestScan->contains(fn ($check) => $check->presence_check === PresenceCheck::BoxAppearsEmpty)) {
                throw new TransitionBlocked(__('messages.signature.empty_box_required'));
            }
            $this->events->log($case, 'empty_signature_box_overridden', ['document_id' => $document->id, 'reason' => $reason], actor: $admin);
            app(AuditLogger::class)->log('empty_signature_box_overridden', $document, ['reason' => $reason], $admin);

            $case = $this->workflow->transition($case, CaseStatus::SignedRecommendationsUploaded, $admin, 'Empty signature box overridden');
            app(AutoDecisionDraft::class)->afterSignedRecommendations($case, $admin);
            $this->notifyReviewers($case);

            return $case;
        });
    }

    public function latestChecks(Document $document): Collection
    {
        $attendeeIds = CommitteeSessionAttendance::query()->where('case_id', $document->case_id)
            ->pluck('committee_pool_member_id')->sort()->values()->all();
        $scanIds = Attachment::query()->where('attachable_type', $document->getMorphClass())
            ->where('attachable_id', $document->id)->where('kind', AttachmentKind::SignedDocumentScan->value)
            ->orderByDesc('id')->pluck('id');
        foreach ($scanIds as $scanId) {
            $checks = Signature::query()->where('document_id', $document->id)->where('attachment_id', $scanId)
                ->where('purpose', SignaturePurpose::CommitteeSignature->value)->get();
            if ($checks->count() === count($attendeeIds)
                && $checks->pluck('committee_pool_member_id')->unique()->sort()->values()->all() === $attendeeIds) {
                return $checks;
            }
        }

        return collect();
    }

    public function recordDecisionSignatures(Document $draft, array $presence, User $hr): void
    {
        DB::transaction(function () use ($draft, $presence, $hr): void {
            $document = Document::query()->lockForUpdate()->findOrFail($draft->id);
            $case = DisciplinaryCase::query()->lockForUpdate()->findOrFail($document->case_id);
            Gate::forUser($hr)->authorize('update', $case);
            if ($document->document_type !== DocumentType::DecisionLetter || ! $document->is_locked
                || ! in_array($case->status, [CaseStatus::AwaitingAppealWindow, CaseStatus::AppealPending], true)
                || ! CaseVisibility::for($hr)->whereKey($case->id)->exists()
                || array_diff(array_keys($presence), ['general_manager', 'union'])
                || array_diff(['general_manager', 'union'], array_keys($presence))) {
                throw new TransitionBlocked(__('messages.signature.issued_decision_and_boxes_required'));
            }
            foreach ($presence as $value) {
                if (! in_array($value, ['filled', 'empty'], true)) {
                    throw new TransitionBlocked(__('messages.signature.each_box_filled_or_empty'));
                }
            }
            $gm = Employee::query()->where('company_id', $case->company_id)->where('is_general_manager', true)->where('is_active', true)->first();
            $union = CommitteeSessionAttendance::query()->where('case_id', $case->id)
                ->whereHas('committeePoolMember', fn ($query) => $query->where('role_type', PoolRole::LaborUnion->value))
                ->with('committeePoolMember')->first();
            if (! $gm || ! $union) {
                throw new TransitionBlocked(__('messages.signature.paper_decision_signers_incomplete'));
            }
            foreach (['general_manager', 'union'] as $signer) {
                $isGm = $signer === 'general_manager';
                Signature::create(['company_id' => $case->company_id, 'case_id' => $case->id,
                    'document_id' => $document->id, 'purpose' => $isGm ? SignaturePurpose::GeneralManagerSignature : SignaturePurpose::UnionSignature,
                    'status' => SignatureStatus::Pending, 'signer_name' => $isGm ? $gm->name : $union->committeePoolMember->person_name,
                    'signer_role' => $signer, 'employee_id' => $isGm ? $gm->id : null,
                    'committee_pool_member_id' => $isGm ? null : $union->committee_pool_member_id,
                    'presence_check' => $presence[$signer] === 'filled' ? PresenceCheck::BoxFilled : PresenceCheck::BoxAppearsEmpty,
                    'presence_checked_at' => now(), 'checked_by' => $hr->id]);
            }
            $this->events->log($case, 'decision_signature_boxes_recorded', ['document_id' => $document->id,
                'empty_signers' => array_keys(array_filter($presence, fn ($value) => $value === 'empty'))], actor: $hr);
        });
    }

    private function notifyReviewers(DisciplinaryCase $case): void
    {
        if (! $case->legal_review_requested_at || $case->legal_review_completed_at || $case->legal_review_cancelled_at) {
            return;
        }
        $reviewers = User::query()->where('primary_role', UserRole::ExternalLegalReviewer->value)->where('is_active', true)
            ->whereIn('law_firm_partner_id', DB::table('company_law_firm_partner')
                ->where('company_id', $case->company_id)->select('law_firm_partner_id'))->get();
        Notification::send($reviewers, new ConsultationReady($case->id));
    }
}
