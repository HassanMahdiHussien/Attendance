# Implementation Plan: Law vs Internal-Regulation Comparison Before Drafting

**Branch**: `021-law-regulation-conflicts` | **Date**: 2026-10-09 | **Spec**: [spec.md](./spec.md)

## Summary
A comparison step inserted before AI drafting for companies with an approved regulation: an AI job suggests matching
law/regulation pairs with verdicts; HR confirms on a Livewire panel; the confirmed comparison feeds the draft
(conflicting regulation units removed from candidates and blocked by the citation gate) and is shown as an internal
note to reviewers.

## Technical Context
Laravel 13 · Livewire 4 · existing `ClaudeClient` (+ `FakeClaudeClient`), `CandidateUnits`, `DraftPrompt`,
`DraftValidator`, `CitationGate`, `AiDrafting` + `DraftDocumentWithAi`, `DraftsWithAi` concern, `ai_generation_logs`,
`ai_draft_notes`, `LegalUnit::citableFor`, request-token pattern on cases (`ai_request_*`), `ai:reconcile-drafting` ·
one migration · no packages.

## Research (decisions)
- **R1 Data**: `regulation_comparisons` (tenant: company_id, case_id, document_type, status `suggesting|ready|
  confirmed|skipped|failed`, origin `ai|manual`, skip_reason, confirmed_by, confirmed_at, ai_generation_log_id,
  timestamps) and `regulation_comparison_pairs` (company_id, comparison_id, law_unit_id, regulation_unit_id,
  ai_verdict `consistent|conflict|more_favourable|null`, ai_reason, verdict `consistent|conflict|more_favourable|
  not_relevant`, changed_by_hr bool). Composite tenant FKs; triggers make confirmed comparisons and their pairs
  immutable (append-only like other legal records).
- **R2 When**: `AiDrafting::request` for defense letter / recommendations checks `RegulationComparison::required($case)`
  = the company has citable approved regulation units for the case. If required and there is no confirmed/skipped
  comparison for this draft request, it creates a comparison (status `suggesting`) and dispatches
  `SuggestRegulationComparison` instead of the draft job; the Livewire concern shows the comparison panel. After HR
  confirms or skips, HR presses "Draft with AI" again and the draft job runs with the comparison id.
- **R3 AI suggestion** (`SuggestRegulationComparison` job, same timeouts/retries/token pattern as the drafting job):
  candidates = `CandidateUnits` statutes + regulations for the case; the prompt (Arabic authoritative, ids only, no
  personal data — case type/description only through the existing `PayloadBuilder` masking) asks for JSON pairs
  `{law_unit_id, regulation_unit_id, verdict, reason}` where both ids come from the given lists; validation rejects
  unknown ids/verdicts (retry, then `failed` → manual path). The worker-favourable rule is stated explicitly in the
  prompt (regulation granting more than the legal minimum is not a conflict, `docs/PLATFORM-PRD.md` §3.3).
- **R4 HR panel** (Livewire, inside the existing editor screens using `DraftsWithAi`): pairs listed side by side
  (law text | regulation text, official texts, page links), AI suggestion badge + reason, verdict select (consistent /
  conflict — law applies / regulation gives the worker more / not relevant), "Add a pair" (manual: pick units from the
  citable lists), "Skip comparison" (reason required), "Confirm comparison". AI failure → panel opens in manual mode
  with the AI-unavailable message. Polling like the draft request.
- **R5 Drafting with the comparison**: `DraftDocumentWithAi` receives the comparison id; `CandidateUnits` excludes
  regulation units in `conflict` pairs; `DraftPrompt` adds the confirmed pairs (ids + verdicts, no free text) with the
  instruction to cite the law for conflicts; `CitationGate` / `CitationSync` reject any excluded regulation unit cited
  in that document (hard error with a translated message). The comparison id is stored on the draft document (column
  `documents.regulation_comparison_id`, nullable) so later corrections and reviewers find it.
- **R6 Internal note & reviewer panel**: a read-only "Law vs internal regulation" panel component shown on the
  confirmation screen, committee approval screen and consultation review screen when the document has a comparison:
  pairs, verdicts, both official texts, AI suggestion vs HR verdict, confirmed by/at, skip reason. Never rendered in
  document templates.
- **R7 Manual fallback**: identical panel without AI (create comparison origin `manual`); skip records the reason;
  reconciliation command returns a stuck `suggesting` comparison to `failed` after 20 minutes (extend
  `ai:reconcile-drafting` or the same pattern).
- **R8 Tests** (FakeClaudeClient): required/not required; suggestion → ready with validated pairs; invalid AI output →
  retry → failed → manual; HR changes verdict (changed_by_hr); confirm makes it immutable; draft excludes conflict
  regulation units from candidates and prompt; citation gate rejects an excluded unit; consistent/more-favourable
  allow citing; skip with reason then draft; reviewer panel visible to committee and lawyer, not in PDF/template;
  tenant isolation; audit entries; translations.

## R9 Plan review resolutions (Codex review + product owner, 2026-10-09) — override R1–R8 where they differ

