# Feature Specification: Employee CSV Import

**Feature Branch**: `012-employee-csv-import`

**Created**: 2026-10-08

**Status**: Draft

**Input**: User description: "CSV bulk employee import (CLAUDE.md §9 step 7, §5): columns name, employee_id, department,
position, hire_date, branch, email (optional); use the company's own employee number when given, otherwise
auto-generate EMP-0001…; never leave it blank."

## Clarifications

### Session 2026-10-08

- Q: Error handling? → A: Preview, then all-or-nothing (product owner): every row is checked and shown; nothing is
  saved until the whole file is valid.
- Q: Existing employee number? → A: Update that employee (product owner): the row's fields replace the stored ones and
  the preview marks it "سيُحدَّث".
- Q: Branch move for an employee with an open case / inactive employee number? → A: Both allowed and shown in the
  preview (open case stays in its branch; inactive stays inactive) — plan R6 B3.
- Q: Extra columns? → A: `gender` (optional: male/female, ذكر/أنثى) because documents cannot be issued for an employee
  with unspecified gender (feature 004); the general-manager flag is never set by import.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - The company admin imports the employee list (Priority: P1)

In the admin panel, Employees → "استيراد من CSV": download the template (Arabic + English headers), upload the file,
see a preview of every row — new / will update / error with the reason — and the totals. When there are no errors,
"تأكيد الاستيراد" saves everything in one step and shows how many were added and updated.

**Acceptance Scenarios**:
1. **Given** a 200-row file with all fields valid, **Then** the preview shows 200 rows without errors and the import
   adds them; rows without an employee number get EMP-0001… in file order, continuing the company's sequence.
2. **Given** a row with an unknown branch and another with an invalid date, **Then** both are listed with their row
   number and reason, the confirm button is disabled, and nothing is saved.
3. **Given** a row whose employee number already exists, **Then** it is shown as "سيُحدَّث" with the changed fields and
   the import updates that employee.
4. **Given** the same employee number twice in the file, **Then** both rows are errors ("رقم مكرر في الملف").

### User Story 2 - Arabic files from Excel just work (Priority: P1)

Files saved from Excel in Iraq are often Windows-1256 encoded and may use semicolons; headers may be Arabic or
English. The import detects UTF-8 (with or without BOM) or Windows-1256, comma or semicolon, and either header set.

**Acceptance Scenarios**:
1. **Given** a Windows-1256 file with Arabic headers and semicolons, **Then** the preview shows correct Arabic names.
2. **Given** dates as 2024-01-15 or 15/01/2024, **Then** both are read as 15 January 2024; ambiguous or invalid dates
   are errors.

### Edge Cases

- Empty file, missing required headers, or more than 5,000 rows / 2 MB: refused before the preview with a clear
  message.
- A cell starting with "=", "+", "-" or "@" is stored as plain text (no spreadsheet formula meaning).
- Branch matched by name (trimmed, case/space-insensitive) within the company only.
- The company's employee sequence changes between preview and confirm (another import): the confirm re-validates and
  re-numbers; if anything became invalid, the preview is shown again instead of importing.
- Gender column empty for an existing employee: the stored gender is kept (empty never erases data); the same for an
  empty email.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: Only users allowed to create and update employees in the company (company admin; Platform Admin within
  the company) can import.
- **FR-002**: The template download MUST contain the headers (Arabic and English accepted): الاسم/name, رقم الموظف/
  employee_id, القسم/department, المنصب/position, تاريخ التعيين/hire_date, الفرع/branch, البريد/email (optional),
  الجنس/gender (optional).
- **FR-003**: Validation per row: name, department, position, hire date (not in the future), branch (existing branch
  of the company) required; email format if present; gender values; employee number unique within the file.
- **FR-004**: The preview MUST list every row with status (new / update / error), the reason for errors, and the fields
  that change for updates; totals at the top.
- **FR-005**: Confirming MUST re-validate and save all rows in one transaction, generating missing employee numbers
  from the company's sequence (EMP-0001 format, skipping numbers already used).
- **FR-006**: The import MUST be written to the audit log (who, when, file name, file hash, rows added, rows updated);
  each updated employee records the changed fields in the audit entry.
- **FR-007**: Encoding (UTF-8, UTF-8 BOM, Windows-1256) and delimiter (comma, semicolon) are detected automatically.

### Key Entities

- **Employee** (existing): unchanged schema.
- **Import audit entry**: summary plus per-employee changes in the audit log metadata.

## Success Criteria *(mandatory)*

- **SC-001**: A 1,000-row file previews in under 5 seconds and imports in under 10 seconds.
- **SC-002**: An invalid file never changes any employee (automated test).
- **SC-003**: Every imported employee has a non-empty, company-unique employee number.

## Assumptions

- Import is synchronous (bounded at 5,000 rows); no queue needed.
- Employees are never deleted or deactivated by an import (absent rows are left as they are).
