# Implementation Plan: Leave import, coverage and reconciliation

**Branch**: `504-leave-import-and-matching` | **Date**: 2026-10-22 | **Spec**: [spec.md](spec.md)

## Summary

Import the company leave report into versioned requests, map leave types per company, spread approved covering requests over working days, and reconcile them against spec 503's office-day balances with a deterministic allocation ledger: same-day first, surplus to credit, credit auto-deducted FIFO with explanations, full reversal on cancellation or rejection.

## Technical Context

Same conventions as 501–503 (WSL, `testing_attendance`, attendance namespaces `App\…\Leave` / `*_leave_*` migrations, Codex implements, gates in WSL). Reuse: `TransactionFile`-style streaming reader and `SpreadsheetReader` cell conversion, positional mappings, confirmation-token + hash idempotency, batch events, `DataIssues`, processing runs with superseding under per-employee locks, `AssessmentTriggers` pattern.

## Constitution Check (2.0.0)

I isolation: all new tables tenant-scoped + tests. II rules calculate: reconciliation is deterministic service code; descriptions are not used (absent from the report). III immutability: batches, request versions, HR decisions append-only; allocations superseded/reversed, never edited. IV tests: PRD §25 2, 13, 14, 15, 23 + clarifications. VI: WEIRDNESS lines for the credit auto-deduction override and the rejection-date rule.

## Design

### Schema
- `leave_import_batches` (append-only, + events with sequence), `leave_import_mappings`.
- `leave_types` (per company: `name`, `other_name`, `covers` bool, `consumes` enum, `mapped_by`, timestamps; unique `(company_id, name, other_name)`); changes audited and trigger reconciliation.
- `leave_requests`: `source`, `external_id`, `employee_id` nullable + `owner_code`, `leave_type_id`, `status` (`approved|pending|draft|rejected|cancelled`), `raw_status`, `rejected_on`, `start_date`, `end_date`, `duration_days` (decimal 5,1), `approver_name`, `created_on`, `current_version_id`, `flags` (json), `cancelled_at/by/reason` via decisions; unique `(company_id, source, external_id)`.
- `leave_request_versions` (append-only): request, batch, all parsed fields + raw values json, `seen_at`.
- `leave_request_decisions` (append-only): `cancel|confirm_distribution|fix_dates|reverse_deduction|undo`, reason, actor.
- `leave_request_days` (derived per run, superseded): request, employee, `work_date`, `minutes` (480 or 240).
- `leave_allocations` (derived per run, superseded; `reversed_by_run_id`): `source_kind` (`leave_day|credit`), `source_id`, `target_day_id` (office day), `target_kind` (`absence|late|early|exit`), `minutes`, `explanation` (json: codes + parameters, rendered per language), `run_id`.
- `leave_credits` (derived per run): created from surplus `leave_day` minutes: `employee_id`, `source_day_id`, `minutes`, `remaining_minutes`, `expires_on` nullable.
- Indexes with explicit names ≤ 64 chars; composite FKs; reserved words backticked.

### Services (`App\Services\Leave`)
- `LeaveFile` (stream, header detection, `M/D/YYYY` and Excel dates, plausibility checks), `LeaveImport` (preview/commit, token, hash, scope, versions, missing-from-full-report alerts), `LeaveTypes` (seed from the first import with Q3/Q4 defaults, map, audit), `LeaveStatus` (derive status: rejected-date rule first, then `Approved`→approved, `Leave Entry`→draft, approval stages→pending), `LeaveDistributor` (working days by employee rest days via `EmployeeHistory` folded versions, 8/4 h, mismatch flag), `Reconciler::run(company, employeeIds, from, to)` (lock employees, load office days + request days + credits + HR decisions, compute: same-day allocations → credits → FIFO deductions with explanations; supersede previous allocations/credits; write changes), `LeaveActions` (cancel, confirm, fix, reverse deduction) → reconcile.
- Triggers: after leave import commit, leave type change, HR leave action, and after spec 503's day assessment (new `DaysAssessed` event from `DayAssessor` with employee ids + range) → `ReconcileLeaveJob` (unique per scope, after commit).
- Office day (503) display: reconciled and remaining minutes are read from current allocations; 503's `Day` rows are not modified (reconciliation is a separate derived layer).

### Screens (Filament admin, "Leave" nav group)
Leave upload (upload → mapping on new headers → scope → preview → commit → progress), leave requests (filters + flags), request detail (versions, days, allocations, HR actions), leave types (map covers/consumes), credit ledger per employee (credits, deductions with rendered explanations, balance). Day detail (503) gains a "Leave and reconciliation" section.

## Steps (Codex → my review → gates → commit)
1. Schema + import (file reader, status derivation, versions, data issues, types seeding, missing-from-full-report alerts) — runnable without reconciliation.
2. Distribution + reconciliation engine (pure allocator unit-tested exhaustively) + triggers + HR actions.
3. Screens + 503 day-detail section; translations, UI inventory, route sweep, help; Livewire + browser smoke tests.
4. Real sample (21,249 requests) on the smoke DB with the HC employees and the punch sample; report counts; docs; PR.

## Risks
- The leave sample (Jan–Sep 2026) does not overlap the punch sample (18–20 Oct 2026), so real-data reconciliation can only be verified on synthetic overlaps.
- Automatic credit deduction overrides PRD §11.2 by product-owner decision; reversal chains must stay exact (tested).
