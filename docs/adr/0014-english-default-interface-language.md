# ADR 0014: English is the default interface language

**Status:** Accepted, 2026-10-21

## Context

The platform was designed Arabic-first. The attendance module's users work mainly in English, and the product owner decided English should be the default for both modules, with Arabic as the second language.

## Decision

- The default interface locale is `en` for the whole platform (`APP_LOCALE=en`); each user can switch to Arabic, saved on `users.locale`.
- English renders LTR, Arabic RTL. Every new string ships in both `en` and `ar`.
- **Legal documents are not affected.** Discipline documents keep their multilingual rule: Arabic and English always included, Arabic text authoritative (CLAUDE.md §3).
- Employee names, report descriptions and other source text are never machine-translated.

## Consequences

New screens are designed and reviewed in English first, then checked in Arabic RTL. The code already defaults to `en` (`APP_LOCALE`, `users.locale`, `SetLocale`); this ADR makes that the intended product decision, so CLAUDE.md and DESIGN.md wording that says "Arabic-first" refers to legal documents only.
