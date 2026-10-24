# Implementation Plan: Annual leave balance

**Branch**: `508-leave-balance` | **Date**: 2026-10-24 | **Spec**: [spec.md](spec.md)

## Summary

A standalone upload of the company annual leave report creates dated, append-only balance snapshots per employee; the employee page, dashboard employee view and exports show the current balance and its history. No calculation uses the balance yet.

## Technical Context

- Reuse the spec 504 leave import pattern (`LeaveFile` inspect/suggest/validateMapping, `PreviewKeys` preview token, private storage, batch + events, queued commit job, `UnknownEmployeeIssues`) — read those files and match them; do not generalise them unless a helper is reused unchanged.
- OpenSpout XLSX: formula cells are `FormulaCell`; read `getComputedValue()` (cached value) for every mapped numeric column; a formula cell with no cached value is an invalid value for that row. CSV: plain values.
- Numbers: store days as `decimal(9,4)` (fractions like 3.8333); display 2 decimals; hours = days × 8 (ROADMAP: 1 day = 8 h).
- Codes: exact text match on `employees.employee_code` (leading zeros kept; a numeric cell is converted without decimals or exponent).

## Constitution Check (2.0.0)

I tenant-scoped + isolation tests; III originals kept, snapshots append-only; II no AI, deterministic; V audit on upload/commit/download. Imported data only — the app never changes a balance.

## Design

- **Schema** (`*_leave_*`, Leave namespace): `leave_balance_batches` (company, uploaded_by, file_path, file_name, sha256, balance_date, balance_year, mapping json, status queued|running|completed|failed, counts json, error, timestamps; unique (company_id, sha256, balance_date)); `leave_balance_batch_events` (append-only, like leave batch events); `leave_balances` (append-only: company, batch, employee, balance_date, balance_year, remaining_days, carried_over_days, entitlement_days, used_days, monthly_used json, source_row; unique (company_id, batch_id, employee_id)); index (company_id, employee_id, balance_date). Composite tenant FKs, ≤ 64-char names, FK-safe down(), triggers blocking update/delete on balances and events.
- **Services**: `LeaveBalanceFile` (inspect: header row by the remaining-balance header, skip the sub-header row whose code cell is not a code; suggest mapping; validate mapping), `LeaveBalanceImport` (preview → counts/samples/token; commit → store file, batch, dispatch `ImportLeaveBalanceJob`), job (chunked inserts, unknown-code issue, counts, idempotent on retry), `LeaveBalances::current(employee)` and `::history(employee)` (latest balance_date then latest batch id).
- **Screens**: Filament page `Leave/UploadLeaveBalance` (file, balance date, year, mapping, preview, commit), `Leave/LeaveBalanceBatches` (list + detail with skipped rows); HC upload screen gets a link. Employee page attendance/leave section: current balance card + history table. Dashboard employee view + employee statement PDF/Excel: current balance and date.
- **Docs**: ROADMAP renumbering (508 balance, 509 overtime, 510 notifications, 511 description AI, 512 chat).

## Steps

1. Schema, models, file reader (formula cached values, header detection, mapping), preview, commit, job, unknown-code issue, snapshots, current/history service; tests (FR-008 except screens).
2. Screens (upload/preview, batches, employee page balance + history, dashboard + exports), translations, UI inventory, route sweep, Livewire + browser tests.
3. Verification with the real report on the smoke DB (counts: matched 974, unknown 4, missing 41, negative 5; one employee's history after a second upload with a later date), docs, PR.
