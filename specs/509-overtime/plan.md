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
