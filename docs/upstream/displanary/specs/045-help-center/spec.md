# Feature Specification: Help Center (user guide)

**Feature Branch**: `045-help-center`

**Created**: 2026-10-15

**Status**: Draft

**Input**: Product owner (2026-10-15): "documentation on how to use this platform so others can use it … do it as web … we will surely edit or add, so we must be able to edit it easily." Format chosen: **in-app Help Center** (not an external site or shared document).

## Clarifications

### Session 2026-10-15

- Q: Where does the guide live? → A: Inside the platform at `/help`, in the same workspace layout, for every signed-in user (including the Filament admin and platform panels, via a "Help" user-menu link). No extra hosting.
- Q: How is it edited? → A: One Markdown file per article per language: `resources/help/{locale}/{section}/{slug}.md`, with a short front-matter header (title, summary, audience, order). Editing an article = editing a text file; adding one = adding a file. No database, no admin editor, no new package (Laravel's bundled CommonMark).
- Q: Languages? → A: Arabic and English, same slugs in both. An article missing in one language is a test failure, not a silent fallback.
- Q: Who sees what? → A: Each article has an `audience` (one or more roles, or `all`). The index shows the reader's own guides first; "All guides" shows the rest. Articles are not secret: any signed-in user may open any article by its URL.
- Q: Screenshots? → A: Generated from the seeded demo data by a dev-only command (`help:screenshots`) listed in one manifest, in both languages, so a design change is fixed by re-running one command. Never run against real company data; the command refuses outside the local environment.
- Q: Accuracy? → A: Every step must match what the app actually does today (exact on-screen labels in each language, real routes, real rules). Wrong legal steps are worse than none. Content is drafted from the code and specs, then reviewed line by line against the code.
- Q: Scope of this delivery? → A: Stage 1 = the Help Center itself + getting started, reporter, HR case journey, employees/dashboard, rules, FAQ and glossary. Stage 2 = committee, company lawyer, external reviewer, HR partner, company admin, platform admin guides. Stage 3 = contextual "?" links on each screen.

## User Scenarios & Testing *(mandatory)*

1. **Given** a reporter signs in, **When** they open Help from the account menu, **Then** they see "Getting started", "Reporting" and "Rules" guides first, in their language.
2. **Given** an HR officer opens "Issue the defense letter", **Then** the article explains each step with the exact button labels they see, with screenshots in their language.
3. **Given** a user types "witness" (or «شاهد») in the help search, **Then** matching articles are listed.
4. **Given** a developer edits `resources/help/ar/hr/defense-letter.md` and reloads, **Then** the change appears (no build step).
5. **Given** an English article has no Arabic twin, an image reference points to a missing file, or an internal help link points to a missing article, **Then** the test suite fails naming the file.
6. **Given** Markdown containing raw HTML or a `javascript:` link, **Then** it is escaped/removed.
7. **Given** a guest, **Then** `/help` redirects to sign-in.

## Requirements *(mandatory)*

- **FR-001**: `HelpLibrary` service reads `resources/help/{locale}/**/*.md`, parses front matter (`title`, `summary`, `audience`, `order`; section from the folder), renders Markdown with `html_input=escape`, `allow_unsafe_links=false`, rewrites image paths `shots/<name>` to the auth-only `/help/shots/{locale}/<name>` route, and caches per locale (cache cleared when files change in local).
- **FR-002**: Routes `GET /help` (index + search) and `GET /help/{section}/{slug}` (article with previous/next within its section and a sidebar of the section's articles), auth-only, workspace layout, Departures design tokens (DESIGN.md), RTL in Arabic, readable on a phone.
- **FR-003**: Index groups by section (fixed order: getting-started, reporting, hr, committee, legal, partner, admin, platform, rules, faq); "For you" = articles whose audience includes the user's primary role or `all`; "All guides" toggle.
- **FR-004**: Search over title, summary and text in the current language (case- and diacritics-insensitive for Arabic via the existing Arabic normaliser).
- **FR-005**: "Help" link in the workspace account menu (desktop + mobile "More") and as a Filament user-menu item in the admin and platform panels.
- **FR-006**: `php artisan help:screenshots [--only=name]` — local-only; manifest `resources/help/screenshots.php` (name → path, user role, optional CSS selector to crop); captures 1280-wide viewport images as WebP for `en` and `ar` into `resources/help/shots/{locale}/` (served only to signed-in users); uses the seeded demo users and data only.
- **FR-007**: Content (Stage 1) in both languages — see plan for the article list. Exact UI labels from `lang/*.json`, Western digits, legal rules exactly as CLAUDE.md §3 (calendar days, anchors, paper delivery, presence-check wording, AI never supplies facts/decisions).
- **FR-008**: `resources/help/README.md` — how to edit/add an article, front matter, images, regenerating screenshots (plain language, for a non-developer).
- **FR-009**: Tests: parity (same slugs en/ar), front matter valid, audiences are real roles, every image and internal help link resolves, auth required, escaping of HTML/unsafe links, index/article render for every role, search finds an Arabic term; translations for UI strings; behaviour inventory for new controls.

## Success Criteria

- A new HR officer can run a case from report to archive using only the Help Center.
- Changing a sentence is a one-file edit; refreshing every screenshot is one command.
