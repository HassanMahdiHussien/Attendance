# Implementation Plan: Attendance transaction import and punch cleanup

**Branch**: `502-attendance-import` | **Date**: 2026-10-22 | **Spec**: [spec.md](spec.md)

## Summary

Import device exports into immutable raw transactions (deduplicated by a natural key, every source row traceable), register devices with HR approval, classify people as employees / non-employees / unknown, clean repeated punches into a separate derived table, count restaurant meals, and raise data issues. All attendance code lives in its own namespaces (CLAUDE.md §2) so it merges back into Displanary unchanged.

## Technical Context

- PHP 8.5 / Laravel 13 / Livewire 4 / Filament 5 / MySQL 8.4 / Pest; native WSL; test DB `testing_attendance`; queue = database driver.
- Reuses spec 501's `SpreadsheetReader` (field-aware cells) and the positional-mapping idea; no new packages.
- Namespaces: `App\Models\Attendance`, `App\Services\Attendance`, `App\Jobs\Attendance`, `App\Filament\Admin\Resources\Attendance` / `App\Filament\Admin\Pages\Attendance`, migrations `*_attendance_*`, tests `tests/Feature/Attendance`.
- Time zone: Asia/Baghdad (`config('app.timezone')`); `punched_at` stored as a local `datetime` (no UTC conversion), matching how the devices record time.
- Volume: sample 4,828 rows; design target 200,000 rows per file (chunked reads, batched inserts of 1,000, queued jobs).

## Constitution Check (2.0.0)

| Principle | How |
|---|---|
| I Tenant isolation | Every table has `company_id` + composite FKs + `BelongsToCompany`; isolation tests per table and route; `RouteSweepTest` mapping for new routes. |
| II Rules calculate | Cleanup and meal counting are deterministic services with a versioned policy (`cleanup_policy_version`); no AI. |
| III Source immutable | Raw transactions, transaction sources and batches append-only (triggers); cleanup results are derived rows linked to a processing run; re-runs supersede, never edit raw data. |
| IV Tests | All CLAUDE.md §7 items 1–3 for this scope (isolation, idempotency, punch cleanup, restaurant). |
| V Simple Laravel | Services + jobs; Filament/Livewire thin. |
| VI Decisions | New choices recorded in `WEIRDNESS.md` (natural key, 16 h gap, title-row detection). |

## Design

### Schema (new tables; all `company_id` + composite FKs)
- `attendance_devices`: `serial_number` (unique per company), `name`, `status` (`pending|approved|retired`), timestamps; `attendance_device_settings` (append-only, effective-dated): `device_id`, `effective_from`, `role` (`check_in|check_out|restaurant`), `branch_id`, `approved_by`, `reason`. Current role/site = latest setting effective on the punch date.
- `attendance_import_batches` (append-only): uploader, `file_path`, `file_name`, `file_sha256` (unique per company), `export_at` (nullable, from file name), `coverage_from`, `coverage_to`, `coverage_branch_ids` (json), `mapping` (json), `counts` (json), `status` (`queued|processing|completed|failed` — kept in a separate `attendance_import_batch_states` append-only log so the batch row itself stays immutable), `created_at`.
- `attendance_import_mappings`: saved positional mappings per header signature (same shape as `employee_import_mappings`).
- `attendance_transactions` (append-only): `person_code` (string), `person_name`, `department_label`, `punch_date` (date), `punch_time` (string as given), `punched_at` (datetime), `punch_state` (raw string), `serial_number`, `device_name`, `data_source`, `first_batch_id`; unique natural key `(company_id, person_code, punched_at, punch_state, serial_number)`; indexes `(company_id, person_code, punched_at)`, `(company_id, serial_number, punched_at)`.
- `attendance_transaction_sources` (append-only): `transaction_id`, `batch_id`, `row_number`, `duplicate_in_file` (bool); unique `(batch_id, row_number)`.
- `attendance_people`: per company and `person_code`: `kind` (`employee|non_employee|unknown`), `employee_id` (nullable), `department_label`, `classified_at`, `classification_reason`. Recomputed (not append-only; audited) when transactions arrive or the employee list changes.
- `attendance_processing_runs` (append-only): `kind` (`cleanup|meals`), `trigger` (`import|device|employees|manual`), `batch_id`/`device_id` nullable, scope (`person_codes` json or null = all, `from`, `to`), `policy_version`, `status`, counts, `started_at`, `finished_at`.
- `attendance_punches` (derived): `transaction_id`, `run_id`, `person_code`, `employee_id` (nullable), `punched_at`, `role` (`check_in|check_out`), `status` (`used|repeat|unused_device|non_employee`), `group_key`, `reason`, `superseded_by_run_id` (nullable). Only the latest non-superseded row per transaction is current.
- `attendance_meals` (derived): `person_code`, `employee_id` nullable, `department_label`, `meal_date`, `punch_count`, `run_id`, `superseded_by_run_id`; one current row per person per day.
- `data_issues` (foundation-neutral name, attendance-owned): `type`, `subject_type`/`subject_key`, `batch_id` nullable, `date_from`/`date_to`, `status` (`open|resolved|dismissed`), `note`, `resolved_by`, `resolved_at`, `details` (json, codes only). Unique open issue per `(company_id, type, subject_key)`.

