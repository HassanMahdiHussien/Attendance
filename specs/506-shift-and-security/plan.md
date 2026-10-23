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


## Revisions after Codex plan review (2026-10-23) — these override the sections above

1. **Policy versions.** For every company and **every existing policy version**, the migration appends a replacement version with the same `effective_from`, a higher `version`, all of that version's values preserved, plus `min_presence_minutes` 540 (office), `absence_minutes` 480 and the shift/security defaults. Validation, defaults (`OfficePolicy::DEFAULTS`) and casts include the new fields. Reassessment covers each affected interval. This runs in **step 2**, together with its consumers.
2. **Absence minutes everywhere.** `Reconciler`, `LeaveAllocator` targets and `AttendanceSummary` read the historical `absence_minutes` (office 480; shift/security 480) instead of `min_presence_minutes`. `LeaveDistributor` uses the **effective pattern's** rest days (office policy rest days, shift Friday, security the individual day) and withholds distribution for security employees without a rest day (`rest_day_missing` flag).
3. **Inference rule (exact).** Tolerance `T` = 120 min; shift `s` has start `S`, end `E = S + 480`.
   - Two punches (first in `a`, last out `b`): `s` fits when `|a − S| ≤ T` and `|b − E| ≤ T`; score `|a − S| + |b − E|`; the unique lowest score wins (`clear`); equal lowest scores or no fit → see leave rule, else `unresolved`.
   - **Leave evidence** (approved covering request day with 240 min on that date): `s` fits as `leave_explained` when exactly one edge is off by 240 ± T and the other edge fits within T (late start → leave at the start; early end → leave at the end — the punches are the evidence). If both a start-explanation and an end-explanation fit, or more than one shift fits this way → `unresolved` (never forced). A full-day (480) approved leave with punches → `nothing_to_reconcile` path of 504, shift by the two-punch rule.
   - **One punch**: only a check-in → `s` fits when `|a − S| ≤ T`, unique → inferred, day `missing_punch` with lateness shown; only a check-out → fits when `|b − E| ≤ T`, unique → inferred, `missing_punch`; otherwise `unresolved` + `missing_punch`. HR can pick the shift and add the missing time or confirm absence.
4. **Punch ownership across midnight.** The assessor loads punches for `[from − 1 day 00:00, to + 1 day 02:00]` and always assesses scopes widened by one day on each side. Ownership is decided per employee in date order: (a) a check-in in `[22:00 previous day, 02:00]` belongs to the `00:00` shift of the date whose midnight is nearest; (b) a check-out in `[22:00, 02:00 next day]` belongs to the previous date's `16:00` shift when that date has an unmatched check-in at or after `14:00`, otherwise to the date it falls on; (c) every other punch belongs to its calendar date; (d) a punch is owned by exactly one day. Manual punches may carry a next-day timestamp for an evening shift's check-out.
5. **Inference inputs and loops.** After a reconciliation run, `Reconciler` compares each employee's current leave request days (date → minutes, status) with the previous run's; only when they changed does it dispatch `AssessDaysJob` for those dates (±1 day). `DaysAssessed` → reconcile → no change → stop, so there is no loop.
6. **Night coverage and outage.** For shift/security days the coverage and close window is `[shift start, shift end + grace]`; for unresolved or absent days `[date 00:00, next day 02:00]`. `OutageDetector` checks approved check-in device punches in the same window, with device settings effective at that time. Reporting groups by the day's `work_date`, never by punch calendar date. Tests for night-shift outage, absent night days and coverage quality.
7. **Statuses everywhere.** `shift_unresolved` is added to the review gate, review queue, daily attendance and report status filters, translations, day detail, exports and `AttendanceSummary` (counted with assessed days only after HR picks; until then shown under "needs shift"). `pick_shift` decisions are withdrawn individually without touching other decisions on the day.
8. **Prefetch.** Per chunk of ≤ 100 employees: punches for the widened range, decisions, manual punches, current leave request days and versions in one query each; inference and ownership in memory. Tenancy, trigger, UI inventory and translation tests extended for the new columns, decision kind and screens.

**Steps (final):** (1) additive schema (new policy columns with defaults, day shift columns, decision kind) + pure `ShiftDayCalculator` with exhaustive unit tests — no change to office results; (2) policy replacement versions + `absence_minutes` consumers (504/505) + assessor routing, ownership, prefetch, inference inputs, night coverage/outage, reassessment, feature tests; (3) statuses, review pick-shift, screens, translations, exports; (4) verification with an assumed pattern split on real data, docs, PR.


## Addendum (product owner, 2026-10-23): default work pattern rule and two more HC columns

- **Two more employee fields from HC** (shared foundation; versioned like the other fields): `staff_category` from `AG HR Collar` (`B` blue collar | `W` white collar) and `legal_entity` from `Company Code` (e.g. `Al Waha Company`, `Sardkar for Bvr. Ind/Ltd`). Both get default aliases in `config/employee_import.php` and appear in the employee form. `legal_entity` is needed by spec 507 (the Sovereignty Day 2026 announcement covers "CCI Iraq Al-Waha employees" only, i.e. Hilla, Karbala and Baghdad, not Erbil/Sardkar).
- **Default pattern rule** (applied only to employees whose `work_pattern` is empty, after each employee import and once for existing employees; recorded through `EmployeeHistory` with source `rule` and the reason; any HR edit overrides it and the rule never changes a pattern that is already set):
  1. position contains "Security" (case-insensitive) → `security` (rest day still to be set by HR, so these employees show "rest day missing" until then);
  2. else `staff_category` = `B` → `shift`;
  3. else `staff_category` = `W` → `office`;
  4. else no pattern (stays "pattern missing").
  Positions containing "Information Security" or "Security Manager" are set by rule 1 but also raise a `pattern_review` data issue for HR to confirm.
- The rule lives in config (`config/attendance.php` → `pattern_rules`) so other companies can change it.
- In the real HC file: 49 security-titled positions, 535 blue collar (all Supply Chain), ~431 white collar.
- Tests: rule order; existing pattern never overwritten; HR edit after rule wins; import of a later file does not re-apply the rule to employees HR changed; flagged positions raise the issue; history shows source `rule`.
