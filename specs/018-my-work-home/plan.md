# Implementation Plan: "My Work" Home for Each Role

**Branch**: `018-my-work-home` | **Date**: 2026-10-08 | **Spec**: [spec.md](./spec.md)

## Summary
A `NextAction` service (single source of "what needs doing next" for a case), a `MyWork` service that assembles each
role's home from existing visibility classes and metrics, a rebuilt `Home` Livewire page (role-aware, design-system
components), `Landing` pointing every workspace role to `/home`, and a "My work" sidebar item.

## Technical Context
Livewire 4 · existing `CaseVisibility`, `ViolationReportVisibility`, `EmailDraftVisibility`, `PoolEligibility`,
`CommitteeNav`, `Deadlines`, `CaseMetrics`, `Hotspots`, `DashboardFilters`, `CaseTimeline`, `Tone`, `Nav` · no new
packages, no migrations.

## Research (decisions)
- **R1 NextAction** (`App\Services\Cases\NextAction::for(DisciplinaryCase $case, User $viewer): ?Action` and a batch
  `forMany(Collection $cases, User $viewer)` that eager-loads what it needs): a table from status → (actor, label key,
  target route) covering every `CaseStatus` (drafts → "Complete and open the case"; open → "Prepare the defense
  letter"; `defense_letter_ready_for_review` / `decision_letter_ready_for_review` → "Review and issue …" (confirm
  route); `awaiting_employee_response` → "Record the employee's paper response" (due = response deadline);
  response received/refused/delivery refused/scanned → "Upload/confirm the response" or "Hold the committee session";
  `committee_session_held` → "Draft the recommendations"; `recommendations_awaiting_approvals` → committee: "Approve
  the recommendations", HR: "Waiting for approvals (n of 3)"; printed/awaiting signatures → "Upload the signed
  recommendations"; `signed_recommendations_uploaded` → "Prepare the decision letter" or close; consultation →
  reviewer: "Give your legal opinion", HR: "Waiting for the lawyer"; appeal window → "Record decision receipt / appeal"
  only when that hand-off is missing; AI drafting states → "AI is drafting — or write it manually" ; archived → none).
  Urgency: red if the 15-day deadline is ≤ 48 h away or passed (no decision), or the response deadline is today/passed;
  amber if a deadline is within 5 days; indigo otherwise; gray when waiting on someone else. Due date = the deadline the
  action is measured against (`Deadlines`). Exhaustiveness test: every `CaseStatus` is mapped.
- **R2 MyWork** (`App\Services\Home\MyWork::for(User $user): array`) per role:
  HR/company admin — tiles (`CaseMetrics` counts with the dashboard's filters; "within 48 h" from `Deadlines`; awaiting
  approvals count; % within 15 days), actions = `NextAction` over `CaseVisibility::for($user)` open cases where actor is
  HR + inbox reports/email drafts, sorted red→amber→indigo→gray then due date; hotspot alerts (`Hotspots` alerts);
  recent activity (10 latest `case_events` joined to `CaseVisibility::for($user)`).
  Committee member — documents awaiting approval where the user is eligible and their category is not yet approved
  (reuse `PoolEligibility`/`DocumentApprovalService` rules, batched), plus cases they attended (`CaseVisibility`).
  External reviewer — cases in `awaiting_external_legal_consultation` visible to them with procedure deadline.
  Reporter/dept manager — `ViolationReportVisibility::for` + `EmailDraftVisibility::for` own items with bare status
  only (no case join).
  HR partner — existing overview data.
  All queries batched: one query per list + eager loads; a test asserts the HR home stays under a fixed query budget
  with 50 cases.
- **R3 Page**: `App\Livewire\Home` becomes role-aware (removes the partner-only `abort_unless`; Platform Admin
  redirected to `/platform`); view built from `x-ui` components per the mockup: greeting + Baghdad date, tiles,
  "Needs your action" (card rows with badge, label, case number in `<bdi>` mono, employee name, button), side column
  (quick actions per role, hotspot alerts, recent activity). Tiles link to `/cases?...` or `/dashboard/cases?...` with
  the existing query filters.
- **R4 Landing & nav**: `Landing::for` → `/home` for CompanyAdmin, HrStaff, Reporter, DeptManager, CommitteeMember,
  ExternalLegalReviewer, HrPartnerStaff; `Nav` adds "My work" first for those roles. Existing landing tests updated.
- **R5 Tests**: NextAction exhaustiveness + representative states + urgency boundaries (Baghdad dates); MyWork per
  role incl. tenant isolation, HR staff scope, committee category already approved, reporter sees no case data;
  query-budget test; Livewire render in both languages; landing per role.

## Constitution Check
| Principle | Status | How |
|---|---|---|
| I. Tenancy | PASS | only existing visibility classes; tenant tests |
| II. AI boundary | PASS | no AI |
| III. Audit | PASS | read-only page |
| IV. Tests | PASS | R5 |
| V. Thin UI | PASS | NextAction + MyWork services; Livewire renders |
| VI. Decided | PASS | product owner chose the role home; mockup approved |

## Structure
```text
app/Services/Cases/NextAction.php (+ app/Services/Cases/NextActionItem.php value object)
app/Services/Home/MyWork.php · app/Livewire/Home.php · resources/views/livewire/home.blade.php
app/Support/Access/{Landing,Nav}.php · lang/ar.json, lang/{en,ar}/messages.php · tests/Feature/Home/*.php
```

## Delegation
Codex plan review → run H (all) → my gates + screenshots per role (EN/AR, 1280/375) → PR.
