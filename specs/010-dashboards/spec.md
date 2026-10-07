# Feature Specification: Dashboards

**Feature Branch**: `010-dashboards-reports`

**Created**: 2026-10-07

**Status**: Draft

**Input**: User description: "Dashboards (CLAUDE.md §24.1, §8, §10c): the per-company HR dashboard (live cases with
deadline alerts, counters, hotspots with trends and drill-down, threshold alerts — visibility only), the Platform
Admin dashboard (all companies, activation and subscription status), the HR-partner overview (stats only; case
detail only where the company opted in) and the external legal reviewer's consultation dashboard. Reports and
PDF/Excel exports are feature 011."

## Clarifications

### Session 2026-10-07

- Q: Split? → A: Product owner: 010 dashboards, 011 reports + exports (Excel via OpenSpout, installed in 011).
- Q: Charts? → A: No chart library: ranked lists with simple proportional bars and a trend arrow (CLAUDE.md §13
  simple code; nothing in the design calls for charts).
- Q: Stats-only HR partner — labels? → A: Category labels with counts (violation type, department, branch, location),
  never people or case numbers (product owner, 2026-10-07).
- Q: "% within 15 days"? → A: Measured to the decision issue date, or the no-penalty closure; not the final archive
  (product owner, 2026-10-07).
- Q: Who sees what? → A: Every number respects the viewer's case visibility (CLAUDE.md §8): HR staff without the
  "see all company cases" add-on see only their own cases; company admins see the company; the Platform Admin sees
  everything; HR partners see counts for their partner companies only.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - HR sees what needs attention today (Priority: P1)

The HR dashboard opens with the alerts first: cases near or past the 15-day deadline, response deadlines, pending
external consultations (with how long they have been waiting) and appeal windows ending soon — each linking to the
case. Below: counters (open, closed this month, by outcome, average days to close, % closed within 15 days) for the
selected branch (or all branches) and period.

**Acceptance Scenarios**:
1. **Given** a case whose 15-day due day ends within 48 hours and no decision yet, **Then** it appears under the
   15-day alert in red with the time left (same rule as the case page).
2. **Given** an HR staff user without the "see all" add-on, **Then** every alert and counter counts only their cases.
3. **Given** a branch filter, **Then** every number changes to that branch.

### User Story 2 - HR sees where problems repeat (Priority: P1)

Hotspots list, for a chosen period (default the last 6 months), the top entries by branch, department, location
inside the site, violation type, alleged responsible person and employee, each with its count and the change versus
the previous equal period. Clicking any number opens the list of the cases behind it. A hotspot crossing the
company's threshold (default 3+ cases of the same violation type in the same department, or the same location,
within 90 days) shows a dashboard alert. Visibility only: nothing is opened, sent or changed automatically.

**Acceptance Scenarios**:
1. **Given** 3 lateness cases in the warehouse department in 60 days, **Then** a threshold alert names the department,
   the violation type and the count, linking to the cases.
2. **Given** the company sets the threshold to 4 cases in 30 days (company settings), **Then** the alert follows it.
3. **Given** free-text places not mapped to a location, **Then** they are grouped under "موقع غير محدد" and do not
   create location hotspots.

### User Story 3 - The Platform Admin sees every company (Priority: P2)

The Platform Admin dashboard lists every company with its activation state, subscription status, open cases, cases
past the 15-day deadline, pending consultations and the date of the last case activity, plus platform totals;
activating / deactivating a company and changing the subscription status stay where they are today (company
resource), linked from each row.

### User Story 4 - HR partners and reviewers see their part (Priority: P2)

An HR-partner user sees, for each partner company, the counters and hotspot counts (no names); the case list behind a
number is available only for companies with full case access. An external legal reviewer sees the consultations
routed to their firm across companies: waiting, completed this month, and the oldest waiting with its 15-day
deadline.

### Edge Cases

- No cases yet: every section shows an empty state, no division-by-zero.
- Cases opened before a branch moved jurisdiction or departments renamed: grouping uses the values stored on the case /
  employee at query time (department from the employee record).
- Large companies: dashboard queries stay aggregate (no per-case loops); the drill-down list is paginated.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The HR dashboard MUST be available to company admins and HR staff, with a branch filter and a period
  filter (default 6 months), and every figure computed only over cases the viewer may see.
- **FR-002**: Alerts MUST include: 15-day deadline approaching (≤ 48 hours, configurable) or passed without a decision;
  response deadline approaching/passed; pending external consultation with waiting time; appeal window ending soon.
- **FR-003**: Counters MUST include open cases, closed this month, closed by outcome (recommendation only / warning /
  termination), average days from defense letter to decision (or closure), and % closed within 15 days.
- **FR-004**: Hotspots MUST rank, for the period, branch, department, location, violation type, alleged responsible
  person and employee, each with count and change versus the previous equal period.
- **FR-005**: Every number MUST link to the list of cases behind it (same visibility rules), paginated.
- **FR-006**: Threshold alerts MUST use per-company settings (default: 3 cases, 90 days, same violation type in the same
  department or same location), editable by the company admin; alerts are visibility only.
- **FR-007**: The Platform Admin dashboard MUST show every company's activation, subscription status and key counts,
  with platform totals.
- **FR-008**: The HR-partner overview MUST show counts per partner company and the case list only where
  `full_case_access` is granted.
- **FR-009**: The external reviewer dashboard MUST show waiting and completed consultations of their firm with waiting
  time and the case's 15-day deadline.
- **FR-010**: All labels in Arabic, numbers in Arabic-Indic digits, dates in the Iraqi format, the design tokens of
  CLAUDE.md §18.

### Key Entities

- **Company dashboard settings**: hotspot threshold count, window days, deadline-approaching hours (company-level).
- **Dashboard query service**: one service computing alerts, counters and hotspots for a viewer + filters.

## Success Criteria *(mandatory)*

- **SC-001**: The HR dashboard loads in under 2 seconds for a company with 5,000 cases.
- **SC-002**: No dashboard number ever includes a case the viewer could not open (automated tests per role).
- **SC-003**: Every counter equals the number of cases in its drill-down list.

## Assumptions

- "Closed" = archived_final; "decision" = decision issued or closed without penalty.
- Department comes from the employee record; location from the case location (free text → "موقع غير محدد").
- The 48-hour business alert email (CLAUDE.md §10c) already exists (case alerts, feature 003); the dashboard only
  displays.
