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


## Revisions after Codex plan review (2026-10-22) — these override the sections above

1. **Snapshot order.** Each batch stores `snapshot_at` = the timestamp in the file name (`IRAQ All Leave REPORT-YYYY-MM-DD-hh-mm-ss`) or else the upload time; HR can edit it on the scope step. Every observation of a request is kept as a version with its batch's `snapshot_at`. The request's current values come from the version with the latest `snapshot_at` (tie → higher batch id), then HR decisions apply on top. An older report uploaded later only adds history. Status derivation applies the rejection-date rule first.
2. **Full replay per employee.** `Reconciler` locks the affected employees (sorted ids) and replays **each affected employee's whole allocation chain** (all current request days, credits and eligible targets), then supersedes the previous allocations/credits in one transaction. Ordering: targets by `(work_date, kind: absence < late < early < exit, day id)`; credit sources by `(source work_date, request external_id, request day id)`. A credit may fund a target only if the target's `work_date` ≤ its `expires_on` (when set). Overlapping jobs serialise on the locks; the last replay is the single current result.
3. **Allocation references.** `leave_allocations` has `source_request_day_id` and `source_credit_id` (exactly one set; composite FKs on each) instead of a polymorphic pair. Available credit = credit minutes − current allocations from it (no mutable `remaining_minutes`). Cancellation/rejection removes the request from the replay: its days, credits and every deduction funded by those credits disappear, and the freed targets are refilled by other valid credits FIFO (consistent with automatic deduction). An HR "reverse deduction" is an append-only decision keyed by the stable target identity `(employee_id, work_date, target_kind)`; the replay never auto-funds that target again until the decision is withdrawn.
4. **Days changing.** `DayAssessor` emits `DaysAssessed(companyId, employeeIds, from, to)` after commit (including days removed or superseded), which dispatches `ReconcileLeaveJob`. Eligible targets per current `Day` status: `absent` → absence of `min_presence_minutes` (480); `present` → late, early, and exits only when the company setting counts exits; `missing_punch` → none until HR confirms absence (then absence); `pending`, `device_outage`, `rest_day`, `not_employed`, `pattern_missing` → none. Reconciled / remaining minutes and "absence closed by request X" are a derived read model over current allocations; `Day` rows are never edited.
5. **Full reports.** A full-report batch declares `period_from`/`period_to` (HR, default = min/max start date in the file). After commit, every request previously seen with a `start_date` inside that period and absent from this file raises a `leave_request_missing` issue keyed `request:{id}`; it resolves automatically if the request reappears in any later report. Partial reports never raise or resolve it. Nothing is ever cancelled automatically.
6. **Distribution (updated by product owner, 2026-10-22).** Fold sparse employee versions to get each date's employee working-day status. Validate ceil(d) against the first equal count in this order: employee working days; company leave-calendar working days (new leave_settings.leave_calendar_rest_days, default [5] for Friday); all calendar days. Store the chosen method on the current leave_requests row (counting_method; nullable when invalid or ineligible). Assign floor(d) times 480 minutes then 240 for a final half day to the employee's own working dates only, in order and up to the number available within [start,end]; counted rest days carry no minutes. No matching count means duration_mismatch, no days until HR confirms. A setting change replays affected employees. Invalid dates likewise produce no days until fixed.

7. **Leave types.** `normalized_key` = lower-trimmed `name` + `|` + lower-trimmed `other_name` or empty, non-null, unique per company. Seeded on first sight with Q3/Q4 defaults (incl. `other|unpaid leave` → covers); `0200` and unseen keys stay unmapped (issue). A mapping change triggers redistribution and reconciliation of affected requests.
8. **Import scale.** Streaming read; preview keeps counts + at most 50 samples per kind and detects in-file duplicates with the 502 `PreviewKeys` temp-table approach; commit inserts in chunks of 1,000; a version is written only when the parsed values differ from the latest observation; unique `(batch_id, row_number)` makes retries idempotent. Indexes: requests `(company_id, source, external_id)`, `(company_id, employee_id, start_date)`; versions `(company_id, request_id, snapshot_at, batch_id)`; request days `(company_id, employee_id, work_date, superseded_by_run_id)`; allocations `(company_id, employee_id, superseded_by_run_id)`, `(company_id, target_day_id)`.
9. **Docs first.** CLAUDE.md §4 and WEIRDNESS record the product-owner override (automatic FIFO credit deduction with explanations).
10. **Tests per step**: tenancy maps + unique busting, isolation, trigger smoke in step 1; route sweep, UI inventory, translations with the screens; step 2 includes synthetic overlapping-date reconciliation tests (the real leave sample does not overlap the punch sample).

**Steps (final):** (1) schema + import (snapshot order, versions, types, issues, missing-from-full-report), runnable alone; (2) `DaysAssessed` event + distribution + replay reconciler + reversal + HR actions, with exhaustive tests; (3) screens + 503 day-detail section; (4) real-data check, docs, PR.
