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

## R7 Plan review resolutions (Codex review, 2026-10-08) — override R1–R6 where they differ
- **A1 Downloads re-check access**: every download re-runs the scope check of the export's saved parameters for the
  current user (owner, or Platform Admin): the export's company must still be within the user's visibility, a
  person-level export (employee, compliance) requires `CaseVisibility::for` to still admit the company/employee, a
  partner export requires the partnership (and `full_case_access` for person-level). Failing → 404, the export is
  marked `revoked` and its file deleted. Tests: HR staff unassigned after export, partner loses full access, partnership
  removed, user deactivated.
- **A2 Two execution paths**: `company` runs (company_id set) execute inside `runForCompany`, filters validated against
  that company (Platform Admin chooses the company first, so `DashboardFilters` gets the chosen company, not the
  viewer's null company — extend `DashboardFilters::fromInput` with an explicit company argument). `all_companies` runs
  (Platform Admin only, periodic + hotspot only) execute inside `runAcrossAllCompanies`, no branch filter, PDF header
  "منصة Displanery — كل الشركات" instead of a company letterhead, `company_id` null on the export row (allowed only for
  Platform Admin rows; check constraint in the model + test).
- **B1 Historical semantics** (extend `CaseMetrics` with a `periodCounts(viewer, filters)` used by reports; the
  dashboard keeps its live `counts`): open at period end = opened (non-draft) before the period end and not archived
  before it; closed in period = `closed_at` within the period; outcomes / procedure days / % within 15 days = procedure
  completed within the period (010 B1 definitions). Report headers state "حتى نهاية الفترة". Tests compare a past
  quarter against hand-built fixtures.
- **B2 Compliance mapping** from `Deadlines`: procedure — `met` (completed on or before the due day), `overdue`
  (completed after the due day) shown "تأخر الإنجاز", `missed` (open past due) "فاتت المهلة", `on_track/approaching`
  "قيد المهلة", no defense letter → "لم يبدأ"; completion date = `decision_issued_at` or `closed_at` for
  recommendation-only closures; response — from the first `response_handoff_recorded` event per `Deadlines`
  (met / missed / pending). Columns: case number, employee (list-scope only), defense letter date, 15-day due date,
  completion date, procedure status, response due, response status. Boundary tests on the due day.
- **B3 Employee access policy**: new `EmployeeProfilePolicy::view` / search scope = employees of companies the viewer
  can list cases for: company admin / HR staff → own company (HR staff without the add-on see the employee but only
  their visible cases, with the partial note); Platform Admin → any company (company chosen first); HR partner →
  companies with `full_case_access` only; others none. The existing CRUD `EmployeePolicy` is not used as a grant.
  Direct-URL and search tests per role.
- **B4 Export file protocol** (mirrors `GenerateDocumentPdf`): render to a temp file, verify non-empty bytes, publish
  with an exclusive create to `report-exports/{id}.{ext}`, hash, then mark `ready` (`ready_at`, `expires_at =
  ready_at + 7 days`); a job finding the row already ready returns; `failed()` sets `failed` with the error; retries
  are idempotent. Download checks status ready, not expired, path inside `report-exports/`, file exists, hash matches.
  Prune touches only `ready` rows past `expires_at` (never queued/running) → `expired`, file deleted.
- **B5 OpenSpout**: RTL via `OpenSpout\Writer\XLSX\Entity\SheetView` (`setRightToLeft(true)`) applied with
  `$sheet->setSheetView()`; every text value written as an explicit string cell (`Cell\StringCell`) so values starting
  with `=`, `+`, `-`, `@` are never formulas (formula-injection test); ISO date column as text.
- **B6 PDF**: add an optional orientation to `PdfGenerator::generate()` (default portrait unchanged for documents);
  reports use landscape for tables. Detail rows (compliance, employee history, periodic case list) capped at 1,000 in
  PDF and preview with "يُعرض أول ١٬٠٠٠ صف — استخدم Excel للقائمة الكاملة"; Excel uncapped and streamed. SC-001 is
  measured manually by the orchestrator on 5,000 seeded cases, not as a unit-test gate.
- **Nits**: rule name "owner or Platform Admin" everywhere; ISO date column is text; no timing assertions in tests.
- **Product questions** were answered from the existing rules (no new decision needed): revoked access ends download
  rights (CLAUDE.md §8); historical "open" = open at period end (a report about a past period must describe that
  period). Recorded here; the dashboard's live open count is unchanged.
