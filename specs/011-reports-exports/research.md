# Research: Reports, Exports and the Employee Page

## R1 One source of figures
- Reports call `App\Services\Dashboards\CaseMetrics` / `Hotspots` (010) with `DashboardFilters` built from the report
  parameters; new methods only where a report needs a list the dashboard does not have (compliance rows per case,
  employee history). Compliance rows use `Deadlines` for due dates and statuses (met = completed ≤ due; missed =
  completed > due or still open after due; open = not yet due).

## R2 Employee page
- `EmployeeProfile` service: identity from `employees` (company scope), cases from `CaseVisibility::for($viewer)
  ->where('employee_id', …)`, counters over those cases (total, warnings, last 12 months by incident date), open case =
  newest non-draft, non-archived case; issued documents per case (locked, latest version per type) with the existing
  PDF download route. A note when the viewer's visibility is partial (HR staff without the add-on).
- Page structure: a header card + "السجل الانضباطي" section; sections rendered from a small ordered list so later
  modules can add sections (CLAUDE.md §5) without restructuring — no other sections now.
- Employee search `/employees`: name / employee number / department, paginated, company scope; links.

## R3 Exports
- `report_exports` table (tenant: company_id nullable only for Platform Admin all-company runs; requested_by,
  report_type, parameters json, format pdf|xlsx, status, file_path, file_sha256, error, ready_at, expires_at).
- `ReportExporter::request(User, type, params, format)` validates parameters against the requester's scope (R4),
  creates the row, dispatches `GenerateReportExport` (queue, $timeout 300). The job sets the tenant context, re-checks
  scope as of generation, renders, writes `storage/app/private/report-exports/{id}.{ext}`, hashes, marks ready.
- PDF: Blade report views (`resources/views/reports/*.blade.php`, Amiri/Noto fonts embedded as documents do,
  letterhead header, CONFIDENTIAL footer, A4 landscape for wide tables) → the existing `PdfGenerator` (JS off, network
  blocked). Row cap 1,000 with a note.
- Excel: OpenSpout writer (`OpenSpout\Writer\XLSX\Writer`), one sheet per section, `setRightToLeft(true)` on sheets
  (check the 4.32 API in vendor), header style bold, streamed rows.
- Download route `/statistics/exports/{export}` — owner or Platform Admin only, status ready, audit
  `report_export.downloaded`; otherwise 404.
- Scheduler: `report-exports:prune` daily — deletes files past `expires_at`, sets status expired.

## R4 Scope per role
- Company admin / HR staff: their company, `CaseVisibility::for` (HR staff limited set; the periodic and hotspot
  reports show the same partial note as the dashboard).
- Platform Admin: company selector (any company) or all companies for periodic/hotspot.
- HR partner: periodic + hotspot for partner companies through `CaseVisibility::aggregateFor` (labels, no people);
  employee + compliance only for companies with `full_case_access` (via `CaseVisibility::for`).
- Others (reporter, committee, reviewer): no access.

## R5 Screens
- `/statistics` (nav "التقارير الإحصائية" for the roles above): report type tabs, period (month / quarter / custom),
  branch (and company for Platform Admin / partners), employee picker for the employee report, preview, "تصدير PDF" /
  "تصدير Excel", "صادراتي" list with status polling and download links.
- Employee links: case page header (employee name), board cards, drill-down rows.

## R6 Tests
- Employee page counts and visibility per role; partial note; documents links only issued.
- Report figures equal dashboard figures for the same filters; compliance met/missed/open boundaries.
- Export lifecycle (queued → ready, failure → failed, prune → expired), owner-only download (other user 404, other
  company 404), audit entries, partner scope (no names in partner exports; employee/compliance refused without full
  access), PDF generated with Browsershot faked at the boundary used by existing PDF tests, Excel file readable by
  OpenSpout reader with RTL sheet and expected rows; 5,000-case Excel export completes within the test timeout.
