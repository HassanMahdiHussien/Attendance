# Tasks: Design System, Sidebar Layout and Mobile

**Tests**: REQUIRED. Gates (as sail): `docker compose exec -T -u sail laravel.test php artisan test` ·
`… ./vendor/bin/pint --test`. Never run two test processes at once.

plan.md R7 (review resolutions) overrides earlier sections.

## Run D1
- [x] T001 Generate `tests/fixtures/ui-inventory.json` from the CURRENT views + BehaviourInventoryTest (R7 A1) — first,
  before any view changes
- [x] T002 UI components + icon set (R1, R7 B3/B5), Tone helper (R2)
- [x] T003 Workspace + guest layouts: sidebar, top bar, bottom tab bar + More sheet, Nav helper with bounded counts,
  toasts (R3, R7 A2/B4)
- [x] T004 Filament: translated brand name, favicon (R5, R7 nit)
- [x] T005a Rebuild cases board + my-cases + pending-approvals
- [x] T005b Rebuild show-case (incl. hand-offs, witness scan, response, decision signatures, consultation, appeal)
- [x] T005c Rebuild create-case
- [x] T005d Rebuild confirm-document (keep §18 distinct document card) + edit-document + edit-recommendations +
  edit-decision-letter
- [x] T005e Rebuild record-session + record-appeal + review-approval + review-consultation + signed-scan
- [x] T006 Component + nav tests; markup-assertion updates per R7 B2

## Run D2
- [x] T007 Rebuild home, reports (inbox, my-reports, submit-report), employees (index, page), dashboard (hr-dashboard,
  case-drilldown — HR and partner routes), statistics, login/guest
- [x] T008 Fixes from the screenshot matrix; WEIRDNESS line for any deliberate deviation from the mockup
