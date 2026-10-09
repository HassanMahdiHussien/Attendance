# Implementation Plan: Automatic Decision-Letter Draft and Related-Case Links

**Branch**: `022-tp4-related-links` | **Date**: 2026-10-09 | **Spec**: [spec.md](./spec.md)

## Summary
Hook the existing AI decision-letter request into the transition to `signed_recommendations_uploaded` for penalty
outcomes, and add HR-made `related` case links with a case-page panel, events and AI context.

## Technical Context
Existing `SignaturePresence` (recordPresence, overrideEmpty), `CaseWorkflow`, `AiDrafting::request`,
`DraftDocumentWithAi`, `ai:reconcile-drafting`, `Outcome`/penalty helpers, `case_links` + `CaseLinkType::Related`,
`CaseInsights` (priors banner), `PayloadBuilder`, `CaseVisibility`, `ShowCase` + view, `NextAction` (shows the
drafting state) · no packages; one migration only if `case_links` lacks reason/in-error columns.

## Research (decisions)
- **R1 Auto-draft** `App\Services\Cases\AutoDecisionDraft::afterSignedRecommendations(DisciplinaryCase, User $actor)`
  called from `SignaturePresence::recordPresence` and `overrideEmpty` right after their transition, inside the same
  transaction but dispatching after commit (`AiDrafting::request` already uses `DB::afterCommit`). Conditions: case
  status is `signed_recommendations_uploaded`; the issued recommendations' outcome has a penalty (warning /
  termination); no decision-letter document exists; no AI request token is held. It calls the existing
  `AiDrafting::request($case, DocumentType::DecisionLetter, $actor)`; any `TransitionBlocked`/`AiUnavailable` is caught,
  logged as a case event `ai_draft_auto_skipped` with the reason key, and never fails the presence recording. Actor =
  the HR user who recorded the presence (or the admin who overrode). Case event `ai_draft_auto_requested`.
- **R2 Consultation & 021**: unchanged — the decision letter can be drafted while a consultation is pending
  (issuance still waits); the Doc B regulation-comparison inheritance applies through the existing draft path.
- **R3 Links data**: check `case_links` columns/triggers; add (if missing) `reason` (string ≤ 500), `created_by`,
  `in_error_at`, `in_error_by`, `in_error_reason`. If `case_links` is append-only by trigger, the "in error"
  annotation is a separate append-only table `case_link_annotations` (link_id, reason, by, at) instead of updating the
  row. One row per pair (store lower id first) — unique (company_id, type, case_id, related_case_id); shown from both
  sides.
- **R4 Links service** `App\Services\Cases\CaseLinks::linkRelated(case, other, reason, actor)` /
  `markInError(link, reason, actor)`: same company, both visible via `CaseVisibility::for($actor)`, actor HR role,
  not the same case, not already linked; events `case_related_linked` / `case_related_link_in_error` on both cases
  (ids only); audit.
- **R5 UI**: case page "Related cases" card: list (case number, employee, date, violation type, outcome badge,
  reason, "linked in error" note), "Link a related case" form (search among visible cases of the company by number or
  employee — reuse `GlobalSearch`'s case provider scoped by `CaseVisibility`), "Mark as linked in error" with reason
  (company admin / HR). Banner line "N related cases" near the prior-cases banner.
- **R6 AI context**: `PayloadBuilder` adds `related_cases` (case number, date, violation type, outcome) for
  touchpoints 1 and 3, excluding in-error links; prompt says they are context only and must not be restated as facts
  unless HR's facts mention them. Templates unchanged.
- **R7 Tests**: auto draft on warning/termination (FakeClaudeClient), none on no-penalty, none when a draft exists,
  none when a token is held, AI unavailable → existing failed state (case not stuck), override path also triggers;
  links: create both-direction display, cross-company refused, invisible case refused, duplicate refused, in-error
  annotation hides from AI payload but stays visible, events on both cases, tenant FK registration for any new
  table.

## Constitution Check
| Principle | Status | How |
|---|---|---|
| I. Tenancy | PASS | same-company + visibility checks; tenant FK tests |
| II. AI boundary | PASS | auto-draft uses the human-decided outcome; human confirmation gate unchanged; related cases are DB facts |
| III. Audit | PASS | events on both cases; permanent links; annotations instead of deletes |
| IV. Tests | PASS | R7 |
| V. Thin UI | PASS | services; Livewire renders |
| VI. Decided | PASS | CLAUDE.md §3b tp4, §24.4; spec defaults |

## Structure
```text
app/Services/Cases/{AutoDecisionDraft,CaseLinks}.php · app/Services/Cases/SignaturePresence.php
database/migrations/2026_10_22_000000_add_related_link_fields.php (if needed)
app/Ai/PayloadBuilder.php · app/Livewire/Cases/ShowCase.php + view · lang/* · tests/Feature/Cases/{AutoDecisionDraft,RelatedLinks}Test.php
```

## Delegation
Codex plan review → run L → my gates + browser check of the links card → PR.
