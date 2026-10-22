# Feature Specification: Multi-Employee Incidents

**Feature Branch**: `040-multi-employee-incidents`

**Created**: 2026-10-15

**Status**: Draft

**Input**: Product owner (2026-10-15): "sometimes there is one case but more than one name in the same issue, so there must be a defense for each one but related to the same case."

## Clarifications

### Session 2026-10-15

- Q: One case for several employees, or one case each? → A: **One case per employee**, grouped under one **incident**. Law 37/2015 art. 140 gives each worker the right to defend himself; every legal clock (3-day response, 15-day procedure from that employee's own `defense_letter_issued_at`, 30-day appeal), document, signature and decision stays per employee and per case, exactly as today.
- Q: What does the incident share? → A: The facts entered once (CLAUDE.md §6 single source of truth): branch, location / place, occurred_at, violation type, description, alleged responsible person, witnesses, attachments, the originating report. Each case keeps its own employee, number, status and documents. Editing a shared fact before any defense letter in the incident is issued updates all its cases; after an issuance, shared facts are frozen for the issued cases (their documents are immutable anyway) and a correction follows the existing per-case correction path.
- Q: Committee? → A: **One session for the incident, separate decisions.** HR/the committee records one session (one set of attendees, one minutes note) that applies to every case of the incident that is ready for its session; each employee still gets their own outcome, own vote/majority, own Doc A (recommendations, own three approvals) and own Doc B. Quorum (3 attendees incl. a labor-union member) and conflict-of-interest checks apply per case. A case not yet ready (e.g. still awaiting its response) is simply left out and can get its own session later.
- Q: Who starts one? → A: **Reporter and HR.** A report can name several employees; converting it creates one case per named employee in the same incident. HR can also pick several employees on "New case". Duplicate-report detection, prior-cases banners and repeat-responsible-person checks run per employee as today.
- Q: Changes after opening? → A: **Add yes, remove by closing.** HR can add an employee later (a new case in the same incident, same shared facts, own clock from its own defense letter). An employee is "removed" only by closing their case with a reason through the existing closure path — never by deleting or detaching.
- Q: Relation to the existing `related` case links (feature 022)? → A: Cases of one incident are shown as an "Incident" group on each case page and board card; manual `related` links stay for different incidents. No `case_links` rows are created for incident membership (the incident itself is the link).
- Q: Documents? → A: Each defense letter, Doc A and Doc B names only its own employee. The incident's other employees are never printed in an employee's documents (privacy); the AI drafting payload for one case does not include the other employees' names or responses.

## User Scenarios & Testing *(mandatory)*

1. **Given** a supervisor reports a fight naming 3 employees, **When** HR converts the report, **Then** 3 draft cases are created in one incident with the facts copied once, each case number distinct, and each case page shows the incident group with links to the other two.
2. **Given** HR opens "New case" and selects 2 employees, **Then** 2 cases are created in one new incident.
3. **Given** an incident of 3 cases where 2 have recorded responses, **When** a committee member records the incident session, **Then** the session applies to those 2 cases (each gets its own attendance rows, outcome and vote) and the third is listed as "not ready".
4. **Given** shared facts are edited before any defense letter is issued, **Then** all cases of the incident show the new facts; **after** one case's defense letter is issued, that case keeps its facts and the edit is refused for it.
5. **Given** an incident, **When** HR adds a 4th employee, **Then** a new case is created with the incident's facts; **When** HR closes one employee's case with a reason, **Then** the other cases continue unaffected.
6. **Given** a user who can see only some cases of an incident (role scoping, CLAUDE.md §8), **Then** the incident group shows only the cases that user may see.
7. **Given** a defense letter is drafted (AI or manual) for one employee, **Then** neither the document nor the AI payload contains the other employees' names.

## Requirements *(mandatory)*

- **FR-001**: `incidents` table (tenant-scoped, `company_id`, branch, created_by, created_at) and nullable `cases.incident_id`; a case belongs to at most one incident; incident membership is append-only (no detach); audit-logged.
- **FR-002**: Reports may name several employees (`violation_report_employees` pivot or equivalent; existing single `employee_id` keeps working); conversion creates one case per named employee in a new incident; email-forward drafts unchanged (one employee, may be added to an incident by HR).
- **FR-003**: "New case" allows selecting several employees; creates one incident and one case each through the existing `CaseOpening` path (all existing checks per case).
- **FR-004**: Shared facts editable once for the incident while no case in it has `defense_letter_issued_at`; afterwards refused for issued cases (per-case correction path unchanged).
- **FR-005**: "Add employee to incident" (HR) creates a new case with the incident facts; "remove" = existing case closure with reason.
- **FR-006**: Incident committee session: one form records attendees + minutes once and, per eligible case, its outcome and votes; creates the same per-case rows the single-case session creates today, in one transaction; per-case quorum, conflict and pool-branch checks; cases not in the session-ready state are listed and skipped.
- **FR-007**: Case page and board show the incident group (other cases' numbers, employees, stages) filtered by the viewer's visibility; employee page unchanged except the incident badge.
- **FR-008**: Documents and AI payloads stay per employee; no other employee's name or response is included (test).
- **FR-009**: Translations en/ar; behaviour inventory for new controls; Western digits; Departures design (DESIGN.md).

## Success Criteria

- An incident with N employees needs the facts typed once and the committee session recorded once.
- Every legal rule (clocks, quorum, three approvals, immutability, delivery/witness rules) still holds per case; the existing suite passes unchanged.
