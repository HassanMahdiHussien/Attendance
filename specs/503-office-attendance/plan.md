# Implementation Plan: Office attendance

**Branch**: `503-office-attendance` | **Date**: 2026-10-22 | **Spec**: [spec.md](spec.md)

## Summary

Compute one derived "attendance day" per office employee per date from spec 502's current cleaned punches, spec 501's dated employee values and a versioned office policy, gated by confirmed coverage. HR reviews missing punches and absences with append-only decisions. Recalculation is incremental and keeps earlier results.

## Technical Context

Same stack and conventions as specs 501/502 (WSL, `testing_attendance`, attendance namespaces, Codex implements, gates in WSL). Times are local Asia/Baghdad datetimes; all minute arithmetic uses whole minutes (the devices record minutes).

## Constitution Check (2.0.0)

I isolation: new tables tenant-scoped + tests. II rules calculate: `OfficeDayCalculator` is pure deterministic code with a versioned policy; no AI. III immutability: punches untouched; HR decisions, manual punches and policy versions append-only; derived days superseded, never edited. IV tests: PRD §25 6, 10, 11, 12 + the clarified rules. V/VI: services + thin Filament; WEIRDNESS lines for the 18:00 rule and the short-hours-not-in-balance rule.

## Design

### Schema (`*_attendance_*`, all `company_id` + composite FKs, explicit short index names)
- `attendance_policies` (append-only): `effective_from`, `office_start` (time), `flex_minutes`, `workday_minutes` (540), `min_presence_minutes` (480), `close_grace_minutes` (120), `exits_count_toward_balance` (bool), `office_rest_days` (json, default [5,6] = Fri, Sat), `version` (int, per company), `created_by`, `reason`.
- `attendance_manual_punches` (append-only): `employee_id`, `punched_at`, `role` (`check_in|check_out`), `reason`, `created_by`, `reverses_id` (nullable, to withdraw an earlier manual punch).
- `attendance_day_decisions` (append-only): `employee_id`, `work_date`, `decision` (`confirm_absence|withdraw_confirmation`), `reason`, `created_by`, `manual_punch_id` (nullable).
- `attendance_runs` reuse spec 502's `attendance_processing_runs` with `kind = days`.
- `attendance_days` (derived, superseded per run): `employee_id`, `work_date`, `run_id`, `superseded_by_run_id`, `status`, `first_in`, `last_out`, `elapsed_minutes`, `presence_minutes`, `late_minutes`, `early_minutes`, `exit_minutes`, `short_minutes`, `balance_minutes`, `required_leave_at`, `intervals` (json), `exits` (json), `policy_id`, `inputs` (json: employee values used — pattern, rest days, schedule, branch), `reviewed` (bool: HR decision applied), `changes` (json: previous vs new for this run). Unique current row per `(company_id, employee_id, work_date)` enforced by superseding under lock (same pattern as 502). Index `(company_id, work_date, superseded_by_run_id)` and `(company_id, employee_id, work_date, superseded_by_run_id)`.

### Services (`App\Services\Attendance`)
- `OfficePolicy::asOf(company, date)`; `add(company, values, effectiveFrom, actor, reason)` → append + recalculation from that date.
- `OfficeDayCalculator::calculate(employeeValues, policy, punches, manualPunches, decisions, coverage, outage, date, now)` → pure array result (no DB). All rules of FR-002…FR-006 live here and are unit-tested directly.
- `DayAssessor::run(company, employeeIds, from, to, trigger)` — loads inputs per employee (EmployeeHistory::asOf per date, current used punches incl. ±1 day margin, manual punches, decisions, coverage intervals, open outage issues), calls the calculator, supersedes and inserts current days under per-employee lock (sorted), records changes vs previous.
- `OutageDetector::run(company, branchIds, from, to)` — per branch and covered working date: zero punches from approved check-in devices while ≥1 office employee is expected → open/keep a grouped `device_outage` data issue; disappearing outage → resolve.
- `DayReview` — `addMissingTime(employee, date, time, role, reason, actor)`, `confirmAbsence(employee, date, reason, actor)`, `withdraw(...)`; HR-only gate; audit; triggers `DayAssessor` for that day.
- Triggers: listener on spec 502 processing-run completion (`AttendanceProcessed` event — add it to `ProcessAttendanceJob` if missing) → assess affected employees/dates; listener on `EmployeeVersionChanged` → from the effective date; policy add → from its date; coverage interval creation / device approval → affected branch dates; data-issue resolution for outages → that branch/date. All via a queued `AssessDaysJob` (`ShouldBeUnique` per scope, after commit).
- Daily scheduled command `attendance:assess-pending` re-assesses `pending` days whose close time has passed (coverage permitting).

### Screens (Filament admin, Attendance group)
Daily attendance (date + branch filter, table with statuses and minutes, LTR times); Review queue (missing punch / absence → add time or confirm absence modal, HR only); Office policy (current + history, add version); Day detail (intervals, exits, rule values, recalculation history). Platform admin can view all; HR only own company.

## Steps (Codex → my review → gates → commit)

1. **Rules engine + schema**: migrations, models, factories, `OfficePolicy`, `OfficeDayCalculator` with exhaustive unit tests of every clarified rule (pure, fast), seeds of a default policy version for existing companies and new companies (onboarding).
2. **Assessment pipeline**: `DayAssessor`, `OutageDetector`, `DayReview`, events/listeners/jobs, scheduled command, coverage gating; feature tests incl. recalculation and isolation.
3. **Screens** + translations, UI inventory, route sweep, help article, Livewire tests and a browser smoke test.
4. **Verification** (me): run the real sample (HC + transactions) on the smoke DB with office patterns assigned to a test subset, report day statuses and minutes distribution; docs; PR.

## Risks
- Most HC employees have no work pattern yet (the email/work-pattern file is pending), so on real data most days will show `pattern_missing` until that file is uploaded.
- Public holidays appear as absences pending review until the holidays spec.
- The sample covers only 2.3 days; long-range performance is estimated, not measured.
