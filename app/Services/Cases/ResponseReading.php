<?php

namespace App\Services\Cases;

use App\Ai\ScanBlocks;
use App\Enums\AttachmentKind;
use App\Enums\CaseStatus;
use App\Enums\UserRole;
use App\Exceptions\TransitionBlocked;
use App\Jobs\TranscribeResponseScan;
use App\Models\Attachment;
use App\Models\DisciplinaryCase;
use App\Models\ResponseTranscription;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CaseEventLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class ResponseReading
{
    public function __construct(private CaseWorkflow $workflow, private CaseEventLogger $events, private AuditLogger $audit) {}

    public function latestScan(DisciplinaryCase $case): ?Attachment
    {
        return Attachment::query()->where('attachable_type', $case->getMorphClass())->where('attachable_id', $case->id)
            ->where('kind', AttachmentKind::EmployeeResponseScan->value)->orderByDesc('id')->first();
    }

    public function request(DisciplinaryCase $case, User $actor, bool $noSensitiveData): void
    {
        DB::transaction(function () use ($case, $actor, $noSensitiveData): void {
            $locked = DisciplinaryCase::query()->lockForUpdate()->findOrFail($case->id);
            $this->authorize($locked, $actor);
            $scan = $this->latestScan($locked);
            if (! $noSensitiveData || ! $scan || $locked->status !== CaseStatus::ResponseScannedUploaded || $locked->ai_request_id !== null) {
                throw new TransitionBlocked('تأكد من خلو الصورة من رقم الهوية والراتب ورقم الهاتف قبل القراءة.');
            }
            app(ScanBlocks::class)->for($scan);
            $token = (string) Str::uuid();
            $locked->forceFill(['ai_request_id' => $token, 'ai_request_document_type' => 'response_transcription',
                'ai_request_from_status' => null, 'ai_request_attachment_id' => $scan->id, 'ai_request_started_at' => now()])->save();
            $this->events->log($locked, 'response_reading_requested', ['attachment_id' => $scan->id, 'no_sensitive_data_confirmed' => true], actor: $actor);
            $this->audit->log('response_reading_requested', $locked, ['attachment_id' => $scan->id, 'no_sensitive_data_confirmed' => true], $actor);
            DB::afterCommit(fn () => TranscribeResponseScan::dispatch($locked->id, $locked->company_id, $actor->id, $scan->id, $token));
        });
    }

    public function confirm(DisciplinaryCase $case, string $text, string $origin, User $actor): void
    {
        DB::transaction(function () use ($case, $text, $origin, $actor): void {
            $locked = DisciplinaryCase::query()->lockForUpdate()->findOrFail($case->id);
            $this->authorize($locked, $actor);
            $scan = $this->latestScan($locked);
            if ($locked->status !== CaseStatus::ResponseScannedUploaded || ! $scan || ! in_array($origin, ['ai_checked', 'human'], true) || blank($text)) {
                throw new TransitionBlocked('يلزم نص الرد والمسح قبل التأكيد.');
            }
            if ($origin === 'ai_checked' && ! ResponseTranscription::query()->where('case_id', $locked->id)
                ->where('attachment_id', $scan->id)->where('origin', 'ai')->exists()) {
                throw new TransitionBlocked('لا توجد محاولة قراءة لهذا المسح.');
            }
            $this->supersede($locked, $actor);
            $transcription = ResponseTranscription::create(['company_id' => $locked->company_id, 'case_id' => $locked->id,
                'attachment_id' => $scan->id, 'origin' => $origin, 'text' => $text, 'created_by' => $actor->id, 'created_at' => now()]);
            $locked->forceFill(['employee_response_text' => $text, 'employee_response_checked_by' => $actor->id,
                'employee_response_checked_at' => now(), 'employee_response_origin' => $origin])->save();
            $this->workflow->transition($locked, CaseStatus::AiIngestedResponse, $actor, 'Response text confirmed');
            $this->events->log($locked, 'response_transcription_confirmed', ['transcription_id' => $transcription->id, 'origin' => $origin], actor: $actor);
            $this->audit->log('response_transcription_confirmed', $locked, ['transcription_id' => $transcription->id], $actor);
        });
    }

    public function correct(DisciplinaryCase $case, string $reason, User $actor): void
    {
        DB::transaction(function () use ($case, $reason, $actor): void {
            $locked = DisciplinaryCase::query()->lockForUpdate()->findOrFail($case->id);
            $this->authorize($locked, $actor);
            if ($locked->status !== CaseStatus::AiIngestedResponse || blank($reason)) {
                throw new TransitionBlocked('يلزم سبب تصحيح النص.');
            }
            $previous = ResponseTranscription::query()->where('case_id', $locked->id)->whereIn('origin', ['ai_checked', 'human'])->orderByDesc('id')->first();
            $locked->forceFill(['employee_response_text' => null, 'employee_response_checked_by' => null,
                'employee_response_checked_at' => null, 'employee_response_origin' => null])->save();
            $this->workflow->transition($locked, CaseStatus::ResponseScannedUploaded, $actor, 'Response text correction');
            $this->events->log($locked, 'response_transcription_correction', ['reason' => $reason, 'previous_transcription_id' => $previous?->id], actor: $actor);
            $this->audit->log('response_transcription_correction', $locked, ['reason' => $reason], $actor);
        });
    }

    private function authorize(DisciplinaryCase $case, User $actor): void
    {
        Gate::forUser($actor)->authorize('uploadAttachment', $case);
        abort_unless(in_array($actor->primary_role, [UserRole::CompanyAdmin, UserRole::HrStaff], true), 403);
    }

    private function supersede(DisciplinaryCase $case, User $actor): void
    {
        if ($case->ai_request_id === null) {
            return;
        }
        $this->events->log($case, 'ai_request_superseded', ['operation' => $case->ai_request_document_type], actor: $actor);
        $case->forceFill(['ai_request_id' => null, 'ai_request_document_type' => null,
            'ai_request_from_status' => null, 'ai_request_attachment_id' => null, 'ai_request_started_at' => null])->save();
    }
}
