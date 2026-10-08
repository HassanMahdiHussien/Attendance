# Implementation Plan: Email Intake (Forwarded Reports)

**Branch**: `014-email-intake` | **Date**: 2026-10-08 | **Spec**: [spec.md](./spec.md)

## Summary
A scheduled command polls one IMAP mailbox, authenticates each message (account match + the receiving server's
DMARC/SPF/DKIM verdict), and stores accepted ones as **email drafts** — a new tenant table, because a
`violation_reports` row requires structured facts an email does not have. The HR inbox lists email drafts next to
reporter reports; converting opens the normal case form with the email beside it.

## Technical Context
Laravel 13 · PHP 8.4 · new package (approved): `webklex/php-imap` (pure PHP, no ext-imap) · existing:
`ReportService`, `CaseDrafts`, `ViolationReportVisibility`, `Attachment` (morph), `AuditLogger`, Livewire
`Reports/Inbox`, `Reports/MyReports`, `Cases/CreateCase` · Pest.

## Research (decisions)
- **R1 Data model**: new table `email_drafts` (tenant): `company_id`, `branch_id` nullable, `sender_user_id`,
  `message_id_hash` (sha256 of the normalised Message-ID; unique with `company_id`), `subject` (≤ 255), `body_text`
  (≤ 50,000 chars, `body_truncated` bool), `excluded_attachments` JSON (file names only), `received_at`, `status`
  (`received` | `converted` | `linked` | `dismissed`), `dismiss_reason`, `case_id` nullable, timestamps; composite
  FKs like `violation_reports`; attachments via the existing `Attachment` morph. Model uses `BelongsToCompany`.
  Platform log: table `email_intake_rejections` (no `company_id`, platform-only): `received_at`, `sender_domain`,
  `reason` (`unknown_sender` | `inactive_sender` | `not_allowed_role` | `auth_failed` | `duplicate`), timestamps —
  no addresses, no subject, no body.
- **R2 Fetch** `email-intake:fetch` (scheduled every 5 minutes, `withoutOverlapping`, `->when(config enabled)`):
  connect with `webklex/php-imap` (host, port, encryption ssl/tls, validate_cert true, username, password, folder
  INBOX — env `INTAKE_IMAP_*`); for each unseen message in date order: parse → decide → store → move to `Processed`
  / `Discarded` (created if missing; fallback: set `\Seen`). Per-message failure logs the type and leaves the message
  for the next run; batch limit 50 per run. Healthchecks ping `email_intake` (success after a run, failure on
  connection error) via the existing `HealthPing`.
