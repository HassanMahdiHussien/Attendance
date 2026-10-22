# Implementation Plan: Shared employee record for attendance

**Branch**: `501-employee-record-for-attendance` | **Date**: 2026-10-22 | **Spec**: [spec.md](spec.md)

## Summary

Extend the shared `employees` record with the attendance fields, keep every change as an effective-dated version, and turn today's CSV import into an import that also reads `.xlsx`, maps columns by position with a saved per-company mapping, supports a full-active-list mode that finds missing employees, and keeps each committed upload as an append-only batch. Missing employees go to an HR exit review; nobody is deactivated automatically. No attendance calculation is built.

## Technical Context

- PHP 8.5 / Laravel 13 / Livewire 4 / Filament 5 / MySQL 8.4 / Pest; native WSL dev (`~/attendance`), test DB `testing_attendance`.
- Excel reading: `openspout/openspout` (already installed). No new packages.
- Gates: `php artisan test --compact` (Unit, Feature, Browser) and `./vendor/bin/pint --test`.
- Scale: ~1,015 employees per upload today; must stay comfortable at a few thousand rows (preview and commit in one request is acceptable at this size; the existing import already does this).
- Real sample: `D:\Attandance\HC.xlsx` (real names — never committed). Tests use a small anonymised fixture with the **same header row** (including the duplicate `Pers.No.` and the blank header).

## Constitution Check (2.0.0)

| Principle | Compliance |
|---|---|
| I Tenant isolation | New tables carry `company_id` with composite FKs; isolation tests for versions, batches, mappings, exit review; models use `BelongsToCompany`. |
| II AI proposes… | No AI in this spec. |
| III Source records immutable | Original file kept on the private disk; `employee_versions` and `employee_import_batches` append-only via triggers; corrections are new versions with a reason; audit rows for every change. |
| IV Tests | `asOf`, idempotent re-upload, missing-employee review, leading zeros, Excel serial dates, schedule-end-only change, preview = commit, isolation. |
| V Simple Laravel | Logic in `App\Services\Employees`; Filament/Livewire stay thin. |
| VI Decisions | New non-obvious choices get a `WEIRDNESS.md` line (mapping by position; `name` falls back to English). |

## Design