### Services (`App\Services\Attendance`)
- `TransactionFile` — reads via `SpreadsheetReader`, detects the header row (first row whose cells match ≥ 4 known headers; a single-cell title row above it is skipped), parses `Date`+`Time` (also Excel date/time serials), returns typed rows; `exportTimeFromName()` parses `Transaction_YYYYMMDDhhmmss`.
- `TransactionImport::preview(company, file, mapping, coverage)` / `commit(..., token)` — same confirmation-token and hash-idempotency pattern as `MappedEmployeeImport`. Commit stores the file + batch synchronously, then dispatches `ImportTransactionsJob` (chunked insert with `insertOrIgnore` on the natural key, then `attendance_transaction_sources` for every row), then `ProcessAttendanceJob` for the affected scope. Small files (≤ 5,000 rows) may run inline in tests via the sync queue.
- `DeviceRegistry` — `register(serial, name, observedState)` → pending device with suggested role/site; `approve(device, role, branch, effectiveFrom, actor)`; `change(...)`; each approval/change dispatches re-processing for that device's punches from `effectiveFrom`.
- `PersonClassifier` — classifies person codes against `employees.employee_code` and `config('attendance.non_employee_department_patterns')` (default `^3rd Party`, `^Guest$`, `^General$`); raises/resolves `unknown_person` issues; re-run by a listener on spec 501's employee import commit.
- `PunchCleanup::run(company, scope, trigger)` — loads current transactions for the scope (approved check-in/check-out devices only, ±1 day margin), groups per person in time order with the FR-006 rules and the configurable gap (`config('attendance.cleanup.max_group_gap_hours', 16)`), writes new `attendance_punches` rows for the run and supersedes the previous current rows in the same transaction; deterministic order `(punched_at, id)`.
- `MealCounter::run(...)` — same pattern for restaurant devices.
- `DataIssues` — open/resolve/dismiss with audit.

### Jobs
`ImportTransactionsJob`, `ProcessAttendanceJob` (cleanup then meals), both `ShouldBeUnique` per batch/scope, idempotent (natural key + superseding), retry-safe; batch state log records progress and failure; failed jobs show the cause and a Retry action (PRD §23).

### Screens (Filament admin, HR + platform admin)
- **Attendance upload** page: upload → mapping (only on a new header signature) → coverage confirmation (suggested range + branches, editable) → preview (counts, devices, duplicates, unknown IDs, errors) → commit → progress.
- **Batches**: list with counts, coverage, state, original-file download (policy + audit).
- **Devices**: list, approve (role, site, effective date), change, retire.
- **Data issues**: list, filter by type/status, resolve/dismiss with note.
- **Punch viewer**: pick employee (or person code) + date range → raw transactions and cleaned punches side by side with status and reason; restaurant meals per day.
- Nav group "Attendance". All strings en + ar.

## Implementation steps (each one Codex run → my review → full gates in WSL → commit)

1. **Data + import**: migrations (all tables + triggers), models, factories, `TransactionFile`, `TransactionImport` (preview/commit), `DeviceRegistry` (register only), `PersonClassifier`, `DataIssues`, `ImportTransactionsJob`; tests for parsing, title row, export-time coverage, idempotent and overlapping uploads, sources traceability, classification, isolation, append-only.
2. **Cleanup + meals + re-processing**: `PunchCleanup`, `MealCounter`, `ProcessAttendanceJob`, processing runs, device approval/change re-processing, employee-list listener; PRD §25 tests 1, 3, 4, 5 and determinism/idempotency.
3. **Screens**: upload flow, batches + download, devices, data issues, punch viewer, nav, translations, help article, UI inventory, route sweep; Livewire feature tests for the upload flow (the Pest browser server cannot drive Livewire uploads) + a browser smoke test of the pages.
4. **Docs + verification** (me): `WEIRDNESS.md`, `FOUNDATION-CHANGES.md` (only if a shared file changed), run the real sample through preview and commit on the smoke DB and report counts (devices, duplicates, repeats, meals, unknown IDs) before opening the PR.

## Risks

- Natural key vs. genuine identical punches: two real punches by one person on one device in the same minute with the same state are indistinguishable from an export duplicate; they count once (PRD: "exact duplicate rows are counted once"), which is the intended behaviour.
- Large files: avoid loading 200k rows into memory in preview — preview reads in chunks and keeps counts and samples only.
- Device time skew and overnight shifts are not corrected here (503).
