# Implementation Plan: Overtime and rest-day compensatory entitlements

**Branch**: `509-overtime` | **Date**: 2026-10-24 | **Spec**: [spec.md](spec.md)

## Summary

The day assessor gains two calculated fields (overtime minutes, early-arrival marker) from versioned policy values. Rest-day entitlements are derived from current day results; `Compensation Leave` consumes them through the 504 leave replay (same run/supersede model as credits and allocations), so cancellations and reassessments rebuild consumption deterministically.

## Technical Context

- Days are append-only versions (`run_id` / `superseded_by_run_id`); policies are append-only versions (`attendance_policies`, new version per change). Leave credits and allocations are rebuilt per employee by `Reconciler::replayEmployee` with run/supersede rows. Reuse those patterns; no new framework.
- Inputs already on the day: `status` (`present`, `rest_day_worked`, `holiday_worked`, …), `first_in`, `last_out`, `required_leave_at`, `presence_minutes`, `intervals`, `holiday_ids`, `shift_start`, `inputs.work_pattern`, policy id.

## Design

- **Policy** (new columns on `attendance_policies` with defaults, plus a replacement version appended for every company like spec 506 did): `overtime_min_minutes` 30, `overtime_step_minutes` 15, `rest_day_half_minutes` 240, `rest_day_full_minutes` 480, `compensatory_rest_days` json per pattern `{office:[5], shift:[5], security:"individual"}` (security uses the employee's own rest days).
- **Day columns** (`attendance_days`): `overtime_minutes` (unsigned, null when not assessed), `early_arrival_minutes` (unsigned, null), `overtime_basis` (`after_end|rest_day|holiday|null`). Calculated in the office/shift calculators (pure) from the day result:
  - normal working day, status `present` with a last check-out: minutes from `required_leave_at` (shift: shift start + shift minutes) to `last_out`; < min → 0; else floor to step.
  - `rest_day_worked` / `holiday_worked`: presence minutes floored to step (no minimum).
  - early arrival: office minutes before `office_start` (08:00), shift minutes before shift start, when first_in is earlier; marker only.
  - other statuses: null.
- **Entitlements** (`attendance_rest_day_entitlements`, run/supersede like leave credits; produced by the assessor in the same transaction as the day row): employee, work_date, source day id, quantity (0.5 / 1.0), worked minutes, basis (`office_friday|shift_rest_day|security_rest_day`, plus `holiday` flag), run ids. A day version change supersedes its entitlement row and writes the new one (or none); the stable key is (employee, work_date).
- **Consumption** (`leave_entitlement_consumptions`, rebuilt by the leave replay, run/supersede like allocations): request day ↔ entitlement key (employee, work_date) + quantity (0.5 / 1.0) + explanation. Order: oldest available entitlement first; half-day requests can split a full entitlement. Exceeding → request flag `entitlement_exceeded` + data issue; nothing created. The replay is triggered by entitlement changes (assessor → `ReconcileLeaveJob` for the employee) and by leave changes as today.
- **Alerts**: "unused after the following week" computed at read time (work_date's Sunday–Saturday week + 1 week < today and remaining quantity > 0); no stored alert, no expiry.
- **Read service** `Overtime` (period totals, per-day list, entitlements with available/used/overdue) feeding the daily record, employee page, dashboard tiles, an overtime export, an entitlement export and the statement — one source so counts agree.
- **Screens**: day detail (overtime, early-arrival marker, entitlement), employee page section, dashboard tiles ("Overtime hours", "Entitled employees", "Available entitlements", "Overdue entitlements"), policy page Overtime section, reports. English + Arabic.

## Steps

1. Policy columns + replacement version, day columns, calculator changes (pure) + assessor persistence, entitlements table + creation/supersession; unit tests for every FR-008 time case; reassessment tests; isolation/triggers.
2. Consumption in the leave replay (oldest first, half-day split, exceeded flag, cancellation restore), `Overtime` read service, alerts; tests 16–20.
3. Screens, dashboard tiles, exports, statement, policy page section, translations, UI inventory, route sweep, Livewire + browser tests.
4. Verification on the smoke DB with the real 18–20 Oct data (overtime distribution, rest-day work on Friday 2026-10-16 is outside the file → use the 19 Oct holiday simulation and a synthetic Friday), docs, PR.

## Revisions after Codex plan review (2026-10-24) — these override the sections above

1. **Final status first.** Overtime, early-arrival marker and entitlement eligibility are computed by a pure `OvertimeCalculator` called by `DayAssessor` on the **final** day result (after the holiday / rest-day handling) and before the day version is saved. The office/shift calculators are not changed for overtime.
2. **Intervals, not clock span.** Normal-day overtime = sum of the intersections of the recorded presence intervals (device + manual punches, the same intervals used for presence) with the period after the required end; then the 30-minute minimum and 15-minute floor. A day must be complete and closed (`present`, or `rest_day_worked` / `holiday_worked` whose intervals are all closed); an unmatched punch earns nothing until resolved. Rest-day / holiday overtime = presence minutes floored to 15.
3. **Compensation Leave vs credit.** A request whose type `consumes = rest_day_entitlement` still covers attendance on its own dates (allocation as today) but **never creates surplus credit**; its day quantity is consumed from entitlements in the same employee-locked replay as credits and allocations: requests ordered by start date then id, entitlements by work date then id; a request is consumed all-or-nothing — if available quantity is short, nothing is consumed and the request is flagged `entitlement_exceeded` (+ data issue); cancellation/rejection removes its consumption on the next replay. The replay compares the new consumption ledger with the current one and writes nothing when identical. Composite tenant FKs to the request-day and entitlement rows.
4. **`Overtime Equivelant Leave`** keeps its 504 behaviour (covers attendance; may use credit). Matching it against overtime hours is deferred to 509b with the overtime report; screens never show overtime as "matched" in 509.
5. **Eligibility by history.** A day qualifies for an entitlement only when its date is a rest day for that employee as of that date (EmployeeHistory `rest_days` override, else the pattern's policy rest days) **and** the day is a compensatory rest day: office Friday (not Saturday), shift Friday, security individual rest day. A qualifying day that is also a holiday gives one overtime amount and one entitlement, holiday ids kept as context. A non-qualifying holiday gives overtime only.
6. **Policy versioning like 506.** Columns with defaults; model casts, defaults (`OfficePolicy::DEFAULTS`) and validation; the policy page preserves all values when adding a version; an idempotent migration appends one replacement version per company that wins same-date selection without rewriting older versions; after deploy run `attendance:reassess-all` (documented in the PR and run on the dev DB).
7. **Reporting.** `Overtime` read service: period figures use days/entitlements whose work date is in the period with the same historical branch/pattern/day filters as `AttendanceSummary`; "available now" is separate from "earned in period". Overdue: the following week is the Sunday–Saturday week after the work date's week; for a Friday entitlement the alert starts on the second Sunday after it (test the boundary). Dashboard drill-down ids, exports and statement use one projection.
8. **Early-arrival marker** uses the employee's effective `schedule_start` when set (else policy office start); shifts use the chosen/inferred shift start of the owning work date.

**Steps (final):** (1) policy columns + replacement version, day columns, `OvertimeCalculator` on final status, eligibility by history, entitlements (run/supersede), reassessment of existing days, tests; (2) consumption in the locked replay (no surplus credit from Compensation Leave, all-or-nothing, ordering, idempotent ledger compare, cancellation), `Overtime` read service, alerts, tests 16–20; (3) screens, tiles, exports, statement, policy page, translations, UI inventory, route sweep, Livewire + browser tests with agreement; (4) real-data verification, docs, PR.
