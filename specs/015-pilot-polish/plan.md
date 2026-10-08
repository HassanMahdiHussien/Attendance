# Implementation Plan: Pilot Polish and Witnessed-Refusal Evidence

**Branch**: `015-pilot-polish` | **Date**: 2026-10-08 | **Spec**: [spec.md](./spec.md)

## Summary
Translate user-facing blocking messages to Arabic (inline, matching the existing Arabic messages already in the
code), add a guard test, replace the placeholder home page, and require a witness-signed scan for refused hand-offs.

## Technical Context
Laravel 13 · Livewire 4 · existing `HandOffService`, `ShowCase` hand-off form, `AttachmentService`
(`AttachmentKind::DeliveryProof` already allowed for cases), `signatures` table · no new packages.

## Research (decisions)
- **R1 Messages**: replace English text in every `throw new …Blocked('…')` and every exception whose message a
  Livewire component copies to the screen (`->getMessage()` in `app/Livewire/**`), plus model validation messages
  shown to users (e.g. `Company` languages). Inline Arabic strings, as already done in parts of the code (no `lang/`
  files introduced — one language UI). Messages that only reach logs/CLI stay English. Tests asserting the old
  English text are updated to the Arabic text.
- **R2 Guard test**: a Pest test that scans `app/` for `throw new [A-Za-z]+Blocked(` and for exception messages used
  by Livewire notices, and fails when the first string literal contains a run of 3+ Latin letters (allowlist for
  codes such as `PDF`, `PNG`, `JPG`, `AI` inside an otherwise Arabic message).
- **R3 Entry page**: `/` → guest: product name, one-line description, "تسجيل الدخول" button to `/login`, Section 18
  style (Tajawal, indigo primary, rounded-3xl card); authenticated: redirect to `/home`.
- **R4 Witness scan**: migration adds `signatures.witness_scan_attachment_id` (nullable, composite FK to
  `attachments` on company). `HandOffService::record` accepts an uploaded file for refusal statuses; requires it for
  `RefusedToSign` and `DeliveryRefused` (Arabic message); stores it via `AttachmentService` as
  `AttachmentKind::DeliveryProof` on the case inside the same transaction (file cleanup on failure, as in feature
  014); links it; case event metadata gains `witness_scan_attachment_id`. `ShowCase` hand-off form gets a file input
  shown for refusal statuses, validation (PDF/JPEG/PNG, existing size limit); the case page lists the scan link beside
  the witness names (download through the existing authorised attachment route). Signed status unchanged. Decision
  letter receipt (the third hand-off) uses the same service path — verify and cover.
- **R5 WEIRDNESS**: two lines (duplicate check at HR intake — reporters must not learn of cases; committee invite
  links deferred, accounts only).

## Constitution Check
| Principle | Status | How |
|---|---|---|
| I. Tenancy | PASS | scan stored on the case's company; composite FK; authorised download |
| II. AI boundary | PASS | no AI |
| III. Immutable/audit | PASS | scan is append-only attachment; case event logged |
| IV. Tests | PASS | guard test, refusal tests at three hand-offs, entry page |
| V. Thin UI | PASS | service records; component validates |
| VI. Decided | PASS | product owner decisions above |

## Structure
```text
app/Services/** (messages) · app/Livewire/** (messages) · app/Models/Company.php
routes/web.php · resources/views/welcome.blade.php
database/migrations/2026_10_15_000000_add_witness_scan_to_signatures.php
app/Services/Cases/HandOffService.php · app/Livewire/Cases/ShowCase.php + view · app/Models/Signature.php
tests/Feature/Polish/{ArabicMessagesTest,EntryPageTest}.php · tests/Feature/Workflow/HandOffTest.php (extended)
WEIRDNESS.md
```

## Delegation
Run Y (all tasks; small, so no separate plan review — the audit was the review) → my gates, a browser pass through
the refusal flow and a grep/visual pass for English text → PR.
