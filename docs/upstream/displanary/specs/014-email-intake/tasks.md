# Tasks: Email Intake (Forwarded Reports)

**Tests**: REQUIRED (Pest + Livewire). Gates (as sail): `docker compose exec -T -u sail laravel.test php artisan test` ·
`… ./vendor/bin/pint --test`. Never run two test processes at once.

plan.md review resolutions (when added) override earlier sections.

## Run X
- [x] T001 Migration + models + enum (R1)
- [x] T002 Mailbox interface, IMAP implementation, fetch command + schedule + ping (R2, R7)
- [x] T003 Sender verifier + content sanitizer + processor (R3, R4)
- [x] T004 Visibility, inbox/my-reports/create-case UI, EmailDraftService convert/link/dismiss (R5, R6)
- [x] T005 Tests (R8 + R9): topmost-header rule (forged same-id header below the real one rejected; no header;
  other authserv-id; dmarc pass; spf-only aligned; dkim-only aligned; misaligned), sender eligibility (unknown,
  inactive user, inactive company, disallowed roles), branch rule (one / several / none), tenant isolation (fetcher +
  inbox + download + conversion URL), UID idempotency (move failure, refetch, missing Message-ID, 3 failures →
  discarded), limits (oversize message, disallowed/oversized attachment, 6th attachment), HTML → text, conversion
  and linking copy attachments + rollback leaves no files, dismiss, MyReports status, drafts excluded from
  dashboards, retention deletion, health ping success/failure without content, logs free of addresses/subjects
- [x] T006 Docs: runbook section, WEIRDNESS (separate email_drafts table; only the trusted Authentication-Results
  header counts; discarded mail leaves only a domain + reason)
