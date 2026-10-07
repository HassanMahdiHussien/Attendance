# Research: AI Drafting with Verified Citations

## R1 Calling the model
- Official PHP SDK `anthropic-ai/sdk` (installed in 007). One shared client factory `App\Ai\ClaudeClient`
  (key from `services.anthropic.key`; empty → `AiUnavailable` without a request), used by drafting, the relevance
  check and (later) 009. Model from config: `displanery.ai.drafting_model` and `displanery.ai.relevance_model`,
  default `claude-opus-5-5`; `outputConfig` effort `high` for drafting, `medium` for the relevance check; no
  thinking/temperature/budget parameters (rejected on this model); `maxTokens` 16000 non-streaming.
- Structured output via `outputConfig.format` JSON schema (as in 007's page reader). Refusal (`stop_reason =
  refusal`) and `max_tokens` stop reasons are treated as validation failures (retried, then unavailable).
- Retries: job-level 2 retries with backoff (30 s, 120 s) for retryable SDK errors and validation failures; SDK's
  own retries stay at the default. Permanent errors (400/401/403/404) fail immediately → unavailable.
- Every request writes `ai_generation_logs` (touchpoint `draft_defense_letter` / `draft_recommendations` /
  `draft_decision_letter` / `citation_relevance`, company, case, document, actor, model, input JSON, output,
  status, error, tokens, duration).

## R2 What the model receives (data minimisation, CLAUDE.md §20)
- Defense letter: violation type, place (location name or free text), date/time, description, alleged responsible
  person as entered, the employee's position and department (wording context), prior-record summaries only when
  `include_prior_record` (type, date, outcome — no names), candidate units (id, law name, article, clause,
  official text).
- Recommendations: the typed minutes, recorded outcome, defense acceptance, majority result, the incident fields
  above, candidate units. No attendee or employee names (signature lines and names are template placeholders).
- Decision letter: the issued recommendations bodies (findings + recommendations, Arabic and English), outcome,
  penalty type, defense acceptance, candidate units.
- Never: employee name, employee code, national ID, salary, phone, email, address, attachments. A payload builder
  per document type assembles an explicit allow-list; tests assert forbidden fields are absent.

## R3 Candidate units (CLAUDE.md §23.3 step 1)
- Base set: `LegalUnit::citableFor($case)` (007) — the case's pinned labour law, other applicable statutes, the
  company's approved regulation units.
- Ranking: units whose `tags.topics` contains `discipline` (Platform Admin tags them in the library) first, then
  keyword overlap between the normalised violation type + description and the unit's normalised text (token
  overlap score, Arabic stop words removed), capped at `displanery.ai.max_candidates` (30). Regulation units are
  included with their law name so the AI can cite both (law first, CLAUDE.md §23.4; conflict view stays out of
  scope).
- If the base set is empty → draft without citations and add the note "لا توجد مواد موثقة قابلة للاستشهاد".

## R4 Output contract and validation
- JSON schema: `{ "languages": { "<code>": { "body": string, "secondary_body"?: string } }, "notes": [string] }`
  — `secondary_body` only for recommendations (findings → body, recommendations → secondary_body, as 004).
- Validation (deterministic, `DraftValidator`): every enabled language present and non-empty; markers only of the
  form `[[cite:<id>]]` with ids from the candidate set; no bare article numbers (same regex as CitationGate, all
  languages); no placeholder braces; length limits; outcome/penalty consistency for recommendations and decision
  letters (R5). Any failure → retry with the failure reasons appended to the next request (once per retry).
- Notes: stored, never printed; capped at 10 short items.

## R5 Outcome consistency check (FR-007)
- Arabic/English keyword sets per outcome: warning (إنذار, warning), termination (إنهاء الخدمة, فصل,
  termination, dismiss), recommendation only (no penalty words). A draft mentioning a penalty word of a different
  outcome than the recorded one is rejected. Conservative by design: it may reject some valid drafts (then retry),
  never accept a contradictory one.

## R6 Workflow
- Defense letter: `open → ai_drafting_defense_letter` when the job is queued; on success the draft is saved and
  the case returns to `open` (HR edits and submits through the existing `DocumentDrafts::submit`); on failure it
  returns to `open` with the message. Recommendations: `committee_session_held → ai_drafting_recommendations →
  committee_session_held` the same way. Decision letter: `signed_recommendations_uploaded → ai_verify_and_close →
  signed_recommendations_uploaded`. (All edges exist in CaseTransitions; no new statuses.)
- The job records the state it started from and saves nothing if the case is no longer in the drafting state
  (FR edge case). A scheduled `ai:reconcile-drafting` (every 10 min) returns cases stuck in an AI drafting state
  for more than 20 minutes with no running job to their previous state and logs it (CLAUDE.md §3bb).
- One drafting job per case at a time (the status itself is the lock; refused if already drafting).

## R7 Relevance check (CLAUDE.md §23.3 step 4)
- Runs on submit of a draft with markers, as a queued job; the draft moves to the citation-check status (existing
  flow) and the confirmation screen shows per-citation progress. Input per citation: the claim sentence (the
  sentence containing the marker, marker replaced by the article reference) and the unit's official text —
  nothing else. Output per citation: `supports | partial | does_not_support` + reason (≤ 200 chars).
- Stored on `citations.relevance_check/relevance_reason`; editing a body clears results of that document's
  citations (DocumentDrafts already rewrites drafts; citations of a draft are re-synced from the markers).
- Overrides: `CitationOverride::override(Citation, User, string reason)` sets overridden/by/at + new
  `override_reason`, audit `citation.overridden` with the flagged result. Reviewer = the user allowed to confirm
  that document (existing policy).
- If unavailable: results stay null ("لم يُفحص"), CitationGate blocks; "إعادة الفحص" button and per-citation
  override remain.

## R8 Citations sync
- Markers in all bodies of a draft define its citation rows: on every save the service adds rows for new unit ids
  (claim sentence from the Arabic body, else English) and removes rows for unit ids no longer present (draft only;
  locked documents are already immutable). Validation of a manually inserted id uses `citableFor($case)`.
- Superseded replacement (FR-013): `CitationRefresh::replace(Document, oldUnit)` rewrites markers to the current
  version id in every language and re-syncs; relevance re-runs.

## R9 Rendering in each language (FR-012)
- `laws.name_en` (nullable; seeded for the three built-in laws: "Labour Law No. 37 of 2015", "Labour Law No. 71 of
  1987", "Retirement and Social Security Law for Workers No. 18 of 2023"); regulation laws default to the Arabic
  name until set.
- English clause rendering: canonical Arabic clause names → numbers (أولاً→1 … via the splitter's map), letters →
  a, b, c in abjad order (أ ب ج د هـ و ز ح ط ي ك ل م → a…m): `43/ثانياً/ز` → "Article 43(2)(g)". Kurdish uses the
  Arabic form.

## R10 Screens (thin Livewire over services)
- EditDocument / EditRecommendations / EditDecisionLetter: "صياغة بالذكاء الاصطناعي" button (policy-gated, hidden
  when not offered), `wire:poll` while the case is in the AI drafting state, "AI unavailable" banner, notes panel,
  "إدراج مادة" modal (search citable units, insert marker at cursor via a small Alpine helper).
- ConfirmDocument: per-citation relevance badges with reason, "إعادة الفحص", override form (reason required),
  superseded replacement button.
- Platform library units: topic tag editor (discipline + free tags) via LawManagement.

## R11 Tests
- Fake Claude client (no network) returning scripted outputs/failures; payload assertions (allow-list, forbidden
  fields absent); validator unit tests; workflow tests for success / unavailable / stale job / reconcile;
  relevance check + override + gate; citations sync; English rendering; screens per role.
