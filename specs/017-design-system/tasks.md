# Tasks: Design System, Sidebar Layout and Mobile

**Tests**: REQUIRED. Gates (as sail): `docker compose exec -T -u sail laravel.test php artisan test` ·
`… ./vendor/bin/pint --test`. Never run two test processes at once.

plan.md review resolutions (when added) override earlier sections.

## Run D1
- [ ] T001 UI components + icon set (R1), Tone helper (R2)
- [ ] T002 Workspace + guest layouts with sidebar, top bar, bottom tab bar, Nav helper with counts (R3)
- [ ] T003 Filament theme + translated brand (R5)
- [ ] T004 Rebuild cases board, case page and its sub-screens (show-case, create-case, confirm-document, edit-*,
  record-session, record-appeal, review-*, signed-scan, pending-approvals, my-cases) (R4)
- [ ] T005 Component tests (R6)

## Run D2
- [ ] T006 Rebuild reports (inbox, my-reports, submit-report), employees, dashboard (hr-dashboard, case-drilldown),
  statistics, login (R4)
- [ ] T007 Remaining polish found in the screenshot pass; WEIRDNESS line if any deliberate deviation from the mockup
