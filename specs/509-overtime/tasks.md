# Tasks: Overtime and rest-day compensatory entitlements

**Input**: [spec.md](spec.md), [plan.md](plan.md) — "Revisions after Codex plan review" overrides earlier plan text. Each step ends green in WSL (Unit, Feature, Browser, Pint).

## Step 1 — Calculation and entitlements
- [x] T001 Policy columns + defaults/casts/validation + idempotent replacement version; day columns (`overtime_minutes`, `early_arrival_minutes`, `overtime_basis`); entitlements table (run/supersede, composite FKs, triggers)
- [x] T002 `OvertimeCalculator` (pure) on the final day result: intervals after required end, minimum/step, rest-day/holiday presence, early-arrival marker (schedule_start / shift start), eligibility by historical rest days; DayAssessor persistence + entitlement supersession
- [x] T003 Tests: every FR-008 time case, interval gaps after the end, unmatched punch → nothing, office Friday vs Saturday, shift Friday, security individual rest day, holiday on an eligible rest day counted once, reassessment changes an entitlement once, isolation, triggers

## Step 2 — Consumption
- [x] T004 Compensation Leave consumption in the locked replay (no surplus credit, ordering, all-or-nothing, `entitlement_exceeded`, cancellation restore, idempotent ledger compare); replay triggered by entitlement changes
- [x] T005 `Overtime` read service (period vs now, overdue boundary) + tests 16–20

## Step 3 — Screens
- [x] T006 Day detail, employee page, dashboard tiles + drill-down, overtime and entitlement exports, statement, policy page Overtime section; translations, UI inventory, route sweep, Livewire + browser tests, agreement

## Step 4 — Verification (orchestrator)
- [x] T007 Real data on the smoke DB, reassess-all, docs, PR
