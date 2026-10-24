# Feature Specification: Follow-up cases and email notifications

**Branch**: `510-notifications` | **Created**: 2026-10-24 | **Status**: Clarified 2026-10-24 (assumptions marked **[A]** are to be checked with HR before go-live)

**Input**: ROADMAP "later": notifications. PRD §17 (cases and notifications), §11.1/§156 (pending leave follows the current approver), §18–§20 (profile, dashboard, reports: open cases, cases waiting for HR or managers), §21 (no medical details in email; failures never corrupt data; retry), §25 tests 23–24. ROADMAP §2 (pending statuses remind the `Form Approver`, never the employee; `Leave Entry` reminds nobody). CLAUDE.md: never send an employee a formal request automatically; never open a disciplinary case.

## What the real files show (2026-10-24)

- `HC.xlsx` has **no email column**: 0 of 1,015 employees have an address. 995 employees have a direct manager (`Personnel number of superior`, 136 managers).
- Leave report: 693 pending requests (449 `Manager Approval`, 243 `HR Approval`, 1 `Department Manager Approval`). `Form Approver` is a plain English name (103 distinct on pending rows); only 2 match an employee name exactly. The approver is therefore resolved through an HR-maintained mapping, never guessed.

## Clarifications (product owner, 2026-10-24)

- **Sending mode** is a per-company setting: `review` (default) — each batch is built as a draft, HR checks recipients and content and presses Send; `auto` — the batch is sent as soon as it is built. Changing the mode is audited.
- **Recipients**: the employee's **direct manager only** (one consolidated email per manager per batch). Employees receive nothing directly. When the manager (or the manager's address) is missing, the employee's HR-designated fallback email is used; with neither, nothing is sent and a grouped data issue is raised.
- **Pending leave**: the reminder goes to the request's current approver (mapped from `Form Approver`), never the employee.
- **Email addresses**: HR enters an employee's email and fallback email on the employee page now (effective-dated, like other employee values); the future email / work-pattern file loads them as a partial employee update (the email column is already supported).
- **Reminders**: first email in the next batch after a case opens, then a reminder every **2 working days**, at most **3 reminders**; interval and maximum are company settings.

## Requirements

- **FR-001 Cases**: one case per employee per work date that still needs follow-up after assessment and leave reconciliation: uncovered absence, missing punch, or remaining unreconciled hours (lateness, early departure, short hours after leave allocations and credit). A case lists its items (type, minutes) and links its day, and the leave requests touching it. Re-upload, reassessment and replay update the same case (stable key employee + work date); never duplicated. Pending / incomplete / device-outage / not-employed / rest days never open a case. Overtime never opens a case (PRD §17).
- **FR-002 Case state** (derived, with an append-only event history): `open` → `waiting_manager` (sent to the manager) or `waiting_approver` (a pending request covers it — follow-up moves to the approver) → `closed` when supporting data resolves it (approved leave fully covering it, a punch correction, a reassessment with nothing left), with closure reason, source and time. Sending never closes a case. A rejected or cancelled request reopens follow-up with the manager. HR can close a case manually with a reason (audited) **[A]**.
- **FR-003 Approver mapping**: a company list mapping each `Form Approver` text to an employee; exact name matches are suggested, HR confirms; an unmapped approver on a pending request raises one data issue per approver text and the case waits with HR. Mappings are audited and effective immediately.
- **FR-004 Batches**: a notification batch is built per company on a schedule (daily at a configurable time, default 09:00 Asia/Baghdad **[A]**) and on demand by HR. It contains every case due now: new cases not yet sent, and open cases whose last email is at least the reminder interval (working days per the company leave calendar rest days) old and below the reminder maximum. Cases are grouped into **one message per recipient** listing employee name, code, date, issue type, required action. In `review` mode the batch stays a draft until HR sends it (HR may exclude a message); in `auto` mode it is sent right away. Building a batch twice for the same cut-off includes no case twice.
- **FR-005 Delivery**: messages are sent as queued jobs (one per message), idempotent on retry; every attempt records time, actual recipient address, success or the failure reason; a failed message can be retried; a failure never alters cases, days or balances. Email body never contains leave types that reveal medical details (e.g. sick leave) or leave descriptions **[A]**: pending-leave reminders name the request (form number, dates), not its type.
- **FR-006 Settings page**: sending mode, daily build time, reminder interval and maximum, email language (English or Arabic, per company **[A]**); all changes audited.
- **FR-007 Screens**: Cases list (filters: state, type, branch, manager, period; drill-down to day detail); case section on day detail and the employee page; Notifications page (batches with status, draft preview, Send / exclude, message status and retry); approver mapping page; employee page email and fallback email entry; dashboard tiles "Open cases", "Waiting for managers", "Waiting for approvers", "Waiting for HR" (counts = lists); a cases Excel export; the statement lists open cases. English + Arabic; email templates in both languages, RTL for Arabic.
- **FR-008 Tests**: PRD §25 test 23 (approved leave closes the case; pending leave sends reminders to the approver) and 24 (a fallback manager receives one consolidated email per batch, not one per employee); re-upload creates no duplicate case or message; reminder interval and maximum; review vs auto; manual exclusion; failure + retry; unmapped approver → data issue, nothing sent to a guessed person; rejection reopens with the manager; `Leave Entry` reminds nobody; no medical details in the body; isolation; triggers; agreement across dashboard, list, export and employee page.

## Out of scope

Emails to employees; disciplinary cases; unmatched-overtime follow-up (PRD §17); notifications about unused compensatory days **[A]**; SMS / in-app channels; inbound replies; AI.
