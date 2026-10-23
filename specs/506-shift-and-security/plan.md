# Implementation Plan: Shift and security attendance

**Branch**: `506-shift-and-security` | **Date**: 2026-10-23 | **Spec**: [spec.md](spec.md)

## Summary

Extend the 503 pipeline from office-only to all three work patterns. A pure `ShiftDayCalculator` (mirroring `OfficeDayCalculator`) infers the shift per day from punches and approved leave, computes lateness/early departure/presence with no grace, and defers ambiguous days to HR. Cross-midnight days pair punches in a shift-centred window so no punch is used twice. Policies gain shift/security sections and the office short-hours threshold changes to 9 h with a separate 8 h absence value.

## Technical Context

Same conventions as 501–505 (WSL, attendance namespaces, Codex implements, gates in WSL). Reuse: `DayAssessor` (enumeration, coverage, outages, locking, superseding, change log), `DayReview` (decisions), `CoverageWindows`, `OutageDetector`, `AttendanceSummary`, 504 reconciler targets.

## Constitution Check (2.0.0)

I: new tables/columns tenant-scoped + isolation tests. II: pure deterministic calculators, versioned policy; no AI. III: punches untouched; HR shift choices append-only; days superseded with change log. IV: PRD §25 7–9 + rules above. V/VI: one more calculator, shared assessor; WEIRDNESS lines for no-grace, ±2 h inference, 9 h office threshold vs 8 h absence.

## Design

- **Schema** (`*_attendance_*`): policy columns — office `absence_minutes` (default 480), shift section (`shift_starts` json ["00:00","08:00","16:00"], `shift_minutes` 480, `shift_break_minutes` 30, `shift_min_presence_minutes` 480, `shift_rest_days` [5]), security section (`security_min_presence_minutes` 480), `inference_tolerance_minutes` 120; a migration appends a new policy version per company effective 2000-01-01 with office `min_presence_minutes` 540 and `absence_minutes` 480 (append-only: new version, higher number wins on the same date), then dispatches reassessment. `attendance_days` gains `shift_start` (time nullable), `shift_source` (`inferred|hr|unknown`), `shift_confidence` (`clear|leave_explained|unresolved`), `shift_evidence` (json). `attendance_day_decisions` gains decision `pick_shift` with `shift_start`.
- **ShiftDayCalculator** (pure): inputs = date, pattern, policy, employee schedule values, punches in window [date 00:00 − 2 h, date+1 00:00 + 2 h] (the assessor passes candidates; the calculator assigns), manual punches, decisions (pick_shift, confirm_absence), approved leave minutes for the day (from 504 request days), coverage/outage flags, now. Steps: candidate shifts → score each by |first in − start| and |last out − end| (each ≤ tolerance, leave minutes may shift the fitting edge by up to the leave length) → unique best = inferred; tie/none → unresolved. Then the 503 rules with S = shift start, end = S + 480, no flex, no grace.
- **Punch ownership across days**: the assessor assigns each used punch to at most one shift day: process an employee's dates in order; a punch consumed by day D's resolved shift window is not offered to D+1. Unresolved days consume punches between their first and last punch only.
- **Leave interaction**: approved covering leave minutes for the day (504 request days, current) are an input to inference only; reconciliation stays in 504 (targets from the day).
- **Assessor**: `DayAssessor` routes by pattern (office → OfficeDayCalculator; shift/security → ShiftDayCalculator); enumeration covers all three patterns; required end for coverage/close = inferred end, or next-day 00:00 for unresolved/absent days.
- **Review**: `DayReview::pickShift(employee, date, shiftStart, reason, actor)`; review queue lists `shift_unresolved`.
- **Screens**: shift column + evidence in daily attendance and day detail; "Attendance policy" page with sections; translations.

## Steps
1. Schema + policy version + `ShiftDayCalculator` with exhaustive unit tests (pure).
2. Assessor routing, punch ownership across days, leave input, review pick-shift, 504/505 integration, feature tests (PRD §25 7–9, office 9 h / 8 h absence).
3. Screens + translations + tests.
4. Verification on the smoke DB (HC + punches with a synthetic pattern split: e.g. Supply Chain = shift, Security-like units = security, others office), docs, PR.

## Risks
- Real patterns unknown until the work-pattern file; verification uses an assumed split.
- ±2 h windows overlap between adjacent shifts (e.g. a 10:00 arrival fits 08:00 start within tolerance but not 16:00) — ties only when equidistant; tested.
