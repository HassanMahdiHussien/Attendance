# Tasks: Shared employee record for attendance

**Input**: [spec.md](spec.md), [plan.md](plan.md)

Each run ends green in WSL `~/attendance`: `php artisan test --compact` (Unit, Feature, Browser) and `./vendor/bin/pint --test`. Every diff is checked for `\r\n` mangling, mixed `@php` forms and CRLF in `lang/*`.

## Run 1 — Data layer and every existing write path

- [ ] T001 Migration: `employee_import_batches` (append-only triggers)
- [ ] T002 Migration: new `employees` columns; `position` and `department` nullable; `exit_review_batch_id` FK after T001
- [ ] T003 Migration: `employee_versions` (sparse `changes` + `previous`, append-only triggers) and one `initial` version per existing employee
- [ ] T004 Migration: `employee_import_mappings`
- [ ] T005 Models, factories, casts (`rest_days` as list of ISO weekdays, `work_pattern` and `exit_status` enums)
- [ ] T006 `EmployeeHistory::asOf` (per field) and `record` (changed fields only, recompute today's row, event, audit)
- [ ] T007 `EmployeeVersionChanged` event
- [ ] T008 `ExitReview` (start, confirm, dismiss; deactivation the day after the last working day)
- [ ] T009 Command `employees:apply-effective-changes`, scheduled daily
- [ ] T010 Filament employee form: new fields, effective date, reason; saves through `EmployeeHistory`
- [ ] T011 Replace the Deactivate action with exit review; exit review admin page
- [ ] T012 Existing CSV import commit writes through `EmployeeHistory` (source `import`); `department`/`position` optional
- [ ] T013 Employee page "Changes" list
- [ ] T014 Translations (en + ar), nav, UI inventory
- [ ] T015 Tests: `asOf` (before/on/after, back-dated, future-dated), schedule-end-only, append-only, isolation, composite FKs, exit review, day-after deactivation, command idempotency, existing import tests updated

## Run 2 — Import engine and import screen

- [ ] T016 `SpreadsheetReader` (csv + xlsx, field-aware conversion)
- [ ] T017 `ColumnMapping` + `config/employee_import.php` aliases (HC headers, Arabic aliases); saved mappings by header signature
- [ ] T018 Preview: modes, effective date, mapping, new/changed/unchanged, missing employees, manager resolution, warnings, errors, already-imported
- [ ] T019 Commit: confirmation token, hash idempotency, stored file, batch, versions, pending-review and reappearance, one transaction
- [ ] T020 Original file download route + policy + audit
- [ ] T021 Import page: upload → mapping step → preview → commit
- [ ] T022 Help article, translations, UI inventory
- [ ] T023 Tests: HC-shaped xlsx fixture generated with openspout, CSV, idempotency, preview == commit, missing employee never deactivated, reappearance, leading zeros / long IDs / serial dates / `#N/A`, download access, route sweep, browser import flow

## Run 3 — Docs and verification (orchestrator)

- [ ] T024 `FOUNDATION-CHANGES.md`, `WEIRDNESS.md`
- [ ] T025 Preview the real `HC.xlsx` on the dev DB; report counts, warnings and errors to the product owner before committing it
- [ ] T026 Open PR
