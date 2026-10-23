# Tasks: Leave import, coverage and reconciliation

**Input**: [spec.md](spec.md), [plan.md](plan.md) — "Revisions after Codex plan review" overrides earlier plan text. Each step ends green in WSL (Unit, Feature, Browser, Pint).

## Step 1 — Schema and import
- [x] T001 Migrations `*_leave_*`: batches (+ events, snapshot_at, scope, period), mappings, leave types (normalized_key), requests, versions, decisions, request days, credits, allocations (two source FKs), indexes; append-only triggers for batches, events, versions, decisions
- [x] T002 Models, factories, tenancy test maps (+ unique busting), isolation, trigger smoke
- [x] T003 `LeaveFile` (stream, dates, plausibility), `LeaveStatus`, `LeaveTypes` (seed defaults, unmapped issue)
- [x] T004 `LeaveImport` preview/commit (token, hash, scope, snapshot order, versions, unknown employee and invalid-date issues, missing-from-full-report issue + auto-resolve), chunked job
- [x] T005 Tests for step 1

## Step 2 — Distribution and reconciliation
- [x] T006 `DaysAssessed` event from `DayAssessor`; `ReconcileLeaveJob`; triggers
- [x] T007 `LeaveDistributor` (revision 6) and `Reconciler` (revisions 2–4) with explanations
- [x] T008 `LeaveActions`: cancel, confirm distribution, fix dates, reverse deduction, withdraw
- [x] T009 Tests: PRD §25 2, 13, 14, 15, 23; half-day on absence; 2.5-day distribution; mismatch; cancellation refill; HR reversal stickiness; day changes move allocations; replay idempotent; isolation

## Step 3 — Screens
- [x] T010 Leave upload, requests, request detail, leave types, credit ledger; day detail "Leave and reconciliation"; translations, UI inventory, route sweep, help; Livewire + browser smoke tests

## Step 4 — Verification (orchestrator)
- [x] T011 Real leave sample (21,249 rows) on the smoke DB with HC employees: statuses 18,534 approved / 2,022 draft / 391 rejected / 302 pending; 2 unmapped types (0200, "Employee Son/Daughter Marriage"); 33 unknown owners; duration mismatches 3,594 → 26 after the counting-method rule; 19,025 leave days distributed; re-upload refused. Docs; PR
