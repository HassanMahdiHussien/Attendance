# Tasks: Office attendance

**Input**: [spec.md](spec.md), [plan.md](plan.md) — the "Revisions after Codex plan review" section overrides earlier plan text. Each step ends green in WSL (Unit, Feature, Browser, Pint).

## Step 1 — Schema and rules engine
- [x] T001 Migrations: policies (+ default backfill), manual punches, day decisions, days (historical branch, current-result indexes); append-only triggers for policies, manual punches, decisions
- [x] T002 Models, factories; tenancy test maps (+ unique busting), isolation, trigger smoke
- [x] T003 `OfficePolicy` (asOf with version tie-break, add) and onboarding default
- [x] T004 Pure `OfficeDayCalculator` implementing plan revisions 4 and 5
- [x] T005 Exhaustive unit tests for the calculator (boundaries, overrides, pairing, missing punch, exits, short hours, confirmed absence, closing time)

## Step 2 — Assessment pipeline
- [x] T006 Events `AttendanceProcessed`, `CoverageChanged`, `EmployeeExitChanged` (after commit) and `AssessDaysJob`
- [x] T007 `DayAssessor` (coverage union, eligibility per date, batch loading, employee locks, superseding, change log)
- [x] T008 `OutageDetector`, `DayReview` (add missing time, confirm absence, withdraw), scheduled `attendance:assess-pending`
- [x] T009 Feature tests: PRD §25 6, 10, 11, 12; absences for employees without punches; outage rule and no reopen; back-dated rest day / policy changes; exit confirmation; isolation

## Step 3 — Screens
- [x] T010 Daily attendance, review queue, office policy, day detail; translations, UI inventory, route sweep, help article; Livewire tests + browser smoke test

## Step 4 — Verification (orchestrator)
- [ ] T011 Real sample on the smoke DB, docs (WEIRDNESS, FOUNDATION-CHANGES), PR
