# Tasks: Annual leave balance

**Input**: [spec.md](spec.md), [plan.md](plan.md) — "Revisions after Codex plan review" overrides earlier plan text. Each step ends green in WSL (Unit, Feature, Browser, Pint).

## Step 1 — Import and snapshots
- [ ] T001 Migration: balance batches (immutable), batch events, balances, row results; composite FKs, unique keys, triggers, FK-safe down(); models with BelongsToCompany
- [ ] T002 `LeaveBalanceFile` (header + PLAN/ACT sub-header scan, cached formula values, code rules, mapping suggest/validate/save)
- [ ] T003 `LeaveBalanceImport` preview/commit, `ImportLeaveBalanceJob` (idempotent), unknown-code issue, `LeaveBalances` current/history/presence; translations for new errors
- [ ] T004 Tests: synthetic fixture with formulas and a sub-header, fractional/negative values, numeric codes, unknown/duplicate/invalid rows, idempotency (same file + date; same file + new date), out-of-order dates, retry, isolation, composite FKs, triggers

## Step 2 — Screens
- [ ] T005 Upload/preview/commit page, batch list + detail (skipped rows), HC upload link, original download route (audited)
- [ ] T006 Employee page balance card + history, dashboard employee view, statement XLSX + PDF; translations, UI inventory, route sweep, Livewire + browser tests

## Step 3 — Verification (orchestrator)
- [ ] T007 Real report on the smoke DB (matched 974, unknown 4, missing 41, negative 5; a second upload with a later date shows history), ROADMAP renumbering, docs, PR
