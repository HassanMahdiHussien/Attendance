<?php

namespace App\Services\Cases;

use App\Enums\AttachmentKind;
use App\Enums\CaseStatus;
use App\Enums\DocumentType;
use App\Enums\PresenceCheck;
use App\Enums\SignaturePurpose;
use App\Enums\SignatureStatus;
use App\Enums\UserRole;
use App\Exceptions\TransitionBlocked;
use App\Models\CommitteeSessionAttendance;
use App\Models\DisciplinaryCase;
use App\Models\Document;
use App\Models\Signature;
use App\Models\User;
use App\Notifications\ConsultationReady;
use App\Services\Attachments\AttachmentService;
use App\Services\AuditLogger;
use App\Services\CaseEventLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;

class SignaturePresence
{
    public function __construct(private AttachmentService $attachments, private CaseWorkflow $workflow, private CaseEventLogger $events) {}

    public function recordSignedScan(Document $draft, UploadedFile $file, array $presence, User $hr): Document
    {
        return DB::transaction(function () use ($draft, $file, $presence, $hr): Document {
            $case = DisciplinaryCase::query()->lockForUpdate()->findOrFail($draft->case_id);
            Gate::forUser($hr)->authorize('uploadAttachment', $case);
            $document = Document::query()->where('case_id', $case->id)->findOrFail($draft->id);
            if ($document->document_type !== DocumentType::Recommendations || ! $document->is_locked
                || $case->status !== CaseStatus::AwaitingCommitteeSignatures
                || ! in_array($hr->primary_role, [UserRole::CompanyAdmin, UserRole::HrStaff], true)) {
                throw new TransitionBlocked('Issued recommendations awaiting signatures are required.');
            }
            $attendees = CommitteeSessionAttendance::query()->where('case_id', $case->id)->with('committeePoolMember')->get();
            if ($attendees->count() < 3 || array_diff($attendees->modelKeys(), array_map('intval', array_keys($presence)))
                || array_diff(array_map('intval', array_keys($presence)), $attendees->modelKeys())) {
                throw new TransitionBlocked('Record every attendee signature box.');
            }
            foreach ($presence as $value) {
                if (! in_array($value, ['filled', 'empty'], true)) {
                    throw new TransitionBlocked('Each signature box must be filled or empty.');
                }
            }
            $attachment = $this->attachments->store($document, AttachmentKind::SignedDocumentScan, $file, $hr);
            foreach ($attendees as $attendance) {
                $member = $attendance->committeePoolMember;
                Signature::create(['company_id' => $case->company_id, 'case_id' => $case->id,
                    'document_id' => $document->id, 'committee_pool_member_id' => $member->id,
                    'purpose' => SignaturePurpose::CommitteeSignature, 'status' => SignatureStatus::Pending,
                    'signer_name' => $member->person_name, 'signer_role' => $member->role_type->value,
                    'presence_check' => $presence[$attendance->id] === 'filled' ? PresenceCheck::BoxFilled : PresenceCheck::BoxAppearsEmpty,
                    'presence_checked_at' => now(), 'checked_by' => $hr->id]);
            }
            $this->events->log($case, 'signed_recommendations_scan_recorded', ['document_id' => $document->id,
                'attachment_id' => $attachment->id, 'empty_attendee_ids' => $attendees->filter(fn ($attendance) => $presence[$attendance->id] === 'empty')->modelKeys()], actor: $hr);
            if (! in_array('empty', $presence, true)) {
                $this->workflow->transition($case, CaseStatus::SignedRecommendationsUploaded, $hr, 'All signature boxes appear filled');
                $this->notifyReviewers($case);
            }

            return $document;
        });
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
                throw new TransitionBlocked('An administrator reason and issued recommendations are required.');
            }
            $latestScan = $this->latestChecks($document);
            if ($latestScan->isEmpty() || ! $latestScan->contains(fn ($check) => $check->presence_check === PresenceCheck::BoxAppearsEmpty)) {
                throw new TransitionBlocked('A recorded empty signature box is required.');
            }
            $this->events->log($case, 'empty_signature_box_overridden', ['document_id' => $document->id, 'reason' => $reason], actor: $admin);
            app(AuditLogger::class)->log('empty_signature_box_overridden', $document, ['reason' => $reason], $admin);

            $case = $this->workflow->transition($case, CaseStatus::SignedRecommendationsUploaded, $admin, 'Empty signature box overridden');
            $this->notifyReviewers($case);

            return $case;
        });
    }

    public function latestChecks(Document $document): Collection
    {
        return Signature::query()->where('document_id', $document->id)
            ->where('purpose', SignaturePurpose::CommitteeSignature->value)->orderByDesc('id')->get()
            ->unique('committee_pool_member_id')->values();
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
