# Implementation Plan: Employee attendance page, dashboard, search, exports and statement

**Branch**: `505-employee-page-and-dashboard` | **Date**: 2026-10-23 | **Spec**: [spec.md](spec.md)

## Summary

One aggregate read service (`AttendanceSummary`) over the current derived tables feeds four surfaces — employee page section, dashboard, Excel exports, printable PDF — so their numbers agree by construction. No new derived data; only read models, screens, an export pipeline (attendance-owned, since spec 500 removed Displanary's) and a PDF.

## Technical Context

Same conventions as 501–504 (WSL, attendance namespaces, Codex implements, gates in WSL). Excel via openspout (installed); PDF via Browsershot (installed, ADR 0005; headless Chrome present in WSL per `deploy/dev-setup.sh`). Queue: database driver.

## Constitution Check (2.0.0)

I: every query scoped by company + policies; exports/PDFs private and tenant-checked. II: figures computed only by deterministic services. III: read-only over source and derived data; exports are new files with an audit row. IV: agreement test (PRD §25 test 26). V: one summary service, thin Livewire/Filament.

## Design

- `App\Services\Attendance\Reporting\AttendanceSummary` — input: company, employee scope (ids or filter set: branch, department, manager, work pattern, status), period; output: plain arrays (totals + per-employee rows + per-day rows on request). Built with grouped SQL over current `attendance_days`, `leave_allocations`, `leave_credits`, `leave_request_days`, `attendance_meals`, `data_issues`, with historical `branch_id` on days for branch filters. Cached per (company, filters, period, latest run id) as arrays.
- `ReportFilters` value object (validated, serialisable) shared by pages, exports and PDF.
- `LastUpdate` read model: latest committed batch per source + latest processing/assessment/reconciliation run; coverage quality per branch for the period (covered days / working days).
- Employee page section: Livewire component in `App\Livewire\Attendance\EmployeeAttendance` embedded in the existing employee page (shared foundation view: one include line, logged in FOUNDATION-CHANGES); tiles + drill-down tabs.
- Dashboard: replaces the home/HR dashboard placeholder (shared foundation views, logged); tiles → employee list page with the same filters.
- Exports: `attendance_exports` table (company, requested_by, kind, filters json, locale, status, file_path, rows, error, timestamps; status log append-only), `GenerateAttendanceExportJob`, download route with policy + audit + route sweep; openspout writer with a header block.
- PDF: Blade view `attendance.statement` (ar/en, RTL for ar, fonts per DESIGN.md) rendered by Browsershot in a queued job into the same export table (kind `statement_pdf`).
- Search: reuse `GlobalSearch` (employees only since spec 500); verify spelling tolerance and branch/department disambiguation; add tests.

## Steps (Codex → my review → gates → commit)
1. `ReportFilters`, `AttendanceSummary`, `LastUpdate` + exhaustive tests (agreement fixtures across 503/504 data, pending exclusion, branch history).
2. Employee page section + dashboard + drill-down lists + search tests.
3. Exports + PDF statement (jobs, downloads, header blocks, ar/en).
4. Verification on the smoke DB with real HC + punches + leave (render the dashboard and an employee page in the browser, generate an export and a PDF), docs, PR.

## Risks
- Browsershot needs Chrome in the queue worker's environment (present in WSL dev; production provisioning already installs it).
- With most work patterns unknown, real-data figures stay mostly "pattern missing" until the work-pattern file arrives.


## Revisions after Codex plan review (2026-10-23) — these override the sections above

1. **Metric contract (one projection).** `AttendanceSummary` exposes one canonical projection used by the employee page, dashboard, exports and PDF, defined per day from current rows only (`superseded_by_run_id IS NULL`):
   - **Day categories (mutually exclusive)** among assessed days: `present`, `absent`, `missing_punch`. Excluded from the assessed denominator and counted separately: `pending`, `rest_day`, `not_employed`, `pattern_missing`, `device_outage`.
   - **Minutes by target kind** (`absence`, `late`, `early`, `exit` when counted): `gross` (from the day), `reconciled_by_leave` (current allocations from leave days), `reconciled_by_credit` (current allocations from credits), `remaining = gross − reconciled_by_leave − reconciled_by_credit` (never negative). Credit use is counted once, only as `reconciled_by_credit`.
   - **Flags** shown alongside, never added to minutes: `hr_confirmed_absence`, `absence_closed_by_request`, `short_hours`, `nothing_to_reconcile`.
2. **Leave and credit period rules.** Leave totals count **distributed employee working days** (`leave_request_days` current rows with `work_date` in the period) grouped by request type and **current** request status; pending/draft/rejected requests are listed separately and never counted as covered days. Credits: `created` = current credits whose source day is in the period; `used_in_period` = current credit allocations whose target day is in the period; `available_at_period_end` = credits with source day ≤ period end, not expired at period end, minus current allocations to targets ≤ period end.
3. **Meals and issues scoping.** Meals: employee meals filter by the employee set; non-employee meals appear only in company/branch views (grouped by department label). Data issues: **attributable** counts (issue subject resolves to an employee code or request of the set) vs **company-wide** counts (devices, grouped outages, unmapped types) shown separately; outage issues attribute to their branch for branch filters.
4. **Freshness and coverage.** `LastUpdate` shows separately: last committed import per source (employees, punches, leave), last cleanup run, last assessment run, last reconciliation run, and flags "results updating" when a source batch is newer than the derived run that consumed it. Coverage quality per branch = assessable working days ÷ expected working days in the period, computed with the assessor's own coverage rule (`CoverageWindows` + required end + grace), not raw intervals.
5. **Search and filters.** Search matches normalised Arabic name, English name and employee code (add a normalised `search_name_en` column + index; foundation change logged) and shows current branch and department. Dashboard and lists add the **employee** filter (PRD §19) next to period, branch, department, manager, work pattern, status.
6. **Access.** Dashboard is for HR (own company) and platform admins with an explicit company picker (platform admins land on `/platform` and reach a company dashboard through it); every Livewire component (incl. the nested employee attendance section) re-authorises tenant + role on every request.
7. **No summary cache in this spec**; detail rows are streamed in chunks from grouped queries; year exports stream per month per 100 employees.
8. **Export pipeline.** `attendance_exports` (company_id, `requested_by` nullable plain FK to users — platform admins have no company, so no composite FK — kind, filters json, locale, file_path, row_count, timestamps) + `attendance_export_events` (append-only status log with per-export sequence: queued, running, completed, failed with error code). Retry action re-dispatches idempotently; files older than 30 days are deleted by a scheduled cleanup (row kept, file_path cleared, event logged). Private download route with policy (same company HR or platform admin) + audit + route sweep. Excel: header block, typed text cells for codes/dates, supporting rows with linked request numbers and explanations; PDF uses the same projection. Tenancy, trigger, download-route, UI-inventory, translation tests. Every shared foundation file changed is logged in FOUNDATION-CHANGES.

**Steps (final):** (1) metric/filter contracts + `AttendanceSummary` + `LastUpdate` with exhaustive projection tests; (2) employee section + dashboard + drill-downs + access + search + filters, with translations and UI inventory; (3) export schema/jobs/downloads/policies + PDF + the three-surface agreement test; (4) real-data verification, docs, PR.
