# Implementation Plan: English Default Interface with Arabic Switch

**Branch**: `016-english-default-i18n` | **Date**: 2026-10-08 | **Spec**: [spec.md](./spec.md)

## Summary
Introduce Laravel localisation with English source strings (`__('Open case')`) and an Arabic JSON translation file
holding today's approved Arabic wording, a per-user locale, a `SetLocale` middleware for web and Filament, direction-
neutral layouts, and a coverage test. No change to issued documents.

## Technical Context
Laravel 13 JSON translations (`lang/en.json`, `lang/ar.json`) · Filament 5 (ships `ar`/`en`; RTL automatic for `ar`)
· Livewire 4 · Tailwind v4 logical properties (`ms-*`, `me-*`, `ps-*`, `pe-*`, `start-*`, `end-*`, `text-start`) ·
`HasLocalePreference` for notifications · no new packages.

## Research (decisions)
- **R1 Storage of strings**: JSON translations keyed by the English text — idiomatic Laravel, English needs no file
  entries beyond identity, Arabic file maps English → current Arabic. Placeholders via `:name`. Enum labels through a
  `label()` method returning `__()`.
- **R2 Locale resolution** (`SetLocale` middleware, web + both Filament panels + Livewire update requests): user's
  saved `users.locale` → session `locale` (guests) → `config('app.locale')` = `en`. `.env.example` `APP_LOCALE=en`;
  the dev `.env` change is a manual step for the developer (reported).
- **R3 Switch**: POST `/locale` (`en|ar`, CSRF) → saves on the user if authenticated, else in the session; redirect
  back. Rendered in the workspace header, guest/login layout, and Filament (`userMenuItems` + render hook on the login
  page).
- **R4 Direction**: layouts `<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">`;
  replace physical Tailwind classes (`ml/mr/pl/pr/left/right/text-left/text-right/rounded-l/r`, `space-x-reverse`,
  arrow icons) with logical ones; check every view. Arabic document previews keep `dir="rtl"` per section (they are
  document content).
- **R5 Formatting**: `App\Support\Format\ArabicDigits::convert` and `IraqiDate` calls in the UI go through a helper
  `App\Support\Format\Ui::number()/date()` that formats per locale (en: Western digits, `j M Y`; ar: today's
  behaviour). Document rendering keeps calling the document formatters directly.
- **R6 Messages**: every user-facing exception/validation message becomes `__('English sentence.')` with the PR #15
  Arabic text as its `ar.json` value; `ArabicMessagesTest` is replaced by `TranslationCoverageTest`: (a) every string
  literal passed to `__()`/`@lang`/`trans()` in `app/`, `resources/views/` (excluding `resources/views/documents/`)
  exists in `lang/ar.json`; (b) no Arabic letters in `app/` and `resources/views/` outside an allowlist
  (`resources/views/documents/**`, `app/Documents/**`, `app/Ai/**` prompts, `app/LegalLibrary/**` parsing,
  `database/seeders/**`, Arabic constant data such as Iraqi month names) — so new Arabic UI text must go through
  translations; (c) `lang/ar.json` has no unused keys (warning list, not failure).
- **R7 Outbound**: `User implements HasLocalePreference` (returns `locale` or default) so mail/notifications render in
  the recipient's language; deadline alerts and ops mails included. Statistical exports (feature 011 PDF/Excel) use
  the requester's locale captured at request time (stored on `report_exports.locale`, migration) because they render
  in a queued job.
- **R8 Tests**: existing tests asserting Arabic UI text either set the acting user's locale to `ar` (preferred when
  the test is about Arabic rendering) or assert the English text. Add tests: default English + `dir=ltr`; switch
  persists across logout/login; guest session switch; Filament panel follows locale; mail locale; export locale;
  coverage test.
- **R9 Docs**: CLAUDE.md §18 note is the product owner's to edit — report it; WEIRDNESS line: UI English default,
  documents unchanged.

## R10 Plan review resolutions (Codex review, 2026-10-08) — override R1–R9 where they differ

### Blockers
- **A1 Staged coverage gate**: `TranslationCoverageTest` takes a path list. Z1 checks only the surfaces Z1 converts
  (`resources/views/layouts`, `resources/views/livewire`, `resources/views/welcome.blade.php`, `app/Livewire`,
  `app/Services`, `app/Exceptions`, `app/Enums`, `app/Models`, `app/Http`); Z2 extends it to everything (`app/Filament`,
  `resources/views/filament`, `app/Notifications`, `app/Mail`, `app/Statistics`, `resources/views/reports`, `app/Jobs`,
  remaining). The full suite is green after each run.
