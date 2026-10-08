<?php

namespace App\Services\Documents;

use App\Enums\DocumentType;
use App\Exceptions\IssuanceBlocked;
use App\Models\Document;
use App\Models\LegalUnit;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Cases\RecommendationsEditor;
use Illuminate\Support\Facades\DB;

class CitationRefresh
{
    public function __construct(private DocumentDrafts $drafts, private RecommendationsEditor $recommendations, private AuditLogger $audit) {}

    public function replace(Document $draft, LegalUnit $old, User $actor): void
    {
        DB::transaction(function () use ($draft, $old, $actor): void {
            $draft = Document::query()->lockForUpdate()->findOrFail($draft->id);
            if ($draft->is_locked) {
                throw new IssuanceBlocked(__('messages.citation.current_version_required'));
            }
            $ids = $draft->contents->flatMap(fn ($content) => collect(CitationSync::claims(
                $content->body."\n".($content->secondary_body ?? '')))->pluck('legal_unit_id'))->unique();
            if (! $ids->contains($old->id)) {
                throw new IssuanceBlocked(__('messages.citation.not_cited'));
            }
            $replacements = [];
            foreach ($ids as $id) {
                $unit = LegalUnit::findOrFail($id);
                if ($unit->superseded_by_id === null) {
                    continue;
                }
                $seen = [$unit->id];
                $new = $unit->supersededBy;
                while ($new && $new->superseded_by_id !== null && ! in_array($new->id, $seen, true)) {
                    $seen[] = $new->id;
                    $new = $new->supersededBy;
                }
                if (! $new || ! LegalUnit::query()->citableFor($draft->disciplinaryCase)->whereKey($new->id)->exists()) {
                    throw new IssuanceBlocked(__('messages.citation.current_version_required'));
                }
                $replacements['[[cite:'.$id.']]'] = '[[cite:'.$new->id.']]';
            }
            if (! isset($replacements['[[cite:'.$old->id.']]'])) {
                throw new IssuanceBlocked(__('messages.citation.already_current'));
            }
            $contents = $draft->contents->mapWithKeys(function ($content) use ($replacements): array {
                $replace = fn (?string $body) => $body === null ? null : strtr($body, $replacements);

                return [$content->language_code => ['body' => $replace($content->body), 'secondary_body' => $replace($content->secondary_body)]];
            })->all();
            if ($draft->document_type === DocumentType::Recommendations) {
                $this->recommendations->update($draft, $contents, $actor);
            } elseif ($draft->document_type === DocumentType::DefenseLetter) {
                $this->drafts->createOrUpdate($draft->disciplinaryCase, DocumentType::DefenseLetter,
                    collect($contents)->mapWithKeys(fn ($content, $language) => [$language => $content['body']])->all(), $actor, $draft);
            } else {
                $this->drafts->createOrUpdateDecision($draft->disciplinaryCase,
                    collect($contents)->mapWithKeys(fn ($content, $language) => [$language => $content['body']])->all(), $actor, $draft);
            }
            $this->audit->log('citation.refreshed', $draft, ['replacements' => $replacements], $actor);
        });
    }
}
