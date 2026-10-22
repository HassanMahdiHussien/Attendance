# Feature Specification: Email Intake (Forwarded Reports)

**Feature Branch**: `014-email-intake`

**Created**: 2026-10-08

**Status**: Draft

**Input**: PRD §18.5 and CLAUDE.md §3b touchpoint 1: a department manager (or HR) can forward an email to a dedicated
address; the system turns it into a draft only — HR completes the structured fields before it becomes a case. The raw
email is never auto-converted into a case.

## Clarifications

### Session 2026-10-08

- Q: How do emails arrive? → A: A normal IMAP mailbox the app polls every few minutes (package
  `webklex/php-imap`; PHP 8.4 has no built-in IMAP extension).
- Q: How is the company found? → A: One shared address; the sender's address must belong to an active user account
  allowed to report (reporter, department manager, HR staff, company admin) in an active company. The draft goes to
  that company, and to the reporter's branch when the account is branch-scoped.
- Q: Unknown or inactive senders? → A: Discarded. Only a platform-level log entry is kept (time, sender domain, reason)
  — no content, no draft, nothing visible to any company.
- Q: AI pre-fill? → A: No. HR reads the email next to the empty structured form and types the facts. No new AI
  touchpoint.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - A manager forwards an incident email (Priority: P1)

A department manager forwards an email describing an incident (with photos attached) to the reports address. Within a
few minutes it appears in HR's report inbox, marked "بريد مُعاد توجيهه", with the sender, subject, received time, the
text and the attachments. The manager sees it in "بلاغاتي" with the bare status (received / under review / closed).

**Acceptance Scenarios**:
1. **Given** an email from an active department manager of company A, **Then** a draft appears only in company A's HR
   inbox, scoped to the manager's branch, with the plain text and allowed attachments.
2. **Given** the same message fetched twice, **Then** only one draft exists.
3. **Given** an email whose sender address matches no active allowed account, **Then** no draft exists anywhere and a
   platform log entry records the time, the sender's domain and the reason only.

### User Story 2 - HR turns the email into a case (Priority: P1)

HR opens the draft, presses "إنشاء حالة", and gets the normal new-case form with the email text and attachments shown
beside it (read only). HR enters place, time, violation type, the employee and the responsible person; the usual
checks (duplicate within 48 hours, conflict of interest, prior cases) apply. On save, the email's attachments are
attached to the new case and the draft is marked converted. HR can instead link it to an existing case or dismiss it
with a reason — exactly like a reporter's report.

**Acceptance Scenarios**:
1. **Given** an email draft, **When** HR creates the case, **Then** the case holds only the facts HR entered, the
   attachments are copied to the case, and the audit log records who converted which draft.
2. **Given** an email draft, **When** HR dismisses it with a reason, **Then** it leaves the inbox and the sender sees
   "مغلق".

### User Story 3 - The mailbox is safe to expose (Priority: P1)

**Acceptance Scenarios**:
1. **Given** an email with a forged From address (fails the receiving server's SPF/DKIM/DMARC check), **Then** it is
   discarded like an unknown sender.
2. **Given** an HTML email with scripts or remote images, **Then** only sanitised plain text is stored and shown.
3. **Given** an attachment that is not an allowed type (pdf, jpg, png, heic, docx) or is too large, **Then** it is
   dropped and the draft notes "تم استبعاد مرفق" with the file name only.
4. **Given** the mailbox is unreachable, **Then** the fetch fails quietly, retries next run, and the failure is
   visible to the developer (log + Healthchecks ping), never to companies.

### Edge Cases

- An email with no text and no allowed attachments: still a draft (subject only) — HR dismisses it.
- Very large bodies: stored truncated at 50,000 characters with a note.
- Several To/Cc recipients: irrelevant; only the sender decides routing.
- A user whose account is deactivated after sending: the draft stays (it was valid when received).
- Processed messages are moved to a "Processed" folder (or flagged seen if folders are not supported) so they are
  never fetched again; discarded ones to "Discarded".

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: A scheduled fetch (every 5 minutes, when enabled) reads new messages from the configured IMAP mailbox.
- **FR-002**: A message is accepted only if the sender address equals an active allowed user's email in an active
  company **and** the receiving server's authentication results show DMARC pass (or SPF and DKIM pass aligned with
  the sender's domain). Otherwise it is discarded with a metadata-only platform log entry.
- **FR-003**: Accepted messages become an email draft in the sender's company (branch from a branch-scoped account),
  idempotent by Message-ID per company.
- **FR-004**: Stored content: sender user, subject, sanitised plain text (HTML converted, never rendered), received
  time, allowed attachments on the private disk; nothing else of the raw message.
- **FR-005**: HR inbox, reporter "my reports" and the case-creation form treat email drafts like reporter reports
  (visibility, dismiss with reason, convert, link to existing case), with the email shown read-only beside the form.
- **FR-006**: Converting copies the attachments to the case; the audit log records receipt, conversion, linking and
  dismissal (no email content in the log).
- **FR-007**: No AI is used anywhere in this flow.

## Success Criteria *(mandatory)*

- **SC-001**: A forwarded email appears in the HR inbox within 5 minutes.
- **SC-002**: No email from a non-account or failing authentication ever reaches any company (automated tests).
- **SC-003**: A company's HR never sees another company's email (automated tenant test).

## Assumptions

- The developer creates the mailbox at a mail host they choose (credentials in the server `.env`), with the host's
  spam filter and SPF/DKIM/DMARC checks on (they add the `Authentication-Results` header the app reads).
- Volume is small (a few emails a day per company); polling is enough.
