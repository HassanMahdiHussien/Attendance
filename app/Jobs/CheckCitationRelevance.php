<?php

namespace App\Jobs;

use App\Ai\AiUnavailable;
use App\Ai\ClaudeClient;
use App\Documents\CitationRenderer;
use App\Models\Citation;
use App\Models\Document;
use App\Services\AuditLogger;
use App\Services\CaseEventLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class CheckCitationRelevance implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(public int $documentId, public int $companyId) {}

    public function handle(ClaudeClient $claude, CitationRenderer $renderer, AuditLogger $audit, CaseEventLogger $events): void
    {
        app(TenantContext::class)->runForCompany($this->companyId, function () use ($claude, $renderer, $audit, $events): void {
            $document = Document::find($this->documentId);
            if (! $document || $document->is_locked) {
                return;
            }
            foreach ($document->citations()->whereNull('relevance_check')->where('overridden', false)->with('legalUnit')->get() as $citation) {
                try {
                    $claim = $renderer->replace($document, $citation->claim_sentence, 'ar');
                    $result = $claude->structured('citation_relevance', [
                        'type' => 'object', 'properties' => ['result' => ['type' => 'string', 'enum' => ['supports', 'partial', 'does_not_support']],
                            'reason' => ['type' => 'string']], 'required' => ['result', 'reason'], 'additionalProperties' => false,
                    ], [['role' => 'user', 'content' => 'Judge whether this official legal text supports this claim. Reply with result and a reason of one sentence (at most 30 words). Claim: '
                        .$claim."\nOfficial text: ".$citation->legalUnit->official_text]],
                        ['company_id' => $document->company_id, 'case_id' => $document->case_id, 'document_id' => $document->id],
                        (string) config('displanery.ai.relevance_model'), 'medium');
                    if (! in_array($result['result'] ?? null, ['supports', 'partial', 'does_not_support'], true)
                        || ! is_string($result['reason'] ?? null) || blank($result['reason'])) {
                        continue;
                    }
                    // Real reasons ran 300-450 characters and discarding them dropped every verdict, so keep
                    // the verdict and shorten only the reviewer-facing reason.
                    $result['reason'] = Str::limit(trim($result['reason']), 500);
                    DB::transaction(function () use ($citation, $document, $result, $audit, $events): void {
                        $currentDocument = Document::query()->lockForUpdate()->find($document->id);
                        $current = Citation::query()->lockForUpdate()->find($citation->id);
                        if (! $currentDocument || $currentDocument->is_locked || ! $current
                            || $current->relevance_check !== null || $current->overridden
                            || $current->claim_hash !== $citation->claim_hash
                            || $current->claim_sentence !== $citation->claim_sentence) {
                            return;
                        }
                        $current->forceFill(['relevance_check' => $result['result'], 'relevance_reason' => $result['reason']])->save();
                        $audit->log('citation.relevance_checked', $current, ['result' => $result['result']]);
                        $events->log($currentDocument->disciplinaryCase, 'citation_relevance_checked', ['citation_id' => $current->id]);
                    });
                } catch (AiUnavailable $exception) {
                    return;
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
        });
    }
}
