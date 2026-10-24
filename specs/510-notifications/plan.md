# Implementation Plan: Follow-up cases and email notifications

**Branch**: `510-notifications` | **Date**: 2026-10-24 | **Spec**: [spec.md](spec.md)

## Summary

After each leave replay, a deterministic `CaseSync` service derives one follow-up case per employee-day from the current day results and `DayReconciliation` (remaining minutes + pending requests). Cases are append-only versions with an event history. A `NotificationBatches` service groups due cases into one message per recipient (direct manager as of the work date, approver mapping for pending leave, HR fallback email), stores them, and sends them as queued, idempotent mail jobs — immediately in `auto` mode or after HR review in `review` mode.

## Technical Context

- Day results: `attendance_days` (run/supersede, `status` incl. `absent`, `missing_punch`, `present` with late/early/exit minutes; pending / outage / not_employed / rest_day statuses never become cases).
- Reconciliation: `App\Services\Leave\DayReconciliation::for($employee, $from, $to)` → per date `remaining` (absence/late/early/exit), `pending_requests` (external id, `approver_name`), closure info. The replay runs in `ReconcileLeaveJob` (`Reconciler::replayEmployee`, employee-locked) after `DaysAssessed` / leave imports / decisions.
- Employees: `email`, `manager_employee_id`, `fallback_notification_email` are effective-dated through `EmployeeHistory` (`asOf`). HC has no emails today.
- Data issues: `App\Services\Attendance\DataIssues::open(company, type, subjectType, subjectKey, batchId, details)` (grouped by key), `resolve`, `dismiss`.
- Settings pattern: `App\Models\Leave\Setting` + `LeaveSettings` (audited setters). Scheduler: `routes/console.php`. Mail: `MAIL_MAILER=log` in dev; Laravel queued mailables on the database queue.
- Audit/append-only: `AuditLogger`, MySQL triggers as for other history tables; composite tenant FKs; ≤ 64-char identifiers.

## Design

- **Namespace**: attendance module (`App\Models\Attendance`, `App\Services\Attendance`, a `FollowUp` sub-namespace if it reads better **[A]**); FOUNDATION-CHANGES rows only where shared files change.
- **Tables** (`*_attendance_*` migrations):
  - `attendance_cases`: company, employee, work_date, day id, state (`open|waiting_manager|waiting_approver|waiting_hr|closed`), items json (type + minutes), pending request ids json, closure reason/source/time, run/supersede ids (new version only when the derived content changes; stable key employee + work_date), first_sent_at, last_sent_at, reminders_sent.
  - `attendance_case_events` (append-only): case key, event (`opened|changed|sent|reminded|moved_to_approver|moved_to_manager|closed|reopened|closed_manually|excluded`), before/after, source, actor, time.
  - `attendance_approver_mappings`: company, approver_text (normalised key), employee id, confirmed_by, confirmed_at; follow the established versioned/audited pattern **[A]**.
  - `attendance_notification_settings`: company, mode (`review` default | `auto`), build_time `09:00`, reminder_interval_days 2, reminder_max 3, language `en|ar`.
  - `attendance_notification_batches`: company, cut_off, built_at, built_by (null = scheduler), status (`draft|sending|sent|partly_failed|cancelled`), mode at build time.
  - `attendance_notification_messages`: batch, recipient kind (`manager|approver|fallback`), recipient employee id (nullable), address, language, status (`draft|excluded|queued|sent|failed`), attempts, last_error; unique (batch, address).
  - `attendance_notification_message_cases`: message ↔ case version + kind (`first|reminder|approver_reminder`); unique (batch, case key) so a case appears once per batch.
  - `attendance_notification_attempts` (append-only): message, time, address actually used, result, error.
- **CaseSync** (called at the end of `ReconcileLeaveJob` per employee for the replayed range, same company context; also by reassess-all): for each current day in range → items with remaining > 0 (`absence`, `late`, `early`, `exit` from `remaining`; `missing_punch` from status). No items → close the current case (reason from the closing source: approved leave id, correction, reassessment). Items + a pending request covering the date → `waiting_approver` (approver from mapping; unmapped → `waiting_hr` + data issue `approver_unmapped` keyed by approver text). Items, no pending → `open` / `waiting_manager` (kept when already sent). A request rejected/cancelled → back to the manager (`reopened`). Writes nothing when the derived case equals the current version (idempotent, like credits).
- **Recipient resolution** (at batch build, as of the case work date via `EmployeeHistory::asOf`): manager case → manager's email; else employee's `fallback_notification_email`; else data issue `notification_recipient_missing` (grouped per employee) and the case is left out. Approver case → mapped employee's email, else fallback, else data issue. Never an address derived from a name.
- **NotificationBatches::build(company, actor|null)**: under a company lock, cut-off = now; due = cases in `open|waiting_manager|waiting_approver` with (never sent) or (`last_sent_at` ≤ cut-off − interval working days, by the company leave calendar rest days) and `reminders_sent < max`; group by address → one message each; mark nothing as sent yet. `auto` → `send(batch)` right away. `review` → draft; HR may exclude messages, then `send`.
- **Sending**: `SendNotificationMessageJob` per message (`afterCommit`, unique id, retries with backoff); it reloads the message, sends only when `queued`, records an attempt, sets `sent`/`failed`, and on success stamps the included case versions (`first_sent_at`, `last_sent_at`, `reminders_sent`) with case events. Retry re-queues failed messages only. A mail failure never touches cases beyond the attempt record.
- **Email content** (Mailable + Blade, en/ar, RTL for Arabic): table of employee name, code, date, issue type, minutes, required action; approver reminders show form number + dates only (no leave type, no description). No personal data in logs.
- **Scheduler**: `attendance:build-notifications` every 15 min; builds when the company's build time passed today and no scheduled batch exists for today (idempotent).
- **Screens** (Filament admin pages, thin; services do the work): Cases list + filters + export; case panel on day detail and employee page; Notifications (settings form, batches, draft preview, exclude, Send, retry); Approver mappings (suggested exact matches, confirm/change); employee page email + fallback email fields (effective-dated through `EmployeeHistory::record`, source `manual`); dashboard tiles (open, waiting managers, waiting approvers, waiting HR) with drill-down; statement section "Open follow-up cases".
- **Permissions**: reuse the existing HR attendance/leave gates for viewing; a send ability for building, sending, excluding and changing settings, following the existing role/permission pattern **[A]**.

## Steps

1. Tables, models, triggers, settings; `CaseSync` from reconciliation (states, closure, reopen, idempotent versions, data issues for unmapped approvers); approver mappings service; hook into `ReconcileLeaveJob` + reassess-all; tests (test 23, rejection reopen, `Leave Entry` nothing, pending/outage no case, overtime no case, idempotent re-upload, isolation, triggers).
2. Recipient resolution, `NotificationBatches` (build, due rule with working days, grouping, review/auto, exclude), send job + attempts + retry, Mailable en/ar, scheduler command; tests (test 24, interval/max, double build, failure/retry, no medical details, missing recipient issue).
3. Screens, tiles, export, statement section, employee email entry, translations, UI inventory, route sweep, Livewire + browser tests, agreement.
4. Real data on the smoke DB (HC + transactions + leave report; synthetic emails / approver mappings), docs, PR.
