# Tasks: Shared employee record for attendance

**Input**: [spec.md](spec.md), [plan.md](plan.md)

Each run ends green in WSL `~/attendance`: `php artisan test --compact` (Unit, Feature, Browser) and `./vendor/bin/pint --test`. Every diff is checked for `\r\n` mangling, mixed `@php` forms and CRLF in `lang/*`.

## Run 1 — Data layer and every existing write path

- [x] T001 Migration: `employee_import_batches` (append-only triggers)
- [x] T002 Migration: new `employees` columns; `position` and `department` nullable; `exit_review_batch_id` FK after T001
- [x] T003 Migration: `employee_versions` (sparse `changes` + `previous`, append-only triggers) and one `initial` version per existing employee
- [x] T004 Migration: `employee_import_mappings`
- [x] T005 Models, factories, casts (`rest_days` as list of ISO weekdays, `work_pattern` and `exit_status` enums)
- [x] T006 `EmployeeHistory::asOf` (per field) and `record` (changed fields only, recompute today's row, event, audit)
- [x] T007 `EmployeeVersionChanged` event
- [x] T008 `ExitReview` (start, confirm, dismiss; deactivation the day after the last working day)
- [x] T009 Command `employees:apply-effective-changes`, scheduled daily
- [x] T010 Filament employee form: new fields, effective date, reason; saves through `EmployeeHistory`
- [x] T011 Replace the Deactivate action with exit review; exit review admin page
- [x] T012 Existing CSV import commit writes through `EmployeeHistory` (source `import`); `department`/`position` optional
- [x] T013 Employee page "Changes" list
- [x] T014 Translations (en + ar), nav, UI inventory
- [x] T015 Tests: `asOf` (before/on/after, back-dated, future-dated), schedule-end-only, append-only, isolation, composite FKs, exit review, day-after deactivation, command idempotency, existing import tests updated

## Run 2 — Import engine and import screen

- [x] T016 `SpreadsheetReader` (csv + xlsx, field-aware conversion)
- [x] T017 `ColumnMapping` + `config/employee_import.php` aliases (HC headers, Arabic aliases); saved mappings by header signature
- [x] T018 Preview: modes, effective date, mapping, new/changed/unchanged, missing employees, manager resolution, warnings, errors, already-imported
- [x] T019 Commit: confirmation token, hash idempotency, stored file, batch, versions, pending-review and reappearance, one transaction
- [x] T020 Original file download route + policy + audit
- [x] T021 Import page: upload → mapping step → preview → commit
- [x] T022 Help article, translations, UI inventory
- [x] T023 Tests: HC-shaped xlsx fixture generated with openspout, CSV, idempotency, preview == commit, missing employee never deactivated, reappearance, leading zeros / long IDs / serial dates / `#N/A`, download access, route sweep, browser import flow

## Run 3 — Docs and verification (orchestrator)

- [ ] T024 `FOUNDATION-CHANGES.md`, `WEIRDNESS.md`
- [x] T025 Preview the real `HC.xlsx` (smoke DB, not committed): 1,015 rows, 0 errors, 20 warnings (manager code not in the file), 995 manager links, all codes 8-digit text, 726 Arabic names + 289 English fallbacks
- [ ] T026 Open PR
