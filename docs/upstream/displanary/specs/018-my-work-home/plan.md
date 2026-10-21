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

## R6 Plan review resolutions (Codex review, 2026-10-08) — override R1–R5 where they differ

### Blockers
- **A1–A3 Actions from the whole case, not the status alone.** `NextAction::all(DisciplinaryCase, User): Collection`
  returns every pending action on a case with its actor, and `NextAction::for(case, viewer)` picks one by this
  priority (product-owner default, recorded in WEIRDNESS): (1) an action whose actor is the viewer; (2) a pending
  corrected document version (awaiting category approvals → committee/pool approvers; ready for confirmation → HR);
  (3) a pending consultation (requested, not completed/cancelled → reviewer "Give your legal opinion" at
  `/cases/{case}/consultation` when `CaseVisibility` admits the reviewer, else HR "Waiting for the lawyer");
  (4) the stage action from the status table. Deadline urgency is computed separately (`deadline_alert`) and shown as
  a badge; it never changes who the actor is. The reviewer list = pending consultations visible through
  `CaseVisibility::for($reviewer)` by the consultation request/completion/cancellation fields (not by status).
  Committee approval tasks come from pending document versions + eligibility (not from case status).

### Should-fix
- **B1 Precise stage actions** (target the control that is actually available): `awaiting_employee_response` → if no
  defense-receipt hand-off: "Record receipt of the defense letter"; else if before the response due day ends: HR
  "Waiting for the employee's response (due …)" (gray, not HR-actionable) plus "Record the paper response" available;
  after the due day: "Record the response or mark no response"; `response_received|refused|delivery_refused` →
  "Upload the response scan (optional) or hold the committee session"; `response_scanned_uploaded` → "Read the scan
  with AI or confirm it manually"; `ai_ingested_response` → "Hold the committee session"; `awaiting_appeal_window` →
  "Record decision receipt" only while that hand-off is missing; otherwise gray "Appeal window open until …" with
  "Record an appeal" available inside the window; `appeal_pending` → "Record the appeal outcome". Each mapping is
  checked against `CaseTransitions` and the screen's visible controls in tests.
- **B2 Urgency semantics** reuse `Deadlines`: a deadline is due through the end of its Baghdad calendar day; red =
  procedure deadline within the configured 48 h (end-of-day based) or passed without a decision, or response due day
  passed without hand-off; amber = due within 5 days; tests at 23:59 / 00:00 Baghdad boundaries. Alert ≠ actor.
- **B3 48-hour tile** gets a real list: a new `CaseMetrics` metric `procedure_due_48h` (same predicate as the
  `CaseAlertDispatcher` 48 h alert, shared helper) exposed through the existing `/dashboard/cases?metric=` drill-down;
  the % tile uses the existing `within15_numerator/denominator` definition and drill-down.
- **B4 Eligibility batched and shared**: extract `PoolEligibility::pendingFor(User $user): Collection<Document>`
  (batched: one query for candidate documents with mandate/branch/pool/conflict/prior-decision/category data, then
  in-memory checks) used by `/committee`, `CommitteeNav` and the home; pool approvers can be HR staff/company admins
  too. Tests: an HR-pool approver sees the task; an approved-category or conflicted member does not.
- **B5 Recent activity**: `case_events` filtered with `whereIn('case_id', CaseVisibility::for($user)->select('cases.id'))`,
  latest 10; rendered with a minimal label-only formatter (event type + case number + actor + time) — no payload
  details such as comments or witness names.
- **B6 Landing regressions**: update tests that asserted `/home` is forbidden to reporter/committee/reviewer roles and
  tests using old landing URLs; keep `/reports`, `/my-cases`, `/committee` working; test login redirect and direct
  `/home` for every role incl. platform admin (redirect to `/platform`).
- **Nits**: tests assert actor, destination and the explicit no-action statuses (archived, AI-drafting shows "AI is
  drafting — or write it manually" only to HR); action urgency uses its own tone mapping, not `Tone::case()`.

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
