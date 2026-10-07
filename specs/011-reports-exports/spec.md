# Feature Specification: Reports, Exports and the Employee Page

**Feature Branch**: `011-reports-exports`

**Created**: 2026-10-08

**Status**: Draft

**Input**: User description: "Reports and exports (CLAUDE.md §24.2): periodic summary (monthly/quarterly), per-employee
report, compliance report (deadlines met vs missed), hotspot report — each exportable as PDF and Excel, respecting the
viewer's role scope. Includes the employee page (CLAUDE.md §5, §24.3), which does not exist yet and is the screen
behind the per-employee report."

## Clarifications

### Session 2026-10-08

- Q: Excel library? → A: OpenSpout (product owner, 2026-10-07), installed `^4.32`.
- Q: Employee page scope? → A: Disciplinary history only, structured so other HR sections can be added later
  (CLAUDE.md §5): identity, current open case and stage, counters (total cases, warnings, cases in the last 12
  months), and every prior case with date, violation type, outcome and links to its issued documents.
- Q: Where do reports live? → A: A new "التقارير الإحصائية" page (`/statistics`); `/reports` already belongs to
  violation reports.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - HR opens an employee's page (Priority: P1)

From the case page, the board or an employee search, HR opens the employee page: name, employee number, department,
position, branch, hire date; the open case (if any) with its stage; counters; and the full case history with links to
each case and its issued documents. Only cases the viewer may see are listed.

**Acceptance Scenarios**:
1. **Given** an employee with 3 closed cases (one warning) and one open case, **Then** the page shows total 4,
   warnings 1, the open case with its stage, and 3 history rows with their outcomes.
2. **Given** an HR staff user without "see all", **Then** only their visible cases are listed and counted, with a note
   "قد توجد حالات أخرى لا تظهر لك".

### User Story 2 - HR produces and exports a report (Priority: P1)

On "التقارير الإحصائية", HR picks a report (periodic summary, employee report, compliance, hotspots), its period
(month, quarter or custom) and branch, previews it on screen, and exports it as PDF or Excel. Exports are prepared in
the background; the page shows the export as "جارٍ الإعداد" and then a download link. Files are kept 7 days.

**Acceptance Scenarios**:
1. **Given** a quarterly summary for the Hilla branch, **Then** the preview and both exports contain the same
   figures as the dashboard for the same filters.
2. **Given** the compliance report, **Then** it lists each case of the period with its 15-day due date, completion
   date and met / missed / still open, and the response deadline met / missed, with totals.
3. **Given** an export older than 7 days, **Then** the file is removed and the row says "انتهت صلاحية الملف".
4. **Given** another user's export, **Then** it cannot be downloaded (404).

### User Story 3 - Platform Admin and HR partners (Priority: P2)

The Platform Admin can run every report for any company (company selector). An HR partner can run the periodic
summary and the hotspot report for partner companies — counts and category labels only (as on the dashboard); the
employee and compliance reports (which name people and cases) only where the company granted full case access.

### Edge Cases

- Empty period: reports render with zeros and an explanatory line; exports still succeed.
- Large exports: Excel streams rows (no memory blow-up for 5,000 cases); PDF has a row cap (1,000 rows) with a note to
  use Excel for the full list.
- Arabic in Excel: sheets right-to-left, Arabic headers, dates as Iraqi-format text plus an ISO date column for sorting.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: Employee page at `/employees/{employee}` and an employee search at `/employees`, for company admins and
  HR staff (and Platform Admin / full-access partners through their existing visibility), case data via
  `CaseVisibility::for`.
- **FR-002**: Four reports with on-screen preview: periodic summary (counters, outcomes, procedure days, % within 15
  days, top hotspots), employee report (the employee page content), compliance (per case 15-day and response
  deadlines met/missed/open with totals), hotspots (all six groupings with trends).
- **FR-003**: Figures MUST come from the dashboard's metrics service with the same definitions (feature 010) — no
  second implementation of the counters.
- **FR-004**: Export to PDF (existing headless-Chrome generator, company letterhead header, Arabic RTL) and Excel
  (OpenSpout, RTL sheets), as queued jobs; files on the private disk; one export row per request with status, owner,
  parameters and file hash; download only by its owner (and the Platform Admin); auto-deleted after 7 days.
- **FR-005**: Report scope follows the requester's role at generation time (aggregate-only partners get counts and
  labels only; person-level reports require list visibility).
- **FR-006**: Every export request and download is written to the audit log.
- **FR-007**: Links to the employee page from the case page header, board cards and the drill-down list.

### Key Entities

- **Report export**: requester, company (or all for Platform Admin), report type, parameters, format, status
  (queued/ready/failed/expired), file path, hash, error, created/ready/expires times.

## Success Criteria *(mandatory)*

- **SC-001**: A quarterly summary PDF for 5,000 cases is ready in under 1 minute; Excel in under 30 seconds.
- **SC-002**: Report figures equal the dashboard's for the same filters (automated test).
- **SC-003**: No export contains a case or person the requester could not see (tests per role).

## Assumptions

- PDFs use the company's Arabic letterhead (name, logo) as a header; the CONFIDENTIAL footer as on documents.
- Exports run on the existing queue worker; cleanup runs from the scheduler daily.
