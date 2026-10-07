# Research: Dashboards

## R1 One query service
- `App\Services\Dashboards\CaseMetrics` takes a viewer (User) and filters (company, branch, period) and builds every
  figure from `CaseVisibility::for($viewer)` (the existing, test-guarded visibility path; no new scope bypasses),
  using aggregate SQL (`count`, `groupBy`) — no per-case PHP loops. Drill-down = the same base query + the grouping
  condition, paginated, so counter = list size by construction (SC-003).
- Platform Admin: all companies through the existing all-companies tenant context; HR partner: an explicit
  company-id list from `company_hr_partner` (stats), case lists only where `full_case_access`; reviewer: the
  existing reviewer visibility (consultations of their firm).

## R2 Alerts
- Reuse `Deadlines` (procedureDue, responseDue, appealEnd, statuses) for consistency with the case page. To keep it
  aggregate, candidate cases are selected in SQL by date windows (e.g. defense_letter_issued_at between now-15d and
  now-13d with no decision) and then labelled with `Deadlines` (small sets).
- Consultation waiting = `legal_review_requested_at` set, `legal_review_completed_at` and `cancelled_at` null.

## R3 Counters
- Outcome from `cases.outcome` for closed cases; days to close = `decision_issued_at` (or `closed_at`) minus
  `defense_letter_issued_at`; within 15 days = that difference ≤ 15 calendar days (CLAUDE.md §3).

## R4 Hotspots and thresholds
- Group keys: branch (branch_id), department (employees.department), location (location_id; null → "موقع غير
  محدد", excluded from location thresholds), violation type (cases.violation_type), alleged responsible
  (alleged_responsible_employee_id, else free text normalised by trim/lowercase), employee (employee_id).
- Trend: same counts for the previous period of equal length; arrow up/down/flat and the delta.
- Threshold settings stored on `companies` (new columns `hotspot_min_cases` default 3, `hotspot_window_days` default
  90, `deadline_alert_hours` default 48), edited in Filament CompanySettings by the company admin, audited.
- Threshold alert = (violation type, department) or (violation type, location) pairs with count ≥ min within the
  window ending today.

## R5 Screens
- HR: new Livewire page `/dashboard` (workspace layout, mockup tokens, Tajawal, JetBrains Mono for numbers in
  references), nav item "لوحة المتابعة" for company admin / HR staff; the board stays at `/cases`. Filters in the
  query string. Bars = div widths proportional to the max count.
- Drill-down: `/dashboard/cases?metric=…&key=…&period=…&branch=…` listing cases (case number, employee, type,
  status pill, dates) using the board's card style.
- Platform Admin: Filament Platform dashboard widget/page "نظرة عامة على الشركات" (table + totals), rows link to the
  company resource.
- HR partner: `/home` becomes the partner overview (cards per company: counters + top hotspots, counts only; "عرض
  الحالات" only with full access).
- Reviewer: `/my-cases` gains a header summary (waiting, completed this month, oldest waiting with its deadline).

## R6 Tests
- Visibility matrix (company admin, hr_staff with/without add-on, platform admin, HR partner with/without full access,
  reviewer, reporter, committee member, other company) for every section and the drill-down; counter = drill-down
  count; alert windows at the edges (exactly 48 h, passed); hotspot trend and threshold settings; empty state;
  a performance smoke test seeding ~2,000 cases asserting the page renders and query count stays bounded.
