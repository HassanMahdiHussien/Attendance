# Tasks: Company Setup Checklist Before the First Case

**Tests**: REQUIRED. Gates (as sail): `docker compose exec -T -u sail laravel.test php artisan test` ·
`… ./vendor/bin/pint --test`. Never run two test processes at once.

plan.md R6 (review resolutions + product-owner answers) overrides earlier sections.

## Run G
- [ ] T001 Migration + backfill for the regulation answer (R1)
- [ ] T002 CompanySetup service (R2) with tests
- [ ] T003 CaseOpening gate (R3) with tests
- [ ] T004 Filament widget, CompanySettings regulation question, home card/notice (R4) with tests
- [ ] T004b Shared test helper completeCompanySetup + update the case-opening tests; seeders complete the dev company (R6 B2/B3)
- [ ] T005 Translations, WEIRDNESS line (existing companies derived; approval not required to open cases)
