# Tasks: Attendance transaction import and punch cleanup

**Input**: [spec.md](spec.md), [plan.md](plan.md) — the "Revisions after Codex plan review" section overrides earlier plan text.

Each step ends green in WSL `~/attendance` (Unit, Feature, Browser, Pint). Every diff is checked for `\r\n` mangling, mixed `@php` forms and CRLF in `lang/*`.

## Step 1 — Schema and streaming import (runnable without processing)

- [x] T001 Migrations (`*_attendance_*`): devices, device settings, import batches, batch events, import mappings, transactions, transaction sources, people, coverage intervals, data issues; composite FKs; append-only triggers for batches, batch events, transactions, sources, device settings
- [x] T002 Models (`App\Models\Attendance`), factories, `config/attendance.php` (non-employee department patterns, cleanup gap, preview sample limit)
- [x] T003 `TransactionFile`: streaming read, header-row detection (skip title row), date/time parsing (strings and Excel serials) in Asia/Baghdad, export time from file name
- [x] T004 `TransactionImport::preview` (counts, bounded samples, duplicates in file and against stored, devices, unknown IDs, suggested coverage, errors) and `commit` (token, hash idempotency, file + batch, declared coverage intervals, `ImportTransactionsJob` after commit)
- [x] T005 `ImportTransactionsJob`: chunked, idempotent inserts of transactions (natural key) and sources (raw values per row), batch events with sequence, final counts from tables
- [x] T006 `DeviceRegistry::register` (pending device + suggested role/site), `PersonClassifier` (employee / non-employee / unknown by code and department label), `DataIssues` (open/resolve/dismiss, `open_key`)
- [x] T007 Tests: parsing, title row, export-time coverage, idempotent and overlapping uploads, raw values kept per source row, classification and issues, retry of a failed chunk, isolation, composite FKs, append-only triggers

## Step 2 — Cleanup, meals and re-processing

- [x] T008 `PunchCleanup` (FR-006 rules, gap limit, deterministic) and `MealCounter`; processing runs; per-person locking and superseding
- [x] T009 `ProcessAttendanceJob` dispatched after import; `DeviceRegistry::approve/change/retire` with re-processing scope; `PersonClassifier` listener on `EmployeeVersionChanged` and employee creation
- [x] T010 Tests: PRD §25 1, 3, 4, 5; pending device unused until approval; device change rebuilds neighbouring groups and meal days; employee added later reclassifies and reprocesses; re-run idempotent; concurrent runs leave one current result

## Step 3 — Screens

- [x] T011 Attendance upload page (upload → mapping on new headers → coverage → preview → commit → progress)
- [x] T012 Batches list with original-file download (policy + audit); devices page; data issues page; punch viewer
- [x] T013 Nav group, translations (en + ar), help article, UI inventory, route sweep mappings
- [x] T014 Tests: Livewire upload flow, permissions and isolation per page, download access, browser smoke test of the pages

## Step 4 — Docs and verification (orchestrator)

- [x] T015 `WEIRDNESS.md`, `FOUNDATION-CHANGES.md` (only shared files)
- [x] T016 Real sample through preview and commit on the smoke DB (with HC employees): 4,828 rows, 907 in-file duplicates, 3,921 transactions, 472 employees / 102 non-employees / 4 unknown (4 issues), 2,284 used + 134 repeat punches, 800 meal person-days (52 with repeats); re-upload refused
- [ ] T017 Open PR
