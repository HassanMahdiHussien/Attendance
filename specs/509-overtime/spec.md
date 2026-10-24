# Feature Specification: Overtime and rest-day compensatory entitlements

**Branch**: `509-overtime` | **Created**: 2026-10-24 | **Status**: Clarified 2026-10-24 (assumptions marked **[A]** are to be checked against the overtime report sample)

**Input**: ROADMAP "later": overtime & compensatory entitlements. PRD §13 (rest days and compensatory leave), §14 (overtime), §18–§20 (profile, dashboard, reports), §25 tests 16–21, §26 (policy details). ROADMAP Q1 (rest days), Q2 (`Compensation Leave` consumes a rest-day entitlement; `Overtime Equivelant Leave` never does), Q8. Builds on 503/506 day results (`present`, `rest_day_worked`, `holiday_worked`, presence and intervals) and 507 holidays.

## Clarifications (product owner, 2026-10-24)

- **Normal working day overtime**: time after the required end (office: the required leaving time, 17:00 / 18:00 after a late arrival; shift/security: shift start + shift length) counts only when it is **at least 30 minutes**, then is **rounded down to 15-minute steps**. Policy values, versioned.
- **Early arrival**: never counted as overtime; it is **marked** on the day (early-arrival minutes before the official start, shown as a marker) so HR can see it. **[A]** "mark" = informational marker only.
- **Rest-day entitlement**: on an eligible compensatory rest day (office Friday, shift Friday, security individual rest day), recorded work **≥ 4 h earns a half-day, ≥ 8 h a full day**. Office Saturday and an official holiday that is not an eligible rest day earn overtime hours only. A holiday on an eligible rest day: hours counted once, entitlement allowed (PRD §13).
- **Overtime report**: this spec calculates overtime and entitlements and consumes entitlements from `Compensation Leave`. Importing and matching the company overtime report is **509b**, built when the sample arrives.

## Requirements

- **FR-001 Overtime calculation** (deterministic, per assessed day, policy-versioned): normal working day → minutes after the required end per the rule above; `rest_day_worked` and `holiday_worked` → all recorded presence minutes (no threshold **[A]**, rounded down to 15 min **[A]**). Lateness and early departure stay separate (PRD §9); overtime never offsets lateness. Pending / unresolved / missing-punch days produce no overtime until resolved. Night shifts belong to their start date (507 rule).
- **FR-002 Early-arrival marker**: minutes before the official start (office 08:00 window start, shift start) recorded on the day as `early_arrival_minutes`, shown as a marker; never in overtime or balances.
- **FR-003 Entitlements**: a qualifying rest day creates one entitlement (employee, work date, quantity 0.5 or 1 day, reason, source day) automatically, with no approval. Recalculation that changes the day changes or removes the entitlement with history (append-only versions); never duplicated.
- **FR-004 Consumption**: an approved `Compensation Leave` request (leave type `consumes = rest_day_entitlement`) consumes available entitlements oldest first, in day quantities (a 0.5-day request may consume half of a full entitlement); each consumption links request ↔ entitlement with an explanation. A request exceeding the available balance is flagged `entitlement_exceeded` for HR review and creates nothing (PRD §25 test 19). Cancellation or rejection established by a later report restores the consumed quantity automatically with history (test 20). `Overtime Equivelant Leave` never consumes an entitlement.
- **FR-005 Unused alert**: an entitlement still available after the end of the following week (Sunday–Saturday week **[A]**) shows an alert; it never expires (test 16).
- **FR-006 Display**: daily record shows overtime minutes, early-arrival marker, entitlement earned; employee page: calculated overtime (period), entitlements available / used / overdue with history; dashboard: overtime hours, entitled employees, available entitlements (counts = lists); reports and exports: an overtime report and a compensatory entitlement report; the statement includes both. English + Arabic.
- **FR-007 Policy**: the attendance policy page gets an Overtime section (minimum minutes 30, rounding step 15, rest-day half/full thresholds 240/480 min, eligible rest days per pattern) — versioned like the other policy values.
- **FR-008 Tests**: PRD §25 tests 16–20; office Friday → overtime + entitlement, Saturday → overtime only (test 17); holiday on an eligible rest day counted once (test 18); 29 min after end → 0, 44 min → 30, 61 min → 60; late arrival with 18:00 required end; shift overtime after shift end incl. the 16:00–00:00 shift; early arrival marked not counted; 3.5 h rest-day work → overtime only, 4 h → half, 8 h → full; reassessment changes an entitlement once; half-day consumption; cancellation restores; isolation; triggers; agreement across page, dashboard and exports.

## Out of scope

Importing and matching the overtime report (509b, PRD test 21); pay amounts; notifications about unused entitlements (510); AI reading of leave descriptions (511).
