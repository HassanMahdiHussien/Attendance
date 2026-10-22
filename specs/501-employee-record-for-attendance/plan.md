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
- `employee_versions`: `id`, `company_id`, `employee_id`, `effective_from` (date), `changes` (json: **only the fields this version sets**, among `branch_id`, `department`, `position`, `work_pattern`, `rest_days`, `schedule_start`, `schedule_end`, `manager_employee_id`, `manager_name`), `previous` (json: the values `asOf(effective_from)` returned for those fields just before this row, for the Changes list and audit), `reason` (nullable), `source` (`manual|import|chat|initial`), `changed_by` (nullable user), `employee_import_batch_id` (nullable), `created_at`. Composite FKs to company/employee/batch. Append-only triggers. Index `(company_id, employee_id, effective_from, id)`. Storing changed fields only is what keeps a back-dated change correct when later versions exist (Codex review, finding 1).
- `employee_import_batches`: `id`, `company_id`, `uploaded_by`, `mode` (`full|partial`), `effective_date`, `file_path`, `file_name`, `file_sha256`, `mapping` (json: position → field), `counts` (json), `summary` (json: counts, codes and field names only — never unmapped values or row contents), `created_at`. Append-only triggers. Index `(company_id, file_sha256)`. **Idempotency is by company + hash alone**: a file already committed once is reported as "already imported on <date> as <mode>" and cannot be committed again, whatever mode or date is chosen. Migration order: batches table before `employees.exit_review_batch_id` and its FK.
- `employee_import_mappings`: `id`, `company_id`, `header_signature` (sha256 of the normalised ordered header row), `headers` (json), `mapping` (json), `updated_by`, timestamps. Unique `(company_id, header_signature)`. Not append-only (it is a setting); changes audited.
- Data migration: one `initial` version per existing employee, effective from `hire_date` (or the employee's `created_at` date when `hire_date` is null).

### Services (`App\Services\Employees`)
- `EmployeeHistory::asOf(Employee, CarbonInterface $date): array` — **per field**: the value from the latest version (by `effective_from`, then `id`) with `effective_from <= date` whose `changes` contains that field; null before any. `record(Employee, array $values, date $effectiveFrom, string $source, ?User, ?string $reason, ?int $batchId): ?EmployeeVersion` — keeps only fields whose value differs from `asOf(effectiveFrom)`, writes nothing when none differ, inserts one version, then **recomputes the `employees` row from `asOf(today)`** (so back-dated, current and future-dated changes all leave the row equal to today's values), fires `EmployeeVersionChanged(employee, effectiveFrom)` and writes an audit row. Changing `schedule_end` alone writes only `schedule_end`. Every write path goes through this service: Filament create/edit, the importer, and (later) chat.
- `SpreadsheetReader` — reads `.csv` (existing `CsvReader` behaviour) and `.xlsx` (openspout, first sheet) into a header row + cell rows of strings: numeric cells as text without losing digits (no scientific notation, no `.0`), date-formatted cells and plain serials left as raw values for the field parser, Excel error cells (`#N/A`, `#REF!`…) as empty.
- `ColumnMapping` — field catalogue (code, name, name_en, department, position, hire_date, branch, email, gender, manager_code, manager_name, work_pattern, rest_days, schedule_start, schedule_end, fallback_notification_email); `suggest(headers)` from `config('employee_import.aliases')` (English + Arabic aliases, including the HC headers, with an occurrence rule so the first `Pers.No.` is the employee code and a `Pers.No.` followed by `Personnel number of superior` is the manager code); `saved(company, headers)`; `save(...)`. A mapping must include `employee_code`; a full list must also include `name` and `branch`.
- `EmployeeImport` (extended, keeps its current public method signatures for the existing CSV path until the screen moves over): `preview(company, file, mode, effectiveDate, mapping)` → `ImportPreview` with: rows read; new; changed (per field before → after); **unchanged counted separately** (today's `updated()` counts every existing row); warnings (missing position, unmatched manager code, unknown work pattern value); errors (missing required fields, unknown branch, duplicate code in file, bad date); for `full` mode the active employees missing from the file; and `alreadyImported` when the same file hash, mode and effective date were committed before. `commit(...)` takes a confirmation token binding hash, mode, effective date, mapping and a digest of the preview result; it re-runs the preview under a lock and, if anything differs, returns "the file or data changed — review again" instead of committing; stores the file on the private disk, writes the batch, creates/updates employees and versions through `EmployeeHistory::record` (source `import`, batch id), sets `exit_status = pending_review` for missing employees (full mode), clears `pending_review` for employees who reappear (audited), all in one transaction.
- Manager codes are resolved **during preview** against existing employees and all valid rows of the same upload, so the preview shows the final manager links; commit creates new employees first, then writes versions with the resolved `manager_employee_id`, in one transaction.
- Blank cells never erase stored values (as today). Full mode: every row needs `employee_code`, `name` and `branch`; a blank code is a row error (no generated codes in full mode, otherwise the missing-employee check would be wrong). Partial mode: `employee_code` required; only mapped, non-blank fields change. The legacy CSV path keeps generating codes for blank codes, but `department` and `position` become optional there too; affected existing tests are updated, not deleted.
- `ExitReview` — `pending(company)`, `start(employee, actor)` (manual exit from the employee screen), `confirm(employee, lastWorkingDay, actor)` (sets `confirmed`, `last_working_day`; `is_active = false` only once **the day after** the last working day has been reached), `dismiss(employee, actor, reason)`; all audited. The existing Filament **Deactivate** action is replaced by "Start exit review" → confirm with a date; no path sets `is_active = false` directly any more. Linked user account warning reused.
- Scheduled command `employees:apply-effective-changes` (daily, idempotent, per company in tenant context): recomputes the `employees` row from `asOf(today)` for employees with a version dated today or earlier that is not yet reflected, and sets `is_active = false` for confirmed exits with `last_working_day < today`.
- `SpreadsheetReader` cell conversion is **field-aware** (openspout v4 returns typed cells): code fields — strings kept exactly, integers converted with `(string)` (no scientific notation), non-integral floats rejected as an error row ("ambiguous ID"); date fields — `DateTime` → `Y-m-d`, numeric Excel serial → date (1900 system), `YYYY-MM-DD` / `DD/MM/YYYY` strings parsed, anything else an error; `ErrorCell` and `#N/A`-style strings → empty; other fields → trimmed string. Leading zeros already lost inside Excel cannot be recovered and are not guessed.
- **Original file download**: route `employee-imports/{batch}/file`, authorised for HR of the batch's company and platform admins (policy + tenant scope), audited; covered by an access test and a `RouteSweepTest` mapping.

### Screens
- **Import page** (existing Filament `ImportEmployees`, extended): file (`.csv`/`.xlsx`), mode (full active list — default / partial update), effective date (default today) → **mapping step** (table: column letter, header, sample of the first non-empty value **only for mapped columns**, field select; pre-filled from the saved or suggested mapping) → **preview** (counts, new, changed with before → after, missing employees for full mode, warnings, errors, "already imported" notice) → commit. Personal columns that are not mapped never appear in samples.
- **Exit review**: a Filament admin page listing `pending_review` employees with their batch and date, proposed last working day (empty until spec 502), confirm with a date, or dismiss with a reason.
- **Employee form** (Filament): the new fields plus "effective from" (default today) and an optional reason; saving goes through `EmployeeHistory::record` (source `manual`). Rest days as weekday checkboxes; work pattern select; manager as an employee picker within the company.
- **Employee page**: a short "Changes" list (date, field, before → after, by whom, source) from versions, newest first.
- All strings in `en` and `ar`; codes, emails and times LTR inside Arabic.

## Implementation steps (each = one Codex run → my review → gates in WSL → commit)

Two runs, each green on its own (Codex review: history is only immutable once every write path uses it).

1. **Data layer + every existing write path**: migrations (batches before the employees FK), models, factories, triggers, initial versions, `EmployeeHistory`, event, scheduled command, `ExitReview`; the Filament employee create/edit form (new fields, effective date, reason), the Deactivate action → exit review, the existing CSV importer's commit writing through `EmployeeHistory`; exit review page; employee page "Changes". Tests: `asOf` incl. back-dated and future-dated, schedule-end-only, append-only triggers (`TriggerSmokeTest`), `CompositeForeignKeyTest` map, `IsolationTest`, deactivate-only-via-review, day-after deactivation, translations, UI inventory.
2. **Import engine + import screen**: `SpreadsheetReader` (field-aware), `ColumnMapping` + `config/employee_import.php` aliases, extended preview/commit (modes, mapping step, confirmation token, hash idempotency, missing employees, manager resolution in preview, unchanged count, warnings), batches with stored file, download route + policy + `RouteSweepTest` mapping, import page with mapping step and richer preview, help article. Fixture: an anonymised HC-shaped `.xlsx` generated in the test with the openspout v4 writer (text and numeric IDs, styled and plain dates, duplicate and blank headers, `#N/A` error cells) — no committed binary. Browser test for the import flow.
3. **Docs** (me): `FOUNDATION-CHANGES.md` rows (most **Port**: Displanary needs dated employee history too), `WEIRDNESS.md` lines, tasks ticked.

Then: run the real `HC.xlsx` through the preview on the dev DB (not committed to git) and report counts, warnings and errors to the product owner before committing it.

## Risks

- openspout returns typed cells (int/float/DateTime): code-to-text conversion must not lose leading zeros or produce `2.3000443E7`.
- Back-dated changes after a later version: `record` must not overwrite later versions' values for fields the back-dated change did not touch — only the new version's snapshot changes, and the `employees` row is recomputed from `asOf(today)`.
- Large preview payload in Livewire state: keep the preview summary server-side (cache keyed by file hash) as the existing import does.
- The real file holds personal data: never log row contents; samples only for mapped columns.
