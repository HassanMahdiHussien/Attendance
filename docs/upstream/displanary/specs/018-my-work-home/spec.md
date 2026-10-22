# Feature Specification: "My Work" Home for Each Role

**Feature Branch**: `018-my-work-home`

**Created**: 2026-10-08

**Status**: Draft

**Input**: Product owner, 2026-10-08: "make UI more useful" → chose a smarter home: "a 'my work today' home for each
role: deadlines due, items waiting for me, quick actions, recent activity". Approved mockup screen "Home · my work"
(`docs/design/ui-refresh-mockup.html`).

## Clarifications

### Session 2026-10-08

- Q: Which roles? → A: Every workspace role gets `/home` as its landing page with content for that role (HR / company
  admin, reporter / department manager, committee member, external legal reviewer, HR partner). Platform Admin keeps
  the platform panel.
- Q: Does the home do anything new? → A: No new workflow. It only gathers what already exists and links to the screen
  where the work is done. Deadlines use the existing calendar-day rules (CLAUDE.md §3); alerts are visibility only.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - HR starts the day on one page (Priority: P1)

HR opens the app and sees: a greeting with today's date; four tiles (open cases, cases within 48 h of the 15-day
deadline without a decision, recommendations waiting on committee approvals, % closed within 15 days over 6 months —
each clickable to the matching list); "Needs your action": one row per case or report needing HR, sorted by urgency,
each with the reason (e.g. "Record the employee's paper response — due today"), a badge for the deadline and one
button that opens the right screen; quick actions; hotspot alerts; recent activity on cases they can see.

**Acceptance Scenarios**:
1. **Given** a case in `defense_letter_ready_for_review`, **Then** it appears as "Review and issue the defense letter"
   with a button to the confirmation screen.
2. **Given** a case whose 15-day deadline is within 48 hours and no decision issued, **Then** it is at the top with a
   red badge.
3. **Given** a new reporter report or email draft in the inbox, **Then** it appears as "New report to review".
4. **Given** HR staff without "see all cases", **Then** only their own cases appear (same scope as the case board).
5. **Given** nothing needs HR, **Then** an empty state says so.

### User Story 2 - Every other role sees its own work (Priority: P1)

- **Committee member**: recommendations waiting for *their* approval category (eligible per the pool rules), cases
  they attended, upcoming nothing else.
- **External legal reviewer**: consultations waiting for their firm's review, with the 15-day clock.
- **Reporter / department manager**: their reports with bare status (received / under review / closed) and a
  "Submit a report" button — never case details (CLAUDE.md §8).
- **HR partner**: the existing cross-company overview, restyled as this home.

**Acceptance Scenarios**:
1. **Given** a reporter, **Then** the home never shows case numbers, employee names of cases, documents or committee
   notes — only their own reports' bare status.
2. **Given** a committee member whose category already approved, **Then** that document is not listed for them.

### User Story 3 - The home is the landing page (Priority: P1)

After login, every workspace role lands on `/home` (Platform Admin → platform panel as today). The sidebar's first
item is "My work".

## Requirements *(mandatory)*

- **FR-001**: A `NextAction` service maps a case (status, deadlines, documents, consultation, appeal) to zero or one
  action: who must act (HR / committee / reviewer / nobody), a translated label, an urgency (red / amber / indigo /
  gray), a due date if any and the target URL. It is the single source used by the home (018) and the case page's
  next-step card (019).
- **FR-002**: Every list on the home uses the existing visibility rules (`CaseVisibility`, `ViolationReportVisibility`,
  `EmailDraftVisibility`, pool eligibility) — no new access paths.
- **FR-003**: Tiles link to existing lists with matching filters (case board / dashboard drill-down).
- **FR-004**: Recent activity: last 10 case events on cases the user can see, with translated labels (existing
  timeline formatter), never event payload details beyond what the case page shows that role.
- **FR-005**: Both languages, phone layout, design-system components only.

## Success Criteria *(mandatory)*

- **SC-001**: For each role, the home lists exactly the items the role's existing screens would show (tests per role).
- **SC-002**: A reporter's home never contains case data (automated test).
- **SC-003**: Home renders with a bounded number of queries (no per-case query loops; asserted in a test).

## Assumptions

- "Today" uses Asia/Baghdad.
