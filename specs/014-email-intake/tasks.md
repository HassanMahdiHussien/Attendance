# Tasks: Email Intake (Forwarded Reports)

**Tests**: REQUIRED (Pest + Livewire). Gates (as sail): `docker compose exec -T -u sail laravel.test php artisan test` ·
`… ./vendor/bin/pint --test`. Never run two test processes at once.

plan.md review resolutions (when added) override earlier sections.

## Run X
- [ ] T001 Migration + models + enum (R1)
- [ ] T002 Mailbox interface, IMAP implementation, fetch command + schedule + ping (R2, R7)
- [ ] T003 Sender verifier + content sanitizer + processor (R3, R4)
- [ ] T004 Visibility, inbox/my-reports/create-case UI, EmailDraftService convert/link/dismiss (R5, R6)
- [ ] T005 Tests (R8)
- [ ] T006 Docs: runbook section, WEIRDNESS (separate email_drafts table; only the trusted Authentication-Results
  header counts; discarded mail leaves only a domain + reason)
