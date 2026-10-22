# Tasks: Pilot Polish and Witnessed-Refusal Evidence

**Tests**: REQUIRED. Gates (as sail): `docker compose exec -T -u sail laravel.test php artisan test` ·
`… ./vendor/bin/pint --test`. Never run two test processes at once.

## Run Y
- [x] T001 Arabic user-facing messages across services, Livewire notices and model validation (R1); update tests
- [x] T002 Guard test against English user-facing blocking messages (R2)
- [x] T003 Entry page at `/` (R3) + test
- [x] T004 Witness-signed scan for refused hand-offs: migration, service, component, case page, tests for all three
  hand-off points, rollback leaves no file (R4)
- [x] T005 WEIRDNESS lines (R5)
