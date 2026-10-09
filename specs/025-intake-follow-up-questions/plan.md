# Implementation Plan: AI Follow-Up Questions at Intake

**Branch**: `025-intake-follow-up-questions` | **Spec**: [spec.md](spec.md)

## Design
- **Data**: migration `intake_checks` (company_id, user_id, status pending|ready|failed|checklist, language,
  input_hash, request_token, result JSON, error, timestamps) — one row per press of "Check my description", so the
  queued job has something to write to before a report/case exists. `intake_questions` (company_id,
  violation_report_id nullable, email_draft_id nullable, case_id nullable, intake_check_id nullable, source
  ai|checklist, aspect, question, answer nullable, answered_by_user_id nullable, answered_at nullable, sort_order,
  timestamps); composite same-company FKs; at least one parent set on save. Models + factories + CompositeForeignKeyTest
  map entries.
- **AI**: `App\Ai\IntakeQuestions` builds the minimal payload (description, violation name via `Violation` helper /
  selected type, place, time) and the prompt (FR-002, language); `App\Jobs\SuggestIntakeQuestions` (pattern:
  `SuggestSignaturePresence` — tries 3, backoff, `ClaudeClient::structured('intake_questions', schema, …)`, logs via
  the client) writes `result` or `failed`. Model: `config('displanery.ai.reading_model')`, effort low. No API key /
  `AiUnavailable` / final failure → status `checklist` with the fixed checklist (translated keys).
- **Service** `App\Services\Intake\IntakeQuestionService`: `start(User, array $fields): IntakeCheck` (rate limit
  5/10 min via `RateLimiter`, audit log), `questionsFor(IntakeCheck)`, `attachToReport/attachToCase/attachToEmailDraft`
  (persist answered + open pairs), `answer(IntakeQuestion, string, User)` (case event when a case exists, else audit),
  `carryToCase(ViolationReport|EmailDraft, DisciplinaryCase)` used by `ReportService::convert` / `CaseDrafts` /
  `CaseOpening`.
- **UI**: a shared Livewire concern `ChecksDescription` (properties `intakeCheckId`, `intakeAnswers[]`;
  actions `checkDescription()`, poll while pending) used by `SubmitReport` and `CreateCase`; a Blade partial under
  the description field listing questions with answer boxes, a "looks complete" state and the checklist state.
  Inbox report view + case page: read-only answered pairs and open points; HR can answer open points on the case
  page (before the defense letter is issued) and on the case form.
- **Touchpoint 1**: `PayloadBuilder` adds `intake_answers: [{aspect, question, answer}]` (answered only) for the
  defense letter; `DraftPrompt` notes they are human-entered facts.

## Tests (FakeClaudeClient)
- Short description → questions stored; schema violation rejected; >6 questions rejected.
- No key / unavailable → checklist, submission works.
- Report submit with partial answers → open points; conversion carries pairs to case; case page + inbox show them.
- Payload includes answered pairs only, nothing else new; no national ID/phone/salary in the input.
- Tenant isolation (composite FKs); reporter cannot see another reporter's check; rate limit.
- Translations, behaviour inventory, Pint, full suite.