### Blockers
- **A1 Constitution**: Principle II amended (v1.1.0, product owner): non-binding review aids such as suggested
  law-vs-regulation comparisons are allowed when a human confirms or changes them before they take effect; they never
  choose the operative rule or the outcome. The Constitution Check row II cites v1.1.0.
- **A2 Request identity**: the comparison uses the case's existing single AI request token. `AiDrafting::request`
  (locked) either reserves `ai_request_id` for operation `regulation_comparison` (stores it on the comparison as
  `request_token`, status `suggesting`, case status unchanged — no new case state) or, when a confirmed/skipped
  comparison exists for this draft target with `consumed_at` null, reserves the token for the draft and marks the
  comparison consumed by that draft (one comparison → at most one draft request; a second click or a new draft after
  consumption needs a new comparison, except Doc B inheritance below). The suggestion job writes only if the case's
  token still equals the comparison's token, then clears the token; late jobs/retries/second clicks are no-ops;
  `ai:reconcile-drafting` treats operation `regulation_comparison` older than 20 minutes as expired → comparison
  `failed` (manual path). Skip/confirm while a suggestion is in flight are refused until it finishes or expires.
- **A3 Exclusion set is authoritative everywhere**: each document links its comparison (`documents.regulation_comparison_id`);
  `RegulationExclusions::for(Document)` = regulation units in `conflict` pairs, or ALL regulation units when the
  comparison was skipped (product owner: skip ⇒ law only). Enforced in: candidate retrieval, the prompt, the manual
  citation picker (excluded units not offered), `CitationSync` (refuses to record them), `CitationGate` at issuance
  (hard block, translated), and `CitationOverride` (a relevance override can never re-allow an excluded unit). Tests
  for AI drafts, manual markers typed into edits, picker, and override attempts.
- **A4 Doc B inherits** (product owner): the decision letter uses the comparison linked to the issued recommendations
  version it derives from; its exclusion set applies; if Doc B would cite a regulation unit the comparison did not
  cover in any pair, issuance is blocked until HR confirms a supplementary comparison (new comparison record linked to
  Doc B covering the extra units). Doc A without a comparison (company had no regulation then) → Doc B behaves as
  today unless the company now has citable regulation units, in which case Doc B requires its own comparison.

### Should-fix
- **B1 Versions**: `DocumentCorrector` copies `regulation_comparison_id` to the new version (same exclusions); a new
  comparison for a correction is only created if HR redrafts with AI. The column is added to the document immutability
  trigger's protected set for issued/locked rows.
- **B2 Skip**: skip ⇒ law only (A3); the HR panel and reviewer panel state "Comparison skipped — only the labour law
  may be cited" with the reason; skipped rows are immutable like confirmed ones.
- **B3 Validation & FKs**: pair ids validated with `citableFor($case)` plus kind (statute vs internal_regulation) and
  company at AI-output time AND at confirmation; `law_unit_id` / `regulation_unit_id` are plain FKs to `legal_units`
  (statutes are platform rows with null company) with the company/kind rule enforced in the service and a model
  guard; DB triggers block insert/update/delete of pairs whose comparison is `confirmed`/`skipped`, and block changes
  to those comparison rows except `consumed_at` being set once.
- **B4 Minimal payload**: a dedicated comparison payload — violation type, incident description (masked by the
  existing name masking), and the candidate units (ids + official texts); no response, minutes, outcome or personal
  data. Test what FakeClaudeClient receives.
- **B5 Tests** add: stale/late job result, simultaneous requests, skip during in-flight suggestion, picker and manual
  marker attempts, correction versions, Doc B inheritance + supplementary comparison, relevance override attempt.
- **Nits**: the panel says the AI paired only retrieved candidates and HR can add other citable pairs; an empty AI
  list is never presented as "no conflict"; status/field mutability rules as in B3.

## Constitution Check
| Principle | Status | How |
|---|---|---|
| I. Tenancy | PASS | tenant tables with composite FKs; citable units only |
| II. AI boundary | PASS | v1.1.0 review-aid exception: AI suggests, HR confirms before effect; ids + official library texts only |
| III. Immutable/audit | PASS | confirmed comparisons append-only; audit + ai_generation_logs |
| IV. Tests | PASS | R8 |
| V. Thin UI | PASS | services/jobs; panel renders |
| VI. Decided | PASS | §23.4 + product-owner answers |

## Structure
```text
database/migrations/2026_10_21_000000_create_regulation_comparisons.php
app/Models/{RegulationComparison,RegulationComparisonPair}.php · app/Services/Ai/RegulationComparisons.php (or app/Ai/)
app/Jobs/SuggestRegulationComparison.php · app/Ai/{AiDrafting,CandidateUnits,DraftPrompt}.php · app/Jobs/DraftDocumentWithAi.php
app/Documents/CitationGate.php / app/Services/Documents/CitationSync.php · app/Livewire/Cases/Concerns/DraftsWithAi.php
resources/views/livewire/cases/partials/regulation-comparison*.blade.php · confirm/review/consultation views
lang/* · tests/Feature/Ai/RegulationComparisonTest.php
```

## Delegation
Codex plan review → run C → my gates + browser walkthrough with a seeded approved regulation (fake AI) → PR.
