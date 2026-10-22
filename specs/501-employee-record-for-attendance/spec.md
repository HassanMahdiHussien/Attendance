# Feature Specification: Shared employee record for attendance

**Branch**: `501-employee-record-for-attendance` (after `500-foundation-cleanup` is merged) | **Created**: 2026-10-21 | **Status**: Clarified 2026-10-22

**Input**: Product owner (2026-10-21): build the attendance and leave reconciliation module (`docs/attendance/PRD-EN.md`) in this repo (copied from Displanary), so that after merge-back one employee search shows both attendance and discipline. Decisions and sequence: `docs/attendance/ROADMAP.md`. Architecture: ADR 0013 (separate repo, merge back later). This spec covers only the shared employee record that every later attendance spec depends on (PRD §5). No attendance calculation is built here.

## Background

`employees` today holds `employee_code` (text), `name`, `department`, `position`, `hire_date`, `branch_id`, `email`, `gender`, `is_general_manager`, `is_active`. Updates overwrite values in place, and the CSV import (`App\Services\Employees\EmployeeImport`) creates and updates rows but never reports employees missing from the file. Attendance needs dated history (a work-pattern change on 1 November must not change October's results), a manager, a fallback notification email, and an exit process that never deactivates someone silently.

## Requirements

- **FR-001 New employee fields** (shared by both modules, all nullable unless stated):
  - `work_pattern`: `office` | `shift` | `security`. Required for attendance assessment; an employee with none is listed as "pattern missing" and is not assessed.
  - `rest_days`: a set of weekdays (stored as a JSON list of ISO weekday numbers). Empty means "use the work pattern's default", which spec 503 defines. Defaults (ROADMAP §2, Q1): office Friday + Saturday, shift Friday, security has no default — HR sets each employee's day, and a security employee without one is listed as "rest day missing" and not assessed.
  - `schedule_start` / `schedule_end`: per-employee override of the pattern's working hours. Empty means the pattern default. Changing one never changes the other, the pattern or the rest days (PRD §21.2, test 28).
  - `manager_employee_id`: the direct manager, linked to another employee of the same company. `manager_name` holds the reported name when no employee matches.
  - `fallback_notification_email`: the manager email HR designates for employees without an email (PRD §17).
  - `last_working_day` and `exit_status`: `none` | `pending_review` | `confirmed`.
  - `hire_date` is the joining date for attendance; no new column.
- **FR-002 Effective-dated history**:
  - New table `employee_versions`: one row per change, holding `company_id`, `employee_id`, `effective_from`, the full set of attendance-relevant values (`branch_id`, `department`, `work_pattern`, `rest_days`, `schedule_start`, `schedule_end`, `manager_employee_id`, `manager_name`), `changed_by`, `source` (`manual` | `import` | `chat`), and the `employee_import_batch_id` when it came from an upload.
  - A service `EmployeeHistory::asOf(Employee, date)` returns the values in effect on that date. The `employees` row keeps the values in effect *today*, so existing code — and Displanary after merge-back — keeps working unchanged.
  - A change dated in the past or future creates a version row; it never edits an existing version. A correction to a wrongly entered version creates a new version with a reason.
  - Each change raises an `EmployeeVersionChanged` event carrying the employee and the earliest affected date. No listener is added in this spec; spec 503 uses it to recalculate affected days.
  - Existing employees get one initial version, effective from their `hire_date`, in the migration.
- **FR-003 Full-population upload** (extends the existing import; CSV stays supported, `.xlsx` added via the already-installed `openspout`):
  - HR chooses the upload type: **full active list** (new behaviour, the attendance default) or **partial update** (today's behaviour).
  - HR sets the effective date of the upload (default: today).
  - **Column mapping.** On upload the system suggests a mapping (column position → field) from header aliases (English and Arabic, editable in config) and HR confirms or corrects it before the preview. A confirmed mapping is saved per company under the file's header signature (the ordered header row) and applied automatically next time; HR can still change it. Columns not mapped are ignored and never stored in the database. Duplicate and blank headers are handled because mapping is by position.
  - Employee IDs are read as text: `00123` stays `00123`; Excel numeric cells are converted without losing digits. Excel date serials, `YYYY-MM-DD` and `DD/MM/YYYY` dates are accepted. `#N/A` and similar Excel error cells count as empty.
  - **The preview shows, before anything changes**: rows read, new employees, changed employees with each changed field (before → after), unchanged employees, errors, and — for a full list — **active employees missing from the file**.
  - Committing is all-or-nothing, as today. Re-uploading the same file (same SHA-256) creates no versions and no changes, and says so.
  - Each upload is kept as an `employee_import_batches` row: original file on the private disk, hash, type, effective date, uploader, counts, and the preview summary.
- **FR-004 Missing employees are never deactivated automatically**:
  - An active employee missing from a full list gets `exit_status = pending_review` and appears in an HR "Exit review" list with the batch that raised it.
  - The proposed last working day is the employee's last recorded attendance date. Attendance data arrives in spec 502, so until then the field starts empty and HR enters a date.
  - HR confirms the last working day (or dismisses the review if the employee was left out by mistake). Confirming sets `exit_status = confirmed` and `last_working_day`; `is_active` becomes false after that date (immediately if the date has passed, otherwise by a daily idempotent scheduled command). History is untouched.
  - A linked user account is shown as a warning before confirmation (existing behaviour).
  - An employee who reappears in a later full list while `pending_review` is cleared automatically, with an audit entry.
- **FR-005 Manual edits from the employee screens**: the Filament employee form gains the new fields and an "effective from" date (default today). Saving creates a version through the same service as the import. The employee page shows a short "Changes" history: date, field, before → after, by whom, source.
- **FR-006 Permissions**: the same users who can import and edit employees today (`importEmployees` and the employee resource policy) can use the new fields, the full-list upload and exit review. No new role.
- **FR-007 Audit**: every version, upload, exit-review decision and dismissal writes an `audit_logs` row with before/after values. `employee_versions` and `employee_import_batches` are append-only and protected by triggers, like the existing audit tables.
- **FR-007b Merge-back log**: `employees`, the employee import and the employee screens are shared foundation code. Every edit to them is added to `docs/upstream/FOUNDATION-CHANGES.md`. `employee_versions` and `EmployeeHistory` are foundation (Displanary will need them too) and stay under `App\Models` / `App\Services\Employees`.
- **FR-008 Language**: every new label, message, preview heading and validation message exists in `en` and `ar`. Screens are designed in English first (ADR 0014) and checked in Arabic RTL. Employee IDs, emails and times display LTR inside Arabic text.
- **FR-009 Tests (Pest)**:
  - Tenant isolation for `employee_versions`, `employee_import_batches` and exit review (Company A cannot read or change Company B's rows).
  - `asOf` returns the right values before, on and after each effective date, including a back-dated change.
  - Re-uploading the same file changes nothing and creates no versions.
  - A full list missing an active employee sets `pending_review` and never sets `is_active = false` by itself.
  - Confirming exit keeps all history and cases; reappearing clears the review.
  - Leading zeros and long numeric IDs survive CSV and `.xlsx`.
  - Changing `schedule_end` alone leaves `schedule_start`, `rest_days` and `work_pattern` unchanged.
  - The preview's counts and diffs equal what commit actually writes.
  - Existing employee import tests still pass; translations (en + ar) complete; Pint and the full suite pass.

## Out of scope

Attendance transactions, devices, work-pattern policies and their defaults (502–503); leave (504); the combined employee page and dashboard (505); employee accounts.

## Clarifications (product owner, 2026-10-22)

- **Real employee file (Q6 resolved).** The active-employee export is `HC.xlsx` (SAP-style, one sheet `Data`, ~1,015 rows, header in row 1). Its columns, by position: A `Pers.No.` = employee code (8 digits, text); B `Personnel Number` = English name; C (blank header) = Arabic name, `#N/A` when missing; D `Position`; E `Entry` = hire date as an Excel date serial; N `Gender Key` (`Male`/`Female`); X `Personnel Area` = branch (Hilla/Erbil/Karbala/Baghdad); P `Organizational Unit` = department; T `Pers.No.` (a **second** column with the same header) = manager's employee code; U `Personnel number of superior (` = manager's name. Other columns (age, birth date, nationality, education, payroll, cost centre, collar, groups, company code, directorate, sub-area) are **never imported**. The file contains **no emails and no work pattern**.
  - Because one header repeats and one is blank, columns are mapped by **position with a saved mapping**, not by header name alone (see FR-003).
  - Arabic name → `name`; when it is missing or `#N/A`, `name` = the English name. English name → `name_en`.
- **Two sources, one importer.** The active list is uploaded as a **full active list**. The separate email / work-pattern file is uploaded as a **partial update**: it changes only the fields it maps and never flags anyone as missing.
- **Manager.** Each employee row carries the manager's employee code; the importer links `manager_employee_id` by that code within the company. If the code is missing or matches no employee, `manager_name` keeps the reported name and the preview shows a warning. A manager's email is that manager's own `employees.email` (uploaded through the email file); `fallback_notification_email` is a separate column the email file may carry, for employees without their own email.
- **Position** is optional: when the column is not mapped, existing employees keep their position; new employees get an empty position and the preview shows a warning.
- **Roles.** After spec 500 only `hr_staff` (own company) and `platform_admin` exist; both can use everything in this spec. There are no cases or documents any more, so FR-004's "cases and documents untouched" means "history untouched".
- **Privacy.** The original uploaded file is kept on the private disk (PRD §4) and may contain personal columns the system never imports; it is downloadable only by HR of that company and platform admins, and never shown in the preview.
