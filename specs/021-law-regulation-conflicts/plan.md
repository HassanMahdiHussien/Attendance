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

## Constitution Check
| Principle | Status | How |
|---|---|---|
| I. Tenancy | PASS | tenant tables with composite FKs; citable units only |
| II. AI boundary | PASS | AI suggests; HR confirms; only library ids; official texts from the library |
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
