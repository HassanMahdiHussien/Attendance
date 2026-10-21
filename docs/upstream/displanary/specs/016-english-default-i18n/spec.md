# Feature Specification: English Default Interface with Arabic Switch

**Feature Branch**: `016-english-default-i18n`

**Created**: 2026-10-08

**Status**: Draft

**Input**: Product owner, 2026-10-08: "default language English". Clarified: the interface opens in English
(left-to-right); every user can switch to Arabic (right-to-left) and the choice is remembered; issued legal documents
are unchanged (bilingual Arabic + English, Arabic governs — CLAUDE.md §3).

## Clarifications

### Session 2026-10-08

- Q: Which setup? → A: English default + per-user Arabic switch (not English-only, not per-company).
- Q: Documents? → A: Unchanged. Defense letter, recommendations and decision letter keep their bilingual layout and
  Arabic-governs rule. Only screens, screen messages, e-mails and statistical exports follow the user's language.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - English by default (Priority: P1)

A new user opens the site: the entry page, login, workspace, admin panels and every message are in English,
left-to-right, with dates in English and Western digits.

**Acceptance Scenarios**:
1. **Given** a guest or a user with no preference, **Then** every screen renders `lang="en" dir="ltr"` with English
   text, and no Arabic UI text appears (Arabic *data* such as employee names and legal texts stays as entered).
2. **Given** a blocked action, **Then** the reason is shown in English.

### User Story 2 - Switch to Arabic and keep it (Priority: P1)

A user picks "العربية" from the language switch (header, and on the login page): the whole interface turns Arabic,
right-to-left, with the existing Arabic wording, Arabic-Indic digits and Iraqi month names where they are used today.
The choice is saved on the user and applies on every device; the login page remembers a guest's choice for the
session.

**Acceptance Scenarios**:
1. **Given** a user who chose Arabic, **Then** after logout/login the interface is Arabic.
2. **Given** an Arabic user, **Then** layouts mirror correctly (navigation, icons, spacing, tables) and nothing
   overlaps or misaligns compared with today's Arabic UI.
3. **Given** e-mails and notifications to a user, **Then** they use that user's language.

### User Story 3 - No text left behind (Priority: P1)

**Acceptance Scenarios**:
1. **Given** the automated check, **Then** every translatable string used in the code exists in both the English and
   Arabic translation files, and no hard-coded Arabic UI text remains outside the allowed places (legal document
   templates and their facts, AI prompts, legal-library parsing, seed data).
2. **Given** PR #15's Arabic messages, **Then** they become the Arabic translations of English source messages; the
   guard test changes from "Arabic only" to "translated in both languages".

## Requirements *(mandatory)*

- **FR-001**: Default interface locale `en`; supported `en`, `ar`.
- **FR-002**: Per-user saved preference (users table) + a switch in the workspace header, the Filament panels and the
  login page; guests keep a session choice.
- **FR-003**: `lang`/`dir` follow the locale in every layout and in Filament (Filament's built-in RTL for `ar`).
- **FR-004**: All interface text, enum labels, validation and blocking messages, notifications/e-mails and statistical
  report exports (PDF/Excel from feature 011) are translated; layout classes are direction-neutral.
- **FR-005**: Issued legal documents, their templates and frozen content are untouched.
- **FR-006**: Digits and dates: Western digits and English month names in `en`; current Arabic formatting in `ar`.

## Success Criteria *(mandatory)*

- **SC-001**: A walkthrough of every role's screens shows no untranslated text in either language.
- **SC-002**: The full test suite passes; the translation-coverage test passes.

## Assumptions

- Translations are written by the developer/AI and reviewed by the product owner; Arabic wording keeps today's
  approved phrasing.
- The data itself (names, violation types typed by HR, legal texts) is not translated.