### Schema (one migration per concern, foundation names — these merge back)
- `employees` + columns: `work_pattern` (string, nullable; `office|shift|security`), `rest_days` (json, nullable), `schedule_start` / `schedule_end` (time, nullable), `manager_employee_id` (nullable, composite FK `(company_id, manager_employee_id)` → `employees(company_id, id)`), `manager_name` (string, nullable), `fallback_notification_email` (string, nullable), `last_working_day` (date, nullable), `exit_status` (string, default `none`), `exit_review_batch_id` (nullable FK to the batch that raised it). `position` and `department` become nullable.
- `employee_versions`: `id`, `company_id`, `employee_id`, `effective_from` (date), the full snapshot (`branch_id`, `department`, `position`, `work_pattern`, `rest_days`, `schedule_start`, `schedule_end`, `manager_employee_id`, `manager_name`), `reason` (nullable), `source` (`manual|import|chat|initial`), `changed_by` (nullable user), `employee_import_batch_id` (nullable), `created_at`. Composite FKs to company. Append-only triggers. Index `(company_id, employee_id, effective_from, id)`.
- `employee_import_batches`: `id`, `company_id`, `uploaded_by`, `mode` (`full|partial`), `effective_date`, `file_path`, `file_name`, `file_sha256`, `mapping` (json: position → field), `counts` (json), `summary` (json: the confirmed preview), `created_at`. Append-only triggers. Unique `(company_id, file_sha256, mode, effective_date)` is **not** used for idempotency (a same-file re-upload must be reported, not rejected by an exception): the service checks first.
- `employee_import_mappings`: `id`, `company_id`, `header_signature` (sha256 of the normalised ordered header row), `headers` (json), `mapping` (json), `updated_by`, timestamps. Unique `(company_id, header_signature)`. Not append-only (it is a setting); changes audited.
- Data migration: one `initial` version per existing employee, effective from `hire_date` (or the employee's `created_at` date when `hire_date` is null).

### Services (`App\Services\Employees`)
- `EmployeeHistory::asOf(Employee, CarbonInterface $date): array` — latest version with `effective_from <= date` (ties broken by `id`); returns null-snapshot values before the first version. `record(Employee, array $values, date $effectiveFrom, string $source, ?User, ?string $reason, ?int $batchId): ?EmployeeVersion` — builds the new snapshot from the version in effect on that date plus the changed fields only (so changing `schedule_end` never touches other fields), writes nothing when nothing differs, updates the `employees` row only when the version is in effect today (or is the latest one effective on or before today), fires `EmployeeVersionChanged(employee, earliestAffectedDate)`, writes an audit row. Future-dated versions take effect on the `employees` row via the daily command below.
- `SpreadsheetReader` — reads `.csv` (existing `CsvReader` behaviour) and `.xlsx` (openspout, first sheet) into a header row + cell rows of strings: numeric cells as text without losing digits (no scientific notation, no `.0`), date-formatted cells and plain serials left as raw values for the field parser, Excel error cells (`#N/A`, `#REF!`…) as empty.
- `ColumnMapping` — field catalogue (code, name, name_en, department, position, hire_date, branch, email, gender, manager_code, manager_name, work_pattern, rest_days, schedule_start, schedule_end, fallback_notification_email); `suggest(headers)` from `config('employee_import.aliases')` (English + Arabic aliases, including the HC headers, with an occurrence rule so the first `Pers.No.` is the employee code and a `Pers.No.` followed by `Personnel number of superior` is the manager code); `saved(company, headers)`; `save(...)`. A mapping must include `employee_code`; a full list must also include `name` and `branch`.
- `EmployeeImport` (extended, keeps its current public behaviour for the existing CSV path and tests): `preview(company, file, mode, effectiveDate, mapping)` → `ImportPreview` with: rows read; new; changed (per field before → after); unchanged; warnings (missing position, unmatched manager code, unknown work pattern value); errors (missing required fields, unknown branch, duplicate code in file, bad date); for `full` mode the active employees missing from the file; and `alreadyImported` when the same file hash, mode and effective date were committed before. `commit(...)` re-runs the preview under a lock, refuses if the hash differs from the one previewed, stores the file on the private disk, writes the batch, creates/updates employees and versions through `EmployeeHistory::record` (source `import`, batch id), sets `exit_status = pending_review` for missing employees (full mode), clears `pending_review` for employees who reappear (audited), all in one transaction.
- Manager linking happens after all rows are created in the same commit, so a manager who is also new in the file is found.
- `ExitReview` — `pending(company)`, `confirm(employee, lastWorkingDay, actor)` (sets `confirmed`, `last_working_day`, and `is_active = false` if the date is past or today), `dismiss(employee, actor, reason)`; both audited. Linked user account warning reused from the existing import.
- Scheduled command `employees:apply-effective-changes` (daily, idempotent): applies future-dated versions that reached their date to the `employees` row, and sets `is_active = false` for confirmed exits whose last working day has passed.

### Screens
- **Import page** (existing Filament `ImportEmployees`, extended): file (`.csv`/`.xlsx`), mode (full active list — default / partial update), effective date (default today) → **mapping step** (table: column letter, header, sample of the first non-empty value **only for mapped columns**, field select; pre-filled from the saved or suggested mapping) → **preview** (counts, new, changed with before → after, missing employees for full mode, warnings, errors, "already imported" notice) → commit. Personal columns that are not mapped never appear in samples.
- **Exit review**: a Filament admin page listing `pending_review` employees with their batch and date, proposed last working day (empty until spec 502), confirm with a date, or dismiss with a reason.
- **Employee form** (Filament): the new fields plus "effective from" (default today) and an optional reason; saving goes through `EmployeeHistory::record` (source `manual`). Rest days as weekday checkboxes; work pattern select; manager as an employee picker within the company.
- **Employee page**: a short "Changes" list (date, field, before → after, by whom, source) from versions, newest first.
- All strings in `en` and `ar`; codes, emails and times LTR inside Arabic.

## Implementation steps (each = one Codex run → my review → gates in WSL → commit)

1. **Data layer**: migrations, models, factories, triggers, initial versions, `EmployeeHistory`, event, scheduled command, `ExitReview`; isolation/append-only/`asOf`/schedule-end tests. Existing screens unchanged.
2. **Import engine**: `SpreadsheetReader`, `ColumnMapping` + config aliases, extended `EmployeeImport` preview/commit with modes, batches, missing employees, manager linking, idempotency; anonymised HC-shaped `.xlsx` fixture (generated in the test with openspout, not a committed binary) and CSV tests; existing import tests still pass.
3. **Screens**: import page with mapping step and richer preview, exit review page, employee form fields + effective date, employee page "Changes", nav entry, translations, help article update, UI inventory; browser test for the import flow.
4. **Docs**: `FOUNDATION-CHANGES.md` rows (most are **Port**: Displanary needs dated employee history too), `WEIRDNESS.md` lines, tasks ticked.

Then: run the real `HC.xlsx` through the preview on the dev DB (not committed to git) and report counts, warnings and errors to the product owner before committing it.

## Risks

- openspout returns typed cells (int/float/DateTime): code-to-text conversion must not lose leading zeros or produce `2.3000443E7`.
- Back-dated changes after a later version: `record` must not overwrite later versions' values for fields the back-dated change did not touch — only the new version's snapshot changes, and the `employees` row is recomputed from `asOf(today)`.
- Large preview payload in Livewire state: keep the preview summary server-side (cache keyed by file hash) as the existing import does.
- The real file holds personal data: never log row contents; samples only for mapped columns.
