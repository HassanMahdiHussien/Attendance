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
