# Tasks: Follow-up cases and email notifications

**Input**: [spec.md](spec.md), [plan.md](plan.md) — any "Revisions after Codex plan review" section overrides earlier plan text. Each step ends green in WSL (Unit, Feature, Browser, Pint).

## Step 1 — Cases
- [ ] T001 Tables (cases, case events, approver mappings, notification settings, batches, messages, message cases, attempts), models, composite FKs, triggers
- [ ] T002 `CaseSync` from `DayReconciliation` (items, states, closure with source, reopen on rejection/cancellation, idempotent versions), approver mappings + `approver_unmapped` issue; hooked into `ReconcileLeaveJob` and reassess-all
- [ ] T003 Tests: PRD test 23, rejection reopens, `Leave Entry` nothing, pending/outage/rest-day/not-employed no case, overtime no case, re-upload no duplicate, isolation, triggers

## Step 2 — Notifications
- [ ] T004 Recipient resolution (manager as of date, fallback, missing-recipient issue), `NotificationBatches` (due rule with working days, one message per address, review/auto, exclude), `SendNotificationMessageJob` + attempts + retry, Mailable en/ar, `attendance:build-notifications` scheduler
- [ ] T005 Tests: PRD test 24, interval/max, double build, review vs auto, exclusion, failure + retry, no medical details, unmapped approver never emailed

## Step 3 — Screens
- [ ] T006 Cases list + export, case panel on day detail and employee page, Notifications page (settings, batches, preview, Send, exclude, retry), approver mappings page, employee email/fallback entry, dashboard tiles + drill-down, statement section; translations, UI inventory, route sweep, Livewire + browser tests, agreement

## Step 4 — Verification (orchestrator)
- [ ] T007 Real data on the smoke DB, docs, PR
