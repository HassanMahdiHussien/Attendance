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

## Plan review resolutions

- **Blocker — reporter visibility (§8):** The pre-submit check is visible only in the active SubmitReport form. Polling and attaching require the requesting user's id, company and a matching input snapshot. Once submitted, the reporter's existing reports page remains bare status; questions and answers are available only through the HR inbox and authorized case views.
- **Blocker — AI facts and data minimisation (§3, §3b, §20):** The intake prompt asks for missing information only. It receives exactly description, selected violation name, place and time after the existing payload minimisation rules; it receives no employee, reporter, witness or responsible-person fields. Returned question text is never treated as a fact. Only nonempty human answers enter the defense-letter payload; neither questions nor answers are printed directly in documents.
- **Blocker — queued result integrity:** Every request stores a snapshot hash and random token. The form checks ownership and its current snapshot before passing a completed check to attachment; an obsolete result cannot replace newer questions. Pending checks never block report submission or draft saving. Strict server-side validation rejects extra fields, invalid aspects, more than six questions, duplicates, blank or excessive text, and inconsistent `complete`. Invalid output follows the normal retry/fallback path. The fallback is local and does not depend on an API key.
- **Should fix — final state:** Use `checklist` as the terminal state after permanent or exhausted AI failure instead of leaving a separate `failed` state that the UI must turn into the same checklist. Clear the queued input snapshot after processing; retain its hash for stale-form detection.
- **Should fix — early submission:** If a person submits while a check is still pending, keep the submission nonblocking and do not attach that unreviewed result to the record. The user can wait for the result before submitting when they want to answer it; HR can run a fresh check on the case form afterward.
- **Should fix — parent and tenant integrity:** Question rows have exactly one source parent at creation (report, email draft or case) and retain that provenance on copying. Composite FKs bind every tenant parent, check, answerer and AI log to the same company. Service authorization uses existing report inbox, email inbox and case policy paths; the form never accepts arbitrary parent IDs.
- **Should fix — carry and editing semantics:** Conversion copies pairs in the same transaction, including duplicate links and email draft conversion. Case-form answers to existing open points are saved before conversion/opening. Rechecks remove only unanswered pairs for that same parent, never answered pairs. On the case page, only HR with update access may answer open points before defense-letter issuance. The answer action logs an audit entry or case event without recording answer content in metadata.
- **Should fix — fixed checklist and language:** Localized checklist wording appears if there is no key, a permanent failure, or exhausted retries. Questions use the requester's locale; answers stay as typed. The interface labels the checklist as prompts, not verified facts, and leaving any item open never blocks submission or opening.
- **Should fix — audit and throttling:** Count accepted checks per user over a rolling ten minutes in `intake_checks` rather than an in-memory rate limiter, so the limit remains correct across workers. Audit the request and each answer save; the Claude client records every attempted API call in `ai_generation_logs`. Store no sensitive input or answer text in audit metadata.
- **Should fix — UI and test gates:** Use the existing UI components, translated English/Arabic strings and explicit behaviour inventory additions. The requested Docker gates and browser walkthrough remain with the orchestrator because this environment has no Docker access.
