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
