# Tasks: Multi-Employee Incidents

- [x] T001 Migration + models: `incidents`, `cases.incident_id` (immutable once set), `violation_report_employees`; tenant FKs; factories.
- [x] T002 `IncidentService`: create from report / from selected employees (through `CaseOpening`), add employee, update shared facts (refused after issuance), visible members.
- [x] T003 Reports: submit with several employees; conversion creates the incident and one case each.
- [x] T004 New case: multi-employee selection.
- [x] T005 Incident committee session: service + Livewire, per-case outcome/votes, quorum/conflict per case, not-ready cases listed.
- [x] T006 UI: incident group on case page and board card (visibility-filtered), add-employee action, shared-facts edit; translations en/ar; behaviour inventory.
- [x] T007 Privacy: documents and AI payloads per employee only.
- [x] T008 Tests (plan), Pint, full suite.
