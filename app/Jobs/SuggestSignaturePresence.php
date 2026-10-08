<?php

namespace App\Jobs;

use App\Ai\ClaudeClient;
use App\Ai\InvalidAiOutput;
use App\Ai\PermanentAiFailure;
use App\Ai\ScanBlocks;
use App\Enums\CaseStatus;
use App\Models\Attachment;
use App\Models\CommitteeSessionAttendance;
use App\Models\DisciplinaryCase;
use App\Models\Document;
use App\Models\SignaturePresenceSuggestion;
use App\Services\CaseEventLogger;
use App\Services\Cases\SignaturePresence;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class SuggestSignaturePresence implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public array $backoff = [30, 120];

    public function __construct(public int $caseId, public int $companyId, public int $actorId,
        public int $documentId, public int $attachmentId, public string $token) {}

    public function handle(ClaudeClient $claude, ScanBlocks $blocks, SignaturePresence $presence): void
    {
        app(TenantContext::class)->runForCompany($this->companyId, function () use ($claude, $blocks, $presence): void {
            $case = DisciplinaryCase::find($this->caseId);
            if (! $case || ! $this->matches($case, $presence)) {
                return;
            }
            $attendees = CommitteeSessionAttendance::query()->where('case_id', $case->id)->with('committeePoolMember')->get();
            $printed = $attendees->map(fn ($row) => ['attendance_id' => $row->id,
                'name' => $row->committeePoolMember->person_name, 'role' => $row->committeePoolMember->role_type->value])->all();
            try {
                $response = $claude->structured('signature_presence', ['type' => 'object', 'properties' => [
                    'results' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                        'attendance_id' => ['type' => 'integer'], 'result' => ['type' => 'string', 'enum' => ['appears_filled', 'appears_empty', 'unclear']],
                        'reason' => ['type' => 'string']],
                        'required' => ['attendance_id', 'result', 'reason'], 'additionalProperties' => false]]],
                    'required' => ['results'], 'additionalProperties' => false],
                    [['role' => 'user', 'content' => [$blocks->for(Attachment::findOrFail($this->attachmentId)),
                        ['type' => 'text', 'text' => 'For each printed attendee, say only whether their signature box appears filled, appears empty, or is unclear. Never identify or authenticate a signer. Return one short reason per row. Attendees: '.json_encode($printed, JSON_UNESCAPED_UNICODE)]]]],
                    ['company_id' => $case->company_id, 'case_id' => $case->id, 'document_id' => $this->documentId,
                        'actor_user_id' => $this->actorId, 'attachment_id' => $this->attachmentId],
                    (string) config('displanery.ai.reading_model'), 'medium');
            } catch (PermanentAiFailure $exception) {
                $this->failed($exception);
                $this->fail($exception);

                return;
            }
            if (! is_array($response['results'] ?? null)) {
                throw new InvalidAiOutput('Invalid presence response.');
            }
            $results = [];
            foreach ($attendees as $attendance) {
                $results[$attendance->id] = ['result' => 'unclear', 'reason' => 'messages.ai.signature_box_unclear'];
            }
            foreach ($response['results'] as $item) {
                if (! is_array($item)) {
                    continue;
                }
                if (isset($results[$item['attendance_id'] ?? null]) && in_array($item['result'] ?? null, ['appears_filled', 'appears_empty', 'unclear'], true)
                    && is_string($item['reason'] ?? null)) {
                    $results[$item['attendance_id']] = ['result' => $item['result'], 'reason' => mb_substr($item['reason'], 0, 120)];
                }
            }
            DB::transaction(function () use ($results, $response, $presence): void {
                $locked = DisciplinaryCase::query()->lockForUpdate()->find($this->caseId);
                if (! $locked || ! $this->matches($locked, $presence)) {
                    return;
                }
                SignaturePresenceSuggestion::create(['company_id' => $locked->company_id, 'case_id' => $locked->id,
                    'document_id' => $this->documentId, 'attachment_id' => $this->attachmentId,
                    'results' => $results, 'ai_generation_log_id' => $response['_generation_log_id'], 'created_at' => now()]);
                $this->clear($locked);
                app(CaseEventLogger::class)->log($locked, 'signature_presence_ready', ['attachment_id' => $this->attachmentId]);
            });
        });
    }

    public function failed(?Throwable $exception): void
    {
        app(TenantContext::class)->runForCompany($this->companyId, function (): void {
            DB::transaction(function (): void {
                $case = DisciplinaryCase::query()->lockForUpdate()->find($this->caseId);
                if (! $case || ! $this->matches($case, app(SignaturePresence::class))) {
                    return;
                }
                $this->clear($case);
                app(CaseEventLogger::class)->log($case, 'signature_presence_failed', ['attachment_id' => $this->attachmentId]);
            });
        });
    }

    private function matches(DisciplinaryCase $case, SignaturePresence $presence): bool
    {
        $document = Document::find($this->documentId);

        return $document && $document->case_id === $case->id && $case->status === CaseStatus::AwaitingCommitteeSignatures
            && $case->ai_request_id === $this->token && $case->ai_request_document_type === 'signature_presence'
            && (int) $case->ai_request_attachment_id === $this->attachmentId
            && $presence->latestScan($document)?->id === $this->attachmentId;
    }

    private function clear(DisciplinaryCase $case): void
    {
        $case->forceFill(['ai_request_id' => null, 'ai_request_document_type' => null,
            'ai_request_from_status' => null, 'ai_request_attachment_id' => null, 'ai_request_started_at' => null])->save();
    }
}