- **R3 Authentication** (`EmailSenderVerifier`): take the `From` address (exactly one, lower-cased); read the
  **first** `Authentication-Results` header whose authserv-id equals `INTAKE_TRUSTED_AUTHSERV_ID` (the receiving
  host's id; headers added further down the chain are ignored because a sender can forge them); accept when
  `dmarc=pass`, or when `spf=pass` with `smtp.mailfrom` domain = From domain and `dkim=pass` with `header.d` = From
  domain. No trusted header → reject `auth_failed`. Then the account: `users.email` equals the From address (case
  insensitive), `is_active`, company active, role in {Reporter, DeptManager, HrStaff, CompanyAdmin}; Platform Admin,
  external reviewers, HR partners, committee-only users are refused (`not_allowed_role`). Branch: the reporter's
  branch (reuse the same rule `ReportService::submit` uses for branch-scoped accounts), else null.
- **R4 Content** (`EmailContentSanitizer`): prefer `text/plain`; else convert HTML to text (strip tags with a
  DOM-based converter that keeps line breaks; drop script/style; never store HTML); normalise to UTF-8; strip control
  characters; truncate 50,000. Subject decoded (RFC 2047), stripped, ≤ 255. Attachments: allowed by **content
  sniffing** (finfo) and extension — pdf, jpeg, png, heic, docx; ≤ 10 MB each, ≤ 5 per message (same limits as the
  report form if those differ — reuse them); file names sanitised; stored with the existing attachment storage path
  pattern; inline images (cid) treated as attachments under the same rules; excluded names recorded.
- **R5 Inbox & visibility**: `EmailDraftVisibility::inbox` / `::for` mirroring `ViolationReportVisibility` (HR inbox:
  company admin + HR staff of the company, status `received`; sender sees own drafts with bare status mapped to
  received / under review / closed). The `Reports/Inbox` view lists both kinds sorted by received time, email
  drafts marked "بريد مُعاد توجيهه"; actions: dismiss (reason), link to existing case, create case. `MyReports`
  includes the sender's email drafts. Body shown escaped with `nl2br(e())`.
- **R6 Conversion**: `/cases/new?email=ID` — `CreateCase` loads the draft through `EmailDraftVisibility::inbox`,
  shows subject/text/attachment list read-only beside the form, pre-fills only the branch (if known); on save
  `EmailDraftService::convert` (in the same transaction as `CaseDrafts::create`) copies attachments to the case
  (new rows + file copies), sets `status=converted`, `case_id`; link-to-existing does the same against the chosen
  case (`linked`) and logs a `case_events` entry like `duplicate_report_linked`. Audit: `email_draft.received`
  (system actor), `.converted`, `.linked`, `.dismissed` with ids only.
- **R7 Config**: `config/displanery.php` → `email_intake` (enabled, imap settings, trusted authserv-id, folders,
  limits); `.env.example` keys empty; Healthchecks key `HEALTHCHECK_EMAIL_INTAKE_URL`; runbook section in
  `deploy/README.md` (mailbox setup, authserv-id discovery from a test message's headers, folders).
- **R8 Tests**: a fake message source (interface `IntakeMailbox` with an IMAP implementation and an in-memory fake
  for tests — the only new abstraction, needed because tests cannot reach an IMAP server); cases: accepted manager
  email → draft in right company/branch; duplicate Message-ID; unknown / inactive / disallowed-role senders →
  rejection rows only, no draft; forged From (no trusted header / header from another authserv-id / dmarc=fail);
  HTML → text, script removed; disallowed and oversized attachments excluded; tenant isolation of inbox and
  conversion URL; conversion copies attachments and logs; dismiss; MyReports status; fetch failure pings and does
  not throw.

## Constitution Check
| Principle | Status | How |
|---|---|---|
| I. Tenancy | PASS | drafts are tenant rows; routing only via the sender's own account; visibility classes; tenant tests |
| II. AI boundary | PASS | no AI; HR types every fact |
| III. Audit | PASS | receipt/convert/link/dismiss audited without content |
| IV. Tests | PASS | R8 |
| V. Thin UI | PASS | services: fetcher, verifier, sanitizer, EmailDraftService |
| VI. Decided | PASS | product owner chose IMAP, sender routing, discard, no AI |

## Structure
```text
database/migrations/2026_10_14_000000_create_email_drafts.php
app/Models/{EmailDraft,EmailIntakeRejection}.php · app/Enums/EmailDraftStatus.php
app/Services/EmailIntake/{IntakeMailbox,ImapMailbox,IntakeMessage,EmailSenderVerifier,EmailContentSanitizer,
  EmailIntakeProcessor,EmailDraftService}.php · app/Support/Access/EmailDraftVisibility.php
routes/console.php (email-intake:fetch + schedule) · app/Livewire/Reports/{Inbox,MyReports}.php + views ·
app/Livewire/Cases/CreateCase.php + view · config/displanery.php · .env.example · deploy/README.md ·
tests/Feature/EmailIntake/*.php
```

## R9 Plan review resolutions (Codex review, 2026-10-08) — override R1–R8 where they differ

### Blockers
- **A1 Trusted verdict = the topmost header only.** The receiving host prepends its own `Authentication-Results`
  above anything the sender supplied, so the verifier reads the raw header block preserving every instance and order
  (not webklex `Header::get()`, which merges duplicates) and uses **only the topmost** `Authentication-Results`
  header; its authserv-id must equal `INTAKE_TRUSTED_AUTHSERV_ID`, else reject `auth_failed`. A forged header with the
  right id sits below the host's real one and is never read. Runbook: the mail host **must** add its own header
  (Gmail/Google Workspace, Microsoft 365, Fastmail and similar do; a host that does not cannot be used), and a
  verification step: `php artisan email-intake:inspect-latest` prints, for the newest INBOX message, the topmost
  authserv-id and verdict only (no addresses/subject), plus a deliberate forged-header test message that must be
  rejected.
- **A2 Tenant context**: after the sender resolves to a company, all draft/attachment work for that message runs in
  `TenantContext::runForCompany($company, …)`; rejection rows and UID bookkeeping are platform rows outside it.
  Tests: a message from a company-B user never creates or exposes anything in company A (fetcher and UI).
- **A3 Branch rule**: a sender with exactly one `branch_user` branch → that branch; zero or several → `branch_id`
  null (company-level draft) and HR must choose the branch on the case form. Tests for single, multi and none.
- **A4 Attachments**: `EmailDraft` added to the attachment morph map and `Attachment::booted` allowed parents;
  `AttachmentService` gains an email-draft store path (same sniffing/limits as today: **PDF/JPEG/PNG, 10 MB** — no
  HEIC/DOCX) and an authorised download for drafts (HR inbox visibility only). Conversion/linking copies files then
  rows inside the transaction; a failure deletes the files copied so far (try/catch around the transaction). Case
  attachment kind for copied files: the existing report-evidence kind (Codex: add it to the case-allowed kinds if
  missing). Tests: draft download authorised/denied cross-tenant, conversion and linking copy, rollback leaves no
  orphan files.
- **A5 Idempotency by IMAP identity**: platform table `email_intake_messages` (`mailbox`, `uidvalidity`, `uid`,
  `outcome` accepted|rejected|failed, `attempts`, `email_draft_id` nullable, timestamps; unique
  mailbox+uidvalidity+uid). Fetch reads the oldest 50 INBOX messages by UID with `leaveUnread()` (never relies on
  `\Seen`); a recorded UID is not processed again, only its move is retried. The outcome row is written in the same
  transaction as the draft. Message-ID (when present) still deduplicates drafts per company; a missing Message-ID
  falls back to UID identity only. A message that fails 3 times is moved to `Discarded` with outcome `failed`
  (counted in the health ping), so it cannot starve later mail. Tests: stored-then-move-fails-then-refetched →
  one draft; missing Message-ID; parse failure ×3 → discarded.

### Should-fix
- **B1** Accept when the trusted verdict has `dmarc=pass`, **or** (`spf=pass` aligned) **or** (`dkim=pass` aligned),
  alignment relaxed (same domain or a subdomain of the From domain). Upstream ARC / other `Authentication-Results`
  headers are ignored. No usable trusted header → reject and count it as a configuration warning in the health ping.
- **B2** Visibility: HR staff / company admin senders see their own email drafts in the HR inbox (they are HR);
  reporters and department managers see theirs in `MyReports` with the bare status. Same split as reports.
- **B3** Email drafts never count in dashboards, hotspots, employee pages or duplicate checks. "Link to existing
  case" is its own authorised action (HR picks a visible case). The 48-hour duplicate check runs in `CreateCase`
  once HR has entered the employee and time (existing behaviour).
- **B4** Limits: message ≤ 25 MB total (larger → rejected `too_large`, moved to Discarded without parsing parts),
  ≤ 5 attachments kept, body decode capped at 50,000 chars; attachment names are display metadata only — content is
  written through `AttachmentService`, never `Attachment::save()` with a vendor path.
- **B5** IMAP connect/read timeout 30 s; health ping: success only when the run had no connection error and no
  per-message failure; failure body states counts only.
- **B6** Mailbox retention: the fetch deletes messages from `Processed` older than 30 days and from `Discarded` older
  than 7 days (config `INTAKE_PROCESSED_DAYS`, `INTAKE_DISCARDED_DAYS`); runbook says the mailbox credentials are
  operator-only. Exceptions, logs and ping bodies never contain addresses, subjects, headers, attachment names or
  credentials.
- **B7** Tests list in T005 expanded accordingly.
- **Nits**: retries are not rejections (no `duplicate` reason — the UID table covers them); `received_at` = the IMAP
  internal date (server receipt time), not the sender's `Date` header.

## Delegation
Codex plan review → run X (all tasks) → my gates + a real IMAP end-to-end against a local mail-server container
(GreenMail) with a forwarded message → browser walkthrough of inbox → case → PR.
