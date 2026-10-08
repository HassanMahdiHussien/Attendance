<?php

namespace App\Services\Cases;

use App\Enums\AttachmentKind;
use App\Enums\CaseStatus;
use App\Enums\SignaturePurpose;
use App\Enums\SignatureStatus;
use App\Exceptions\TransitionBlocked;
use App\Models\Attachment;
use App\Models\DisciplinaryCase;
use App\Models\Signature;
use App\Models\User;
use App\Services\Attachments\AttachmentService;
use App\Services\CaseEventLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class ResponseService
{
    public function __construct(private HandOffService $handOffs, private AttachmentService $attachments,
        private CaseWorkflow $workflow, private Deadlines $deadlines, private CaseEventLogger $events) {}

    public function recordHandOff(DisciplinaryCase $case, SignatureStatus $status, array $data, User $actor): Signature
    {
        return $this->handOffs->record($case, SignaturePurpose::ResponseSubmission, $status, $data, $actor);
    }

    public function attachScan(DisciplinaryCase $case, UploadedFile $file, User $actor): Attachment
    {
        $stored = null;
        try {
            return DB::transaction(function () use ($case, $file, $actor, &$stored): Attachment {
                $case = DisciplinaryCase::query()->lockForUpdate()->findOrFail($case->id);
                Gate::forUser($actor)->authorize('uploadAttachment', $case);
                if (! in_array($case->status, [CaseStatus::AwaitingEmployeeResponse, CaseStatus::ResponseReceived,
                    CaseStatus::ResponseRefusedToSign, CaseStatus::ResponseDeliveryRefused], true)) {
                    throw new TransitionBlocked(__('messages.case.changed_reload_period'));
                }
                $attachment = $this->attachments->store($case, AttachmentKind::EmployeeResponseScan, $file, $actor);
                $stored = $attachment;
                $this->workflow->transition($case, CaseStatus::ResponseScannedUploaded, $actor, 'Employee response scan uploaded');
                $this->events->log($case, 'response_scan_uploaded', ['attachment_id' => $attachment->id], actor: $actor);

                return $attachment;
            });
        } catch (\Throwable $error) {
            if ($stored !== null && ! Attachment::query()->where('path', $stored->path)->exists()) {
                Storage::disk($stored->disk)->delete($stored->path);
            }
            throw $error;
        }
    }

    public function markNoResponse(DisciplinaryCase $case, User $actor): void
    {
        DB::transaction(function () use ($case, $actor): void {
            $case = DisciplinaryCase::query()->lockForUpdate()->findOrFail($case->id);
            Gate::forUser($actor)->authorize('recordHandOff', $case);
            $due = $this->deadlines->responseDue($case);
            if ($case->status !== CaseStatus::AwaitingEmployeeResponse || $due === null
                || now('Asia/Baghdad')->startOfDay()->lte($due)
                || $case->events()->where('event_type', 'response_not_received')->exists()) {
                throw new TransitionBlocked(__('messages.response.no_response_after_deadline_only'));
            }
            $this->events->log($case, 'response_not_received', ['due_date' => $due->toDateString()], actor: $actor);
        });
    }
}