- **A2 No control flow on message text**: before translating, every place that branches on `getMessage()` (e.g.
  `app/Livewire/Cases/RecordSession.php:87`) switches to an exception type or a stable `code` property on the
  `*Blocked` exceptions. Screens keep displaying the (now translated) message. Exceptions thrown inside queued jobs
  and stored as technical errors (e.g. `documents.pdf_error`) stay English — they are operator text, not UI.

### Should-fix
- **B1 Stored text is never translated at write time.** `case_events` types/payloads, stored notes and frozen content
  stay exactly as written. Display labels (`App\Support\Format\CaseTimeline`) translate the event *type* at render
  time. New app-authored notes (e.g. in `DraftDocumentWithAi`) are stored as a translation key + parameters and
  rendered with `__()` at display; existing stored notes display as stored. AI-generated prose is data, untouched.
- **B2 Exports**: `GenerateReportExport` sets `App::setLocale($export->locale)` around building and rendering both
  PDF and XLSX (`try/finally` restore); `reports/pdf.blade.php` `lang`/`dir`/alignment and `ExcelReport` sheet RTL
  follow that locale; the live preview uses the request locale. Test: requester changes preference after queuing →
  both files still use the captured locale.
- **B3 Notifications**: remove the forced Arabic locale in `CaseDeadlineAlert`; `User` implements
  `HasLocalePreference`; database notifications store a key + parameters (not prose) and translate at display; tests
  for mail and database channels in both languages. Operational mail (`OperationsMessage`, `ops:test-mail`, backup
  and drill failure mails) is operator mail: English always (WEIRDNESS line).
- **B4/B5 Catalogues**: two layers — (1) short UI chrome: JSON literal keys (`__('Cases')`), English key = English
  text, `lang/ar.json` holds Arabic; (2) workflow/blocking/validation/notification messages: structured PHP files
  `lang/{en,ar}/messages.php` with domain keys (`messages.handoff.witness_scan_required`) and named placeholders whose
  values stay data. The coverage test checks: every literal JSON key used exists in `ar.json`; every structured key
  used exists in **both** `en` and `ar` files; no Arabic letters in UI code outside a purpose-specific allowlist
  (document templates `resources/views/documents/**`, `app/Documents/**`, AI prompt builders, legal-library
  parsing/normalising, seeders, Iraqi month-name constants, `lang/ar*`) — whole directories that also render UI are
  not exempt.
- **B6 Livewire lifecycle tests**: an Arabic user's Livewire action via `/livewire/update` and a Filament action both
  render Arabic, including a translated service exception; locale precedence after login and on later requests.
- **B7 Direction/fonts**: walkthrough and class sweep include Filament custom views and the statistical PDF;
  content-language blocks (bilingual review panels, document previews) keep their explicit `dir`; §18 font roles kept
  (Tajawal chrome in both languages, Amiri for document previews, JetBrains Mono for identifiers).
- **B8 Test migration**: ~67 Arabic assertion lines in 24 files — UI assertions assert English by default or set the
  acting user's locale to `ar` when the test is about Arabic rendering; document/AI content assertions unchanged;
  add regression tests that switching UI language leaves rendered document content and AI prompt language rules
  unchanged.
- **Nits**: `Ui::date()` formats in `Asia/Baghdad` (presentation only); login switch verified on the custom login and
  both Filament logins; CLAUDE.md §18 edit is the product owner's — the PR description proposes the wording.

## Constitution Check
| Principle | Status | How |
|---|---|---|
| I. Tenancy | PASS | locale is per user; no scope changes |
| II. AI boundary | PASS | prompts and document content untouched |
| III. Immutable | PASS | issued documents untouched |
| IV. Tests | PASS | R8 |
| V. Thin UI | PASS | middleware + small controller |
| VI. Decided | PASS | product owner chose English default + Arabic switch, documents unchanged |

## Structure
```text
lang/en.json · lang/ar.json · app/Http/Middleware/SetLocale.php · app/Http/Controllers/LocaleController.php
database/migrations/2026_10_16_000000_add_locale_to_users_and_exports.php · app/Support/Format/Ui.php
resources/views/** (except documents/) · app/Livewire/** · app/Filament/** · app/Services/** messages · app/Enums labels
app/Providers/Filament/*PanelProvider.php · bootstrap/app.php (middleware) · tests/Feature/I18n/*.php
```

## Delegation
Codex plan review → run Z1 (infrastructure + workspace screens + messages) → my gates → run Z2 (Filament panels,
mails, exports, remaining) → my gates + browser walkthrough in both languages for every role → PR.
