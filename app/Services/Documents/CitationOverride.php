<?php

namespace App\Services\Documents;

use App\Models\Citation;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CaseEventLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CitationOverride
{
    public function __construct(private AuditLogger $audit, private CaseEventLogger $events) {}

    public function override(Citation $citation, User $actor, string $reason): Citation
    {
        if (blank(trim($reason))) {
            throw ValidationException::withMessages(['reason' => __('messages.citation.override_reason_required')]);
        }

        return DB::transaction(function () use ($citation, $actor, $reason): Citation {
            $citation = Citation::query()->lockForUpdate()->findOrFail($citation->id);
            $document = $citation->document;
            Gate::forUser($actor)->authorize('confirm', $document->disciplinaryCase);
            if ($document->is_locked || $actor->company_id !== $document->company_id) {
                abort(403);
            }
            $flagged = $citation->relevance_check?->value;
            $citation->forceFill(['overridden' => true, 'overridden_by' => $actor->id,
                'overridden_at' => now(), 'override_reason' => trim($reason)])->save();
            $this->audit->log('citation.overridden', $citation, ['flagged_result' => $flagged, 'reason' => trim($reason)], $actor);
            $this->events->log($document->disciplinaryCase, 'citation_overridden', ['citation_id' => $citation->id], actor: $actor);

            return $citation->refresh();
        });
    }
}
