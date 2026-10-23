# Tasks: Employee attendance page, dashboard, search, exports and statement

**Input**: [spec.md](spec.md), [plan.md](plan.md) — "Revisions after Codex plan review" overrides earlier plan text. Each step ends green in WSL (Unit, Feature, Browser, Pint).

## Step 1 — Contracts and summary service
- [x] T001 `ReportFilters` (period, employee, branch, department, manager, work pattern, status; validated, serialisable)
- [x] T002 `AttendanceSummary` canonical projection (revisions 1–3), streamed detail rows
- [x] T003 `LastUpdate` freshness + coverage quality (revision 4)
- [x] T004 Projection tests with 503/504 fixtures: categories, gross/reconciled/remaining by kind, credit rules, leave status grouping, pending exclusion, historical branch, meals/issues scoping, isolation

## Step 2 — Screens, access and search
- [x] T005 Employee page attendance section (tiles + drill-downs, re-authorised)
- [x] T006 Dashboard (HR + platform admin via company picker), tiles → employee lists with the same filters
- [x] T007 Search: `search_name_en` + code, branch/department shown
- [x] T008 Translations, UI inventory, route sweep, help; Livewire + browser smoke tests

## Step 3 — Exports and printable statement
- [x] T009 `attendance_exports` + events, `GenerateAttendanceExportJob`, retry, cleanup, download route + policy + audit
- [x] T010 Excel reports (FR-005) and PDF statement (ar/en)
- [x] T011 Three-surface agreement test + export/PDF tests + tenancy/trigger tests

## Step 4 — Verification (orchestrator)
- [x] T012 Real data on the smoke DB (HC + punches, all office for the test): summary 490 present / 225 absent / 401 missing punch / 1,014 pending; dashboard and employee page checked in a browser; lateness xlsx (444 employees), employee xlsx and PDF statements (ar + en) generated; polish from the browser check (searchable employee filter, HH:MM times, labels). Docs; PR
