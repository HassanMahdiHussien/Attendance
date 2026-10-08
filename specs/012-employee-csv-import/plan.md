# Implementation Plan: Employee CSV Import

**Branch**: `012-employee-csv-import` | **Date**: 2026-10-08 | **Spec**: [spec.md](./spec.md)

## Summary
A Filament admin page (Employees → "استيراد من CSV") with template download, upload, a full preview (new / update /
error per row) and an all-or-nothing confirm that creates or updates employees in one transaction, generating missing
employee numbers from the existing sequence service.

## Technical Context
PHP 8.4 · Laravel 13 · Filament 5 (admin panel, tenant = company) · PHP's native CSV reader (`fgetcsv`) and
`mb_convert_encoding` for Windows-1256 · existing `SequenceService` (EMP codes) · Pest · no new packages.

## Research (decisions)
- **R1 Parsing**: read bytes; strip UTF-8 BOM; if not valid UTF-8 → convert from Windows-1256 (`mb_convert_encoding`
  with 'CP1256' — verify availability in the container, else `iconv`); detect delimiter from the header line (count of
  `;` vs `,`); `fgetcsv` on a memory stream; trim cells; header map accepts Arabic or English names (case/space
  insensitive). Limits 2 MB / 5,000 data rows.
- **R2 Validation** in a service `EmployeeImport::preview(Company, UploadedFile): ImportPreview` — pure (no writes):
  per-row status, errors, normalised values (dates parsed from `Y-m-d` or `d/m/Y`, gender mapped, branch id resolved),
  existing employee by code → "update" with a diff of changed fields (empty optional cells keep stored values).
- **R3 Commit** `EmployeeImport::commit(Company, UploadedFile, User)`: re-runs `preview` (the uploaded temp file
  persists in Livewire); refuses if errors; one DB transaction: updates first, then creates with codes from
  `SequenceService` (row-locked; skips used numbers); audit `employees.imported` with counts, file name and sha256,
  plus `employee.updated` entries with changed fields (existing audit style).
- **R4 UI**: a Filament page `ImportEmployees` under the Employee resource (header action link), RTL, uses Filament
  FileUpload (csv/txt, 2 MB) or a Livewire file input, preview table (row number, status badge, name, code or
  "سيُولَّد", branch, errors / changes), totals, confirm button disabled while errors exist; template download route
  returning a UTF-8-BOM CSV with Arabic headers and one example row.
- **R5 Security**: authorisation via the existing EmployeePolicy create+update for the tenant; values stored as text
  (formula-looking cells are just strings in the DB; nothing is exported to spreadsheets here); tenant scope on every
  lookup.

## Constitution Check
| Principle | Status | How |
|---|---|---|
| I. Tenancy | PASS | branch lookup and employee codes within the tenant company; policy check |
| II. AI boundary | PASS | no AI |
| III. Immutable | PASS | issued documents keep frozen facts; changes audited |
| IV. Tests | PASS | encodings, validation, all-or-nothing, update diff, sequence, authorisation |
| V. Thin UI | PASS | Filament page → EmployeeImport service |
| VI. Decided | PASS | preview/all-or-nothing and update-on-existing per product owner |

## Structure
```text
app/Services/Employees/{EmployeeImport, ImportPreview, CsvReader}.php
app/Filament/Admin/Resources/EmployeeResource/Pages/ImportEmployees.php (+ view) · header action on ListEmployees
routes (template download) · tests/Feature/Employees/EmployeeImportTest.php · tests/fixtures/employees/*.csv
```

## Delegation
Codex plan review → run U (all tasks) → orchestrator gates, browser walkthrough with a UTF-8 and a Windows-1256 file,
PR.
