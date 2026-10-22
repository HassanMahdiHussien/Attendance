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

## R7 Plan review resolutions (Codex review + product owner, 2026-10-07) — override R1–R6 where they differ
- **Product owner decisions**: (1) a stats-only HR partner may see category labels with counts (violation types,
  departments, branches, locations) but never people (employee or alleged responsible person names, case numbers);
  (2) "% within 15 days" measures the legal procedure: from the defense letter to the decision letter's issue date,
  or to the no-penalty closure for recommendation-only cases — not the final archive.
- **A1 Aggregate-only access path**: add `CaseVisibility::aggregateFor(User $viewer): Builder` in
  `app/Support/Access/CaseVisibility.php` (the only sanctioned bypass file; ScopeBypassGuardTest stays green). For an
  HR partner it returns cases of every company linked in `company_hr_partner` (any `full_case_access`); for an
  external reviewer, cases of partnered companies whose consultation was requested (any status, incl. archived); for
  every other role it equals `CaseVisibility::for()`. It may be used **only** by count/group aggregate methods of
  `CaseMetrics` that return no case ids, numbers or names; every case list, drill-down and link keeps using
  `CaseVisibility::for()`. A test asserts the drill-down for a stats-only partner is forbidden.
- **A2 Reviewer summary**: "consultations completed this month" = cases from `aggregateFor(reviewer)` with
  `legal_review_completed_at` in the month (archived cases included); "waiting" = requested, not completed, not
  cancelled; the oldest waiting row links to the case only when `CaseVisibility::for()` admits it.
- **A3 Deadline definitions = `Deadlines`**: the 15-day due date is the Baghdad calendar day 15 after issuance;
  "approaching" = within the configured hours before the end of that day; SQL only preselects a wide candidate set
  (defense letter issued within the last 30 days, procedure not completed) and `Deadlines` classifies it. The spec
  example becomes "a case whose due day ends within 48 hours". Boundary tests: exactly 48 h, due day itself, one day
  after.
- **B1 Counter definitions** (drafts excluded everywhere):
  - procedure completed at = `decision_issued_at` (penalty) or `closed_at` for recommendation-only closures;
  - open = not draft and not `archived_final` (current, not period-filtered);
  - closed this month = `archived_final` with `closed_at` in the current Baghdad month;
  - by outcome / average days / % within 15 days = cases whose procedure completed within the period; average days
    = completed date − defense letter date (Baghdad dates); within 15 days = completed date ≤ the `Deadlines` due date;
    cases without a defense letter date are excluded from average and % (denominator shown).
- **B2 Drill-down scope**: only count figures are drillable (counter = list size, tested); a percentage shows its
  numerator and denominator as two drillable counts; averages and trend deltas are not drillable; stats-only partner
  figures are never drillable. URL parameters are re-validated and the list re-authorised.
- **B3 Time axis**: hotspots, trends and their drill-downs use `violation_time` (incident date) with Baghdad
  half-open periods [start, end) and the previous period of equal length immediately before; alerts ignore the period
  filter (always current); counters follow B1.
- **B4 Hotspot keys**: violation type key = `LOWER(TRIM(violation_type))` with internal whitespace collapsed — the same
  SQL expression for grouping and drill-down; alleged responsible = employee id, else the same normalised free text;
  department = the employee's current department, labelled "القسم الحالي"; locations by `location_id`, unmapped free
  text grouped as "موقع غير محدد" and excluded from location thresholds. A controlled violation-type list is out of
  scope (noted for later).
- **B5 Alert states**: pending consultation excludes `legal_review_cancelled_at`; the response alert uses
  `Deadlines::responseStatus` (so a recorded response hand-off clears it); appeal alerts only for
  `awaiting_appeal_window`. The per-company `deadline_alert_hours` column is dropped from the plan: dashboard and
  notifications keep the single global config (`displanery.deadlines.approaching_hours`) so they always agree. Per-
  company settings: `hotspot_min_cases` (3), `hotspot_window_days` (90) only. `CaseAlertDispatcher`'s missing
  cancelled-consultation check is fixed in the same run (one-line, with a test).
- **B6 Performance**: migration adds composite indexes `cases(company_id, status)`, `(company_id, violation_time)`,
  `(company_id, branch_id, violation_time)`, `(company_id, defense_letter_issued_at)`; a test seeds 5,000 cases and
  asserts the dashboard renders with a bounded query count; the orchestrator runs `EXPLAIN` on the main queries.
- **B7 Last activity** = latest `case_events.created_at` per company, one grouped query.
- **Nits**: Platform overview is a widget on the existing Filament platform dashboard (`/platform`); Arabic-Indic digits
  are display-only (case numbers keep JetBrains Mono; stored values and URLs unchanged).
