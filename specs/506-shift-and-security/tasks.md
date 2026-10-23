# Tasks: Shift and security attendance

**Input**: [spec.md](spec.md), [plan.md](plan.md) — "Revisions after Codex plan review" overrides earlier plan text. Each step ends green in WSL (Unit, Feature, Browser, Pint).

## Step 1 — Additive schema and pure calculator
- [x] T001 Migration: policy columns (absence_minutes, shift and security sections, inference tolerance) with defaults; day columns (shift_start, shift_source, shift_confidence, shift_evidence); decision kind pick_shift (+ shift_start); FK-safe down()
- [x] T002 `ShiftDayCalculator` (pure) per revisions 3 and the spec rules
- [x] T003 Exhaustive unit tests; tenancy/trigger tests for changed tables; office results unchanged

## Step 1b — Employee fields and default pattern rule (plan addendum)
- [x] T001b `staff_category` + `legal_entity` employee fields (versioned, import aliases, form); `pattern_rules` config; rule applied on import and once for existing employees through EmployeeHistory (source `rule`); `pattern_review` issue for flagged positions; tests

## Step 2 — Integration
- [x] T004 Replacement policy versions (revision 1) + reassessment; absence_minutes in Reconciler/LeaveAllocator/AttendanceSummary; LeaveDistributor pattern rest days (revision 2)
- [x] T005 DayAssessor routing by pattern, widened scope, ownership across midnight, prefetch (revisions 4, 8)
- [x] T006 Leave-day change → reassessment without loops (revision 5); night coverage/outage windows (revision 6)
- [x] T007 Feature tests: PRD §25 7, 8, 9; office 9 h alert with 8 h absence for leave; night shifts; outage; recalculation; isolation

## Step 3 — Statuses and screens
- [ ] T008 shift_unresolved in review gate/queue/filters/summary/exports; DayReview pick/withdraw shift
- [ ] T009 Daily attendance + day detail shift and evidence; "Attendance policy" page sections; translations, UI inventory; Livewire + browser smoke tests

## Step 4 — Verification (orchestrator)
- [ ] T010 Real HC + punches with an assumed pattern split; docs; PR
