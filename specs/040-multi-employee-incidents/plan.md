# Implementation Plan: Multi-Employee Incidents

**Branch**: `040-multi-employee-incidents` | **Spec**: [spec.md](spec.md)

## Existing code (read first)
- Case lifecycle: `app/Services/Cases/CaseOpening.php` (open a draft → checks: duplicates, conflicts, prior cases, responsible-person pattern), `CaseWorkflow.php` (the only writer of status), `CaseClosure.php`, `CaseLinks.php` (manual `related` links, feature 022), `CommitteeSessionService.php` (+ `app/Livewire/Cases/RecordSession.php`), `DefenseLetterFlow.php`, `Deadlines.php`.
- Reports: `app/Services/Reports/ReportService.php`, `app/Livewire/Reports/SubmitReport.php`, `Inbox.php` (conversion), `app/Livewire/Cases/CreateCase.php`.
- Schema: `database/migrations/2026_10_04_201000_create_domain_tables.php` (`cases`, `violation_reports`, `committee_session_attendances` unique per case+member), composite tenant FKs pattern, append-only triggers pattern.
- AI payload builders under `app/Ai/*` (masking, minimal payload).
- UI: `resources/views/livewire/cases/show-case.blade.php`, `cases/board.blade.php`, DESIGN.md.

## Design
- **Schema**: `incidents` (id, company_id, branch_id, created_by_user_id, timestamps, unique(company_id,id)); `cases.incident_id` nullable with composite tenant FK; `violation_report_employees` (company_id, violation_report_id, employee_id, unique pair). Keep `violation_reports.employee_id` for single-employee reports (first named employee mirrored there for back-compat). A DB trigger or model guard refuses changing a non-null `cases.incident_id`.
- **Service** `app/Services/Cases/IncidentService.php` (business logic out of Livewire, CLAUDE.md §19): `createFromReport`, `createForEmployees`, `addEmployee`, `updateSharedFacts`, `members(viewer)`. Each case is still created/opened through `CaseOpening` so every check runs per employee.
- **Session**: `CommitteeSessionService::recordForIncident(incident, attendees, minutes, perCase[outcome, votes], actor)` loops the eligible cases calling the existing per-case logic inside one transaction; returns skipped cases with the reason. Livewire: incident mode of `RecordSession` (or a small `RecordIncidentSession` component) reached from any eligible case page.
- **Facts**: shared fields are stored on each case (as today) and copied by `updateSharedFacts`; refusal for cases with `defense_letter_issued_at`. No new read path for documents.
- **Visibility**: `members(viewer)` filters with `CaseVisibility::for($viewer)`.
- **Privacy test**: build the defense/recommendations payload for one case of an incident and assert other members' names are absent.
- Audit/case events: `incident.created`, `incident.employee_added`, `incident.facts_updated`, `incident.session_recorded` (append-only loggers already exist).

## Tests
Report with 3 employees → 3 cases in one incident; New case with 2 employees; shared-facts edit before/after issuance; add employee; closing one case leaves others; incident session applies to ready cases only with per-case quorum and conflict refusal; tenancy (foreign incident id rejected, composite FK); visibility filtering of the group; privacy of payloads/documents; incident_id immutability; translations, behaviour inventory, browser smoke for the incident panel, Pint, full suite.
