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

## Revisions after Codex plan review (2026-10-22) — these override the sections above

1. **Exit dates.** `last_working_day` and `exit_status` are read from the employee row (spec 501 keeps them outside versions). A date is not assessed when it is strictly after a **confirmed** `last_working_day`. `ExitReview::confirm` / `dismiss` dispatch reassessment from the affected date (new `EmployeeExitChanged` event, after commit).
2. **Coverage-driven assessment.** Assessment enumerates **every office employee eligible on each date** (by the branch, pattern and rest days in effect that date), not only people with punches, so absences and outages appear. `ProcessAttendanceJob` emits `AttendanceProcessed(company, personCodes, from, to)` after commit; coverage confirmation/change and device approval emit `CoverageChanged(company, branchIds, from, to)`. Both dispatch `AssessDaysJob` for employees of those branches over those dates.
3. **Coverage rule.** Declared + confirmed intervals of a branch are merged (overlapping or touching intervals union). A date is assessable for an employee when the merged intervals of their branch cover `[start of the day 00:00, required end + grace]`. Every interval creation or confirmation triggers `CoverageChanged`.
4. **Exact formulas** (all minutes; `S` = start, `W` = workday length, `F` = flex window):
   - Defaults `S` = 08:00, `W` = 540 (9 h incl. break), `F` = 60. If only `schedule_start` is set: `S` = it, end = `S + W`. If only `schedule_end`: `S = end − W`. If both: `S` = start, `W` = end − start (must be > 0, else the day is `pattern_missing` with reason `invalid_schedule`).
   - Arrival `a` (first check-in): `a ≤ S` → required leave `S + W`; `S < a ≤ S + F` → required leave `a + W`; `a > S + F` → late `a − (S + F)`, required leave `S + F + W`.
   - Early departure = `max(0, required leave − last check-out)`. Examples (defaults): 08:00 → 17:00; 08:30 → 17:30; 09:00 → 18:00, not late; 09:15 → late 15, leave 18:00; 07:30 → 17:00.
   - Day closes when `now ≥ required end + grace` **and** coverage holds (rule 3). There is no separate "today" rule.
5. **Pairing.** Inputs = current `used` cleaned punches + non-reversed manual punches, sorted by `(time, manual after device, id)`. Pair each check-in with the next check-out; an unmatched check-in or check-out leaves the day `missing_punch` and **no balance is computed** from unknown time (lateness alone is shown if the first check-in exists). Presence = sum of paired intervals; exits = gaps between a check-out and the next check-in inside the day. Lateness, early departure and (optional) exits are disjoint minute spans by construction (lateness ends at the first check-in, early departure starts at the last check-out, exits lie between), so the balance never double-counts. A confirmed absence decision takes precedence over punches until withdrawn.
6. **Outage rule.** A branch-date is a suspected outage when: the branch has ≥ 1 check-in device whose approval was effective on that date, the date is covered (rule 3), it is a working day for at least one eligible office employee of that branch, and those devices recorded **zero punches from anyone** (employees, contractors, visitors) during the working day. One grouped `device_outage` issue per branch-date. Once HR resolves or dismisses it, the detector never reopens the same branch-date (it checks for any existing issue with that subject, not only open ones).
7. **Locking and scope.** `DayAssessor` locks the `employees` rows of its scope in id order before reading and superseding days. Runs store `scope` as `{employee_ids|branch_ids, from, to}`. Each day stores the historical `branch_id`; index `(company_id, branch_id, work_date, superseded_by_run_id)` serves the daily screen.
8. **Batch loading.** Per assessment chunk (≤ 100 employees × range): prefetch all versions and fold them per date in memory, preload policies and merged coverage per branch for the range, current punches with one ranged query per chunk, decisions and manual punches likewise. Back-dated policy change: affected interval = `[effective_from, next policy's effective_from)`; same-date versions break ties by `version` (higher wins).
9. **Policy backfill.** A migration inserts the default policy version (effective from the company's creation date) for existing companies; onboarding inserts it for new ones.
10. **Tests per step** as listed by the review (composite FK map + unique busting, isolation, append-only triggers in step 1; route sweep, UI inventory, translations with the screens; other-company fixtures before Filament renders).

**Steps (final):** (1) schema + policy backfill + pure `OfficeDayCalculator` with exhaustive rule tests; (2) assessment pipeline: events, coverage union, coverage-driven enumeration, outage detector, HR review, exit hooks, scheduled re-assessment, batch loading; (3) screens; (4) real-data verification, docs, PR.
