# Feature Specification: Annual leave balance

**Branch**: `508-leave-balance` | **Created**: 2026-10-24 | **Status**: Clarified 2026-10-24

**Input**: Product owner, 2026-10-24: HR uploads each employee's annual leave balance from the company report, separately from the HC employee list, and the app shows it on the employee page with history. Sample: `D:\Attandance\IRAQ Annual Leave Report 2026.xlsx` (real names, never committed).

## Sample findings (2026-10-24)

- One sheet "Iraq Annual Leave", header on row 1, 978 employee rows plus one sub-header row ("Employee Group", "PLAN"/"ACT" under the month columns).
- `Pers.No.`: 8 digits, unique; 974 match `employees.employee_code` exactly, 4 are not in HC, 41 HC employees are not in the report.
- Balance columns: `REMAINING FROM 2025` (number, may be negative), `ANNUAL LEAVE DAYS EMPLOYEE IS/TO BE ENTITELED IN 2026` (number or formula), `TOTAL ANNUAL LEAVE DAYS` (formula), `YTD Act Used (Actual)` (formula, sum of the monthly ACT columns), **`Total Remaining Annual Leave Days`** (formula = total − used). All formula cells carry cached values that the reader can return; values are fractional days (e.g. 3.8333) and 5 are negative (min −3.5, max 179.17).
- Monthly `ACT` used days for January–September are filled; October–December are empty.
- The file has no "as of" date.

## Clarifications (product owner, 2026-10-24)

- **Use**: show the balance on the employee page (and the dashboard employee view and exports), and keep every upload as a dated snapshot so HR sees how the balance changed. No warnings and no cross-check against imported leave requests in this spec.
- **Upload**: its own screen ("Leave balance"), runnable alone at any time; the HC upload screen links to it. It never changes employee records.
- **Order**: this is spec 508; overtime moves to 509, notifications 510, description AI 511, chat 512.

## Requirements

- **FR-001 Upload**: HR uploads the balance report (XLSX/CSV). The header row is found by the `Total Remaining Annual Leave Days` header (case/space-insensitive); the column mapping is suggested from the known headers and editable (saved per company like the leave import mapping). Mapped fields: employee code (required), remaining balance in days (required), carried over from last year, entitlement this year, used this year, monthly used days (optional, by month). HR enters the **balance date** ("balance as of", default today, not in the future) and the balance year (default from the headers, e.g. 2026). The original file is kept on the private disk with uploader and time.
- **FR-002 Preview**: before commit, show rows, matched employees, unknown codes (with samples), duplicate codes, invalid values (non-numeric, blank required value), employees with a negative balance (count), HC employees missing from the file (count), and changed / unchanged balances vs the latest snapshot. Commit is blocked only by mapping errors; bad rows are skipped and listed.
- **FR-003 Snapshots**: each committed upload is a batch; each matched row becomes an append-only balance snapshot (employee, balance date, year, remaining, carried over, entitlement, used, monthly used, batch, source row number). Re-uploading the same file (same sha256 and balance date) creates nothing new. Uploading an older balance date after a newer one is stored but never becomes the "current" balance (latest balance date wins, then latest upload).
- **FR-004 Display**: employee page shows the current balance (days with two decimals and the hour equivalent at 8 h/day), balance date, carried over, entitlement, used this year, and a history table of all snapshots (date, remaining, change vs previous, upload). Employees not in the latest upload show "Not in the latest balance report" with their last known value. Dashboard employee view and the employee statement / Excel export show the current balance and date.
- **FR-005 Unknown employees**: unknown codes are listed in the batch result and as one grouped data issue per batch (like the leave import), never creating employees.
- **FR-006 Permissions, isolation, audit**: HR + platform admin; tenant tables with composite FKs, isolation and trigger tests; snapshots and batches append-only (triggers); upload, commit and download audited; no balance values in audit metadata beyond counts.
- **FR-007 Screens and language**: upload/preview/commit screen, batch list with status and counts, batch detail with skipped rows; English + Arabic; UI inventory and route sweep.
- **FR-008 Tests**: header detection with a sub-header row; formula cells read as cached values; fractional and negative balances; code matching keeps leading zeros (exact text); unknown and duplicate codes; idempotent re-upload; older balance date never becomes current; employee page and export agree; isolation; triggers; the anonymised fixture is built from a small synthetic file (no real names).

## Out of scope

Warnings on low/negative balance, cross-checking used days against imported leave requests, deducting leave from the balance (the company leave system owns the balance), and balances for leave types other than annual leave.
