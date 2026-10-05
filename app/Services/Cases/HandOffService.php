<?php

namespace App\Services\Cases;

use App\Enums\CaseStatus;
use App\Enums\SignaturePurpose;
use App\Enums\SignatureStatus;
use App\Enums\UserRole;
use App\Exceptions\TransitionBlocked;
use App\Models\DisciplinaryCase;
use App\Models\Signature;
use App\Models\User;
use App\Services\CaseEventLogger;
use App\Support\Access\CaseVisibility;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class HandOffService
{
    public function __construct(private CaseWorkflow $workflow, private CaseEventLogger $events) {}

    public function record(DisciplinaryCase $case, SignaturePurpose $purpose, SignatureStatus $status, array $data, User $actor): Signature
    {
        return DB::transaction(function () use ($case, $purpose, $status, $data, $actor): Signature {
            $case = DisciplinaryCase::query()->lockForUpdate()->findOrFail($case->id);
            Gate::forUser($actor)->authorize('recordHandOff', $case);
            if (! in_array($purpose, [SignaturePurpose::DefenseLetterReceipt, SignaturePurpose::ResponseSubmission, SignaturePurpose::DecisionLetterReceipt], true)
                || ! in_array($actor->primary_role, [UserRole::CompanyAdmin, UserRole::HrStaff], true)
                || ! CaseVisibility::for($actor)->whereKey($case->id)->exists()) {
                throw new TransitionBlocked('HR access to a case hand-off is required.');
            }
            if ($status === SignatureStatus::Pending) {
                throw new TransitionBlocked('A completed hand-off status is required.');
            }
            if ($purpose === SignaturePurpose::ResponseSubmission && $case->status !== CaseStatus::AwaitingEmployeeResponse) {
                throw new TransitionBlocked('The response is not awaiting hand-off.');
            }
            if ($status === SignatureStatus::Signed && empty($data['signed_at'])) {
                throw new TransitionBlocked('A signature date is required.');
            }
            if (in_array($status, [SignatureStatus::RefusedToSign, SignatureStatus::DeliveryRefused], true)
                && (blank($data['witness_1_name'] ?? null) || blank($data['witness_2_name'] ?? null)
                    || trim($data['witness_1_name']) === trim($data['witness_2_name']))) {
                throw new TransitionBlocked('Two distinct witness names are required.');
            }
            if ($status === SignatureStatus::DeliveryRefused
                && (empty($data['attempted_at']) || blank($data['attempt_location'] ?? null) || blank($data['attempted_by_name'] ?? null))) {
                throw new TransitionBlocked('Date, place and attempting party are required.');
            }
            $signature = Signature::create(array_intersect_key($data, array_flip([
                'document_id', 'signer_name', 'signer_role', 'witness_1_name', 'witness_2_name',
                'attempted_at', 'attempt_location', 'attempted_by_name', 'signed_at',
            ])) + [
                'company_id' => $case->company_id, 'case_id' => $case->id, 'employee_id' => $case->employee_id,
                'purpose' => $purpose, 'status' => $status,
                'signer_name' => $data['signer_name'] ?? $case->employee->name,
                'signer_role' => $data['signer_role'] ?? 'employee',
            ]);
            $this->events->log($case, $purpose === SignaturePurpose::ResponseSubmission ? 'response_handoff_recorded' : 'hand_off_recorded', [
                'signature_id' => $signature->id, 'purpose' => $purpose->value, 'status' => $status->value,
            ], actor: $actor);
            if ($purpose === SignaturePurpose::ResponseSubmission) {
                $target = match ($status) {
                    SignatureStatus::Signed => CaseStatus::ResponseReceived,
                    SignatureStatus::RefusedToSign => CaseStatus::ResponseRefusedToSign,
                    SignatureStatus::DeliveryRefused => CaseStatus::ResponseDeliveryRefused,
                    default => throw new TransitionBlocked('Invalid hand-off status.'),
                };
                $this->workflow->transition($case, $target, $actor, 'Employee response hand-off recorded');
            }

            return $signature;
        });
    }
}
