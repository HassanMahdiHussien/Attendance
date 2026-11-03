# Feature Specification: Clear screens along one HR journey

**Branch**: `514-hr-clear-screens` | **Created**: 2026-11-03 | **Status**: Approved direction 2026-11-03 (Design 1, "clear table").

**Input**: Product owner, 2026-11-03: "I need interface be more friendly and clear ... I must read and thinking ... all dashboard must be more friendly and clear and easy to read"; then, choosing Design 1: "check all project must be same, you know there is must user journey".

## Context

- The daily record is a stack of paragraphs: entry and exit share one value, durations read `4:14`, and absent days show lines of zeros. HR has to read and work out each day.
- The dashboard opens with eight filter fields and an export form; the figures HR acts on come third, as 30+ equal tiles.
- Screens were built spec by spec, so each one has its own table, status wording and time format. 20 screens use a hand-written table, none uses `x-ui.table`, and only 4 use `x-ui.badge`.
- **This spec changes presentation only.** No calculation, stored value, permission or export changes. Every figure comes from the existing services, and every detail shown today stays reachable.

## The HR journey

1. **Upload** the files.
2. **Check** anything the upload needs: new devices, unmatched employees, unmapped leave types.
3. **Wait** until results are ready, with a clear signal that they are.
4. **See what needs action** on the dashboard.
5. **Open the employee** from a count.
6. **Understand the day**: the row says what is wrong; details and punches are one click away.
7. **Export** the result.

## Shared rules (every screen)

- **SR-1 Order**: page title and one-line context, then what needs action, then the list. Filters, export and rarely used figures fold behind a labelled control.
- **SR-2 Tables**: lists use `x-ui.table`, one row per thing, one fact per column, with stacked cards on phones.
- **SR-3 Status**: statuses are pills with one word set and one colour per meaning: red = needs action, ochre = partly wrong, navy = data needs checking, green = fine or covered, grey = nothing expected.
- **SR-4 Durations**: read as `4 h 14 min` / `4 س 14 د` through one formatter, never `4:14` and never raw minutes. Clock times are JetBrains Mono.
- **SR-5 Dates**: people read a weekday and date (`Thursday 1 October`). ISO dates stay only where HR compares with a source file (batches, punches).
- **SR-6 Empty values**: a value that does not apply shows `—`, not `0:00`.
- **SR-7 Amber**: at most the current journey step and the one primary action (DESIGN.md Two Amber Points). The selected period and filter use navy.
- **SR-8 Language**: every string in `en` and `ar`; Arabic renders RTL with times kept LTR.

## Phases (one PR each)

### Phase 1: shared parts, daily record, dashboard (this PR)

- **FR-001 Duration formatter**: one service used by all new markup; localised; handles 0 and negative balances.
- **FR-002 Day presentation**: a service turning one `AttendanceSummary::perDay` row into a display status, tone, needs-review flag, late/early flags and plain problem text. It reads existing values only.
- **FR-003 Display status**: a present day with uncovered late, early, exit or short minutes shows **Short**; an absent or short day fully covered shows **Covered by leave**; otherwise the status keeps its existing label.
- **FR-004 Daily record**: columns Day, Status, In, Out, Worked, What is wrong? The time that causes a problem is red. Each row expands in place to show today's detail (elapsed, presence, leave and credit coverage, explanations, overtime, shift, holiday) with links to day detail and punches.
- **FR-005 Default filter**: Needs review (n) is selected by default, next to All days (n). Choosing a tile shows all days matching that tile, as today.
- **FR-006 Employee summary**: four tiles (Days present, Absent without leave, Late and early leaving, Not covered by leave). All other figures stay under "More figures". Custom dates and export fold away.
- **FR-007 Dashboard**: journey strip, then "Needs action" tiles that open the matching employee list, then folded "More figures", "More filters", "Export" and "Data status". Search stays visible.
- **FR-008 Journey strip**: Upload, Check, Results, Review on the dashboard, with the current step and the one next action. Built on the design's route rail.
- **FR-009 Honest "updating"**: results count as updating while this company's import or calculation jobs are queued or running, so the Results step does not say ready too early.

### Phase 2: lists

Daily, review queue, issues, punches, devices, follow-up cases, leave requests, credits, batches, exports, employee list, dashboard employee list; day detail and leave request detail read as SR-1 to SR-6. Navigation grouped by journey step.

### Phase 3: upload and set-up

Upload centre, the four upload pages, holidays, office policy, leave types, approver mappings, notifications, company settings, help, assistant.

## Acceptance

- Same employee and period: every figure on the new screens equals the old screen and the export (CLAUDE.md §7.9).
- A day absent with full leave is not in Needs review; a day absent without leave is.
- A pending day is never shown as absent or short (CLAUDE.md §4).
- Full suite, browser tests and Pint pass; checked in English and Arabic, at desktop and phone width.
