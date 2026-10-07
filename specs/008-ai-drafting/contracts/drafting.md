# Contracts: AI Drafting

## Services
- `AiDrafting::request(DisciplinaryCase $case, DocumentType $type, User $actor): void` — policy: the user may
  edit that document today (existing editor policies). Preconditions per type (research R6). Moves the case into
  the AI drafting state, dispatches `DraftDocumentWithAi` after commit, audit `ai.draft_requested`.
- `DraftDocumentWithAi::handle()` — builds payload + candidates, calls the model, validates, saves through
  `DocumentDrafts::createOrUpdate` / `RecommendationsEditor::create|update` / `DocumentDrafts::createOrUpdateDecision`
  with `drafted_by = ai`, syncs citations, stores notes, returns the case to its previous state. On final failure:
  previous state + case event `ai_draft_failed` with a user-facing reason.
- `CitationSync::sync(Document $draft): void` — markers → citation rows (R8); refuses ids not citable for the case.
- `RelevanceCheck::run(Document $draft): void` (queued) and `RelevanceCheck::clear(Document $draft)`.
- `CitationOverride::override(Citation $citation, User $actor, string $reason): Citation`.
- `CitationRefresh::replace(Document $draft, LegalUnit $old, User $actor): void`.
- `CandidateUnits::for(DisciplinaryCase $case, string $context): Collection` (≤ max_candidates).
- `ClaudeClient::structured(string $touchpoint, array $schema, array $messages, array $logContext, string $model,
  string $effort): array` — logging, exception mapping, AiUnavailable without key.

## Screens
| screen | additions |
|---|---|
| EditDocument (defense letter) | AI button (case open), polling, unavailable banner, notes panel, "إدراج مادة" |
| EditRecommendations | AI button (minutes + majority recorded), same additions |
| EditDecisionLetter | AI button (signed recommendations uploaded, penalty), same additions |
| ConfirmDocument | relevance badge + reason per citation, re-check, override with reason, superseded replacement |
| Platform LawResource | `name_en` field; units: topic tags (discipline + free text) |

## Case events (timeline, Arabic labels)
`ai_draft_requested` طُلبت صياغة بالذكاء الاصطناعي · `ai_draft_saved` حُفظت مسودة الذكاء الاصطناعي ·
`ai_draft_failed` تعذرت الصياغة بالذكاء الاصطناعي · `citation_relevance_checked` فُحصت ملاءمة الاستشهادات ·
`citation_overridden` تجاوز ملاحظة استشهاد.
