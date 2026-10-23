# Feature Specification: Shift and security attendance

**Branch**: `506-shift-and-security` | **Created**: 2026-10-23 | **Status**: Clarified 2026-10-23

**Input**: ROADMAP §5 "later": shift/security inference. Requirements: PRD §6 (work patterns table, shift inference, ambiguity → HR), §6.2 (shift lateness, no grace), §6.3 (crossing midnight), §9 (two time fields, short hours), §10 (missing punches), §11 (leave on shifts), §25 tests 7, 8, 9. Builds on 501–505; reuses the office pipeline of 503 (assessment, review queue, outage rule, coverage gating, recalculation) and 504/505 unchanged on top.

## Rules (PRD §6, product owner 2026-10-23)

- **Shift employees**: three shifts 00:00–08:00, 08:00–16:00, 16:00–00:00; rest day Friday (employee override allowed); declared 30-minute break, **not deducted**; short-hours alert below **8 h** recorded presence.
- **Security employees**: the same three shifts; an individual rest day set per employee (none by default — "rest day missing", not assessed, as in spec 501); no break; short-hours alert below 8 h.
- **Office employees (change to spec 503)**: the short-hours alert threshold becomes **9 h** recorded presence (new policy version). An office absence still counts as **8 h** for leave reconciliation (1 leave day = 8 h), so the absence minutes become a separate policy value (`absence_minutes`, 480) instead of reusing the short-hours threshold.
- **No grace**: arrival after the shift start is late by the minutes after it (08:01 → 1 min). Leaving before the shift end is early departure. Staying later never cancels lateness. Lateness + early departure form the balance (exits only if the company setting counts them), never double-counted, as in 503.
- **Shift inference (no roster)**: for a day with punches, pick the shift whose start is closest to the first check-in **if within ±2 h**, and the last check-out falls within ±2 h of that shift's end; approved leave hours on that day can explain a late start or an early end (e.g. a 4 h leave + check-in at 12:00 fits the 08:00 shift). If **no** shift fits, or **more than one** fits equally, the day is `shift_unresolved`: punches and presence are shown, **no lateness or early departure is confirmed**, and the day goes to the HR review queue to pick the shift; after HR picks, the day is recalculated. The inferred shift, its evidence (first in, last out, leave used) and confidence are stored with the day; an HR choice is an append-only decision.
- **Crossing midnight** (PRD §6.3): a shift belongs to the date it starts; the 16:00–00:00 shift ends at 00:00 of the next day; a 00:00 check-out closes the previous day's evening shift and is never treated as the next day's check-in. The 00:00–08:00 shift belongs to its own date. Punch pairing for a shift day uses punches from shift start − 2 h to shift end + 2 h, so a punch is used by one shift day only.
- **No punches** on a working day (not a rest day, no covering leave): `absent` pending HR review, 8 h, **shift unknown** — the system never claims which shift was missed.
- **Coverage, outage, pending**: as in 503, with the required end = the inferred shift's end (or, for unresolved/absent days, the latest possible end 00:00 of the next day) + grace 2 h.

## Requirements

- **FR-001** Policy: per-company versioned values for shift and security patterns (shift starts, length 480, break 30 informational, short-hours threshold 480, inference tolerance 120, grace 120, default rest day Friday for shift); office policy gains `absence_minutes` (480) and its short-hours threshold becomes 540 by a new version effective from 2000-01-01 (recalculating affected days).
- **FR-002** Assessment: the 503 `DayAssessor` assesses shift and security employees with a pure `ShiftDayCalculator` (same output shape as office days + `shift` fields); cross-midnight punch windows; rest days per pattern and employee override; security without a rest day → `pattern_missing` (reason `rest_day_missing`).
- **FR-003** Inference per the rules above, deterministic, with stored evidence and confidence (`clear` | `leave_explained` | `unresolved`).
- **FR-004** HR review: the 503 review queue also lists `shift_unresolved` days; actions "Pick shift" (with reason) and "Withdraw"; plus the existing add missing time / confirm absence for shift days.
- **FR-005** Leave (504) and reporting (505) work for shift/security days unchanged: absence targets 480; late/early targets from the inferred shift; unresolved days produce no late/early targets until HR picks.
- **FR-006** Screens: daily attendance and day detail show the shift (inferred / HR-chosen / unknown) and its evidence; the office policy page becomes "Attendance policy" with office, shift and security sections; dashboard/employee figures include shift and security employees.
- **FR-007** Tests: PRD §25 7 (cross-midnight belongs to its start date, a shared 00:00 transaction is not duplicated), 8 (partial leave is not forced to the start or end of a shift without evidence), 9 (ambiguity → HR, recalculated after HR picks); 08:01 → 1 min late; early departure; each shift incl. 00:00–08:00; evening shift with a 00:00 check-out; leave-explained late start; two-shift tie → unresolved; no punches → absent 8 h shift unknown; security rest day missing; shift short hours < 8 h, office short hours < 9 h with absence still 8 h for leave; outage and coverage for night shifts; recalculation keeps history; isolation.

## Out of scope

Rosters and shift swaps (no roster exists); overtime and rest-day entitlements (508); holidays (507); notifications (509).
