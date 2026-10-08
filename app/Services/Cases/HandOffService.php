<?php

namespace App\Services\Cases;

use App\Enums\AttachmentKind;
use App\Enums\CaseStatus;
use App\Enums\DocumentType;
use App\Enums\SignaturePurpose;
use App\Enums\SignatureStatus;
use App\Enums\UserRole;
use App\Exceptions\TransitionBlocked;
use App\Models\Attachment;
use App\Models\DisciplinaryCase;
use App\Models\Signature;
use App\Models\User;
use App\Services\Attachments\AttachmentService;
use App\Services\CaseEventLogger;
use App\Support\Access\CaseVisibility;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class HandOffService
{
    public function __construct(private CaseWorkflow $workflow, private CaseEventLogger $events, private AttachmentService $attachments) {}

    public function record(DisciplinaryCase $case, SignaturePurpose $purpose, SignatureStatus $status, array $data, User $actor): Signature
    {
        $stored = null;
        try {
            return DB::transaction(function () use ($case, $purpose, $status, $data, $actor, &$stored): Signature {
                $case = DisciplinaryCase::query()->lockForUpdate()->findOrFail($case->id);
                Gate::forUser($actor)->authorize('recordHandOff', $case);
                if (! in_array($purpose, [SignaturePurpose::DefenseLetterReceipt, SignaturePurpose::ResponseSubmission, SignaturePurpose::DecisionLetterReceipt], true)
                    || ! in_array($actor->primary_role, [UserRole::CompanyAdmin, UserRole::HrStaff], true)
                    || ! CaseVisibility::for($actor)->whereKey($case->id)->exists()) {
                    throw new TransitionBlocked('يلزم امتلاك صلاحية الموارد البشرية لتسجيل التسليم الورقي.');
                }
                if ($status === SignatureStatus::Pending) {
                    throw new TransitionBlocked('يلزم تحديد نتيجة التسليم الورقي.');
                }
                if ($purpose === SignaturePurpose::ResponseSubmission && $case->status !== CaseStatus::AwaitingEmployeeResponse) {
                    throw new TransitionBlocked('الرد ليس في مرحلة التسليم الورقي.');
                }
                if ($purpose === SignaturePurpose::DefenseLetterReceipt && $case->status !== CaseStatus::AwaitingEmployeeResponse) {
                    throw new TransitionBlocked('كتاب الدفاع ليس في مرحلة التسليم الورقي.');
                }
                if ($purpose === SignaturePurpose::DecisionLetterReceipt
                    && ! in_array($case->status, [CaseStatus::AwaitingAppealWindow, CaseStatus::AppealPending], true)) {
                    throw new TransitionBlocked('يلزم إصدار كتاب القرار قبل تسجيل استلامه.');
                }
                if ($purpose === SignaturePurpose::DecisionLetterReceipt) {
                    $issued = $case->documents()->where('document_type', DocumentType::DecisionLetter->value)
                        ->where('status', 'issued')->orderByDesc('version')->first();
                    if (! $issued || isset($data['document_id']) && (int) $data['document_id'] !== $issued->id) {
                        throw new TransitionBlocked('يجب أن يشير الاستلام إلى كتاب القرار الصادر الحالي.');
                    }
                    $data['document_id'] = $issued->id;
                }
                if ($status === SignatureStatus::Signed && empty($data['signed_at'])) {
                    throw new TransitionBlocked('يلزم تحديد تاريخ التوقيع.');
                }
                if (in_array($status, [SignatureStatus::RefusedToSign, SignatureStatus::DeliveryRefused], true)
                    && (blank($data['witness_1_name'] ?? null) || blank($data['witness_2_name'] ?? null)
                        || trim($data['witness_1_name']) === trim($data['witness_2_name']))) {
                    throw new TransitionBlocked('يلزم إدخال اسمي شاهدين مختلفين.');
                }
                if (in_array($status, [SignatureStatus::RefusedToSign, SignatureStatus::DeliveryRefused], true)
                    && ! (($data['witness_scan'] ?? null) instanceof UploadedFile)) {
                    throw new TransitionBlocked('يجب رفع مسح الورقة الموقعة من الشاهدين عند رفض التسليم أو التوقيع.');
                }
                if ($status === SignatureStatus::DeliveryRefused
                    && (empty($data['attempted_at']) || blank($data['attempt_location'] ?? null) || blank($data['attempted_by_name'] ?? null))) {
                    throw new TransitionBlocked('يلزم تاريخ المحاولة ومكانها واسم القائم بها.');
                }
                if ($status !== SignatureStatus::Signed) {
                    $stored = $this->attachments->store($case, AttachmentKind::DeliveryProof, $data['witness_scan'], $actor);
                }
                $signature = Signature::create(array_intersect_key($data, array_flip([
                    'document_id', 'signer_name', 'signer_role', 'witness_1_name', 'witness_2_name',
                    'attempted_at', 'attempt_location', 'attempted_by_name', 'signed_at',
                ])) + [
                    'company_id' => $case->company_id, 'case_id' => $case->id, 'employee_id' => $case->employee_id,
                    'purpose' => $purpose, 'status' => $status,
                    'signer_name' => $data['signer_name'] ?? $case->employee->name,
                    'signer_role' => $data['signer_role'] ?? 'employee',
                    'witness_scan_attachment_id' => $stored?->id,
                ]);
                $this->events->log($case, $purpose === SignaturePurpose::ResponseSubmission ? 'response_handoff_recorded' : 'hand_off_recorded', [
                    'signature_id' => $signature->id, 'purpose' => $purpose->value, 'status' => $status->value,
                    'witness_scan_attachment_id' => $stored?->id,
                ], actor: $actor);
                if ($purpose === SignaturePurpose::ResponseSubmission) {
                    $target = match ($status) {
                        SignatureStatus::Signed => CaseStatus::ResponseReceived,
                        SignatureStatus::RefusedToSign => CaseStatus::ResponseRefusedToSign,
                        SignatureStatus::DeliveryRefused => CaseStatus::ResponseDeliveryRefused,
                        default => throw new TransitionBlocked('نتيجة التسليم الورقي غير صحيحة.'),
                    };
                    $this->workflow->transition($case, $target, $actor, 'Employee response hand-off recorded');
                }

                return $signature;
            });
        } catch (\Throwable $error) {
            if ($stored !== null && ! Attachment::query()->where('path', $stored->path)->exists()) {
                Storage::disk($stored->disk)->delete($stored->path);
            }
            throw $error;
        }
    }
}
