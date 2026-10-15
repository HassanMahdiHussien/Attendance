# Implementation Plan: Help Center (user guide)

**Branch**: `045-help-center` | **Spec**: [spec.md](spec.md)

## Existing code (read first)
- Layout and navigation: `resources/views/layouts/workspace.blade.php` (account menu + mobile "More" sheet), `app/Support/Access/Nav.php`, `app/Enums/UserRole.php`.
- Filament user menus: `app/Providers/Filament/AdminPanelProvider.php`, `PlatformPanelProvider.php` (`userMenuItems`).
- Markdown already used safely: `app/Documents/BodyFormatter.php` (`Str::markdown` with `html_input=escape`, `allow_unsafe_links=false`).
- Arabic normalising for search: `app/Support/Text/ArabicText.php`.
- UI kit and design: `resources/views/components/ui/*`, DESIGN.md, `resources/views/livewire/home.blade.php` for a simple page.
- Tests to keep green: `tests/Feature/I18n/TranslationCoverageTest.php`, `tests/Feature/Ui/BehaviourInventoryTest.php`, `tests/Feature/Tenancy/RouteSweepTest.php`.
- Screenshot pattern to reuse: `storage/app/e2e/shots.php` (dev-only, untracked) — session cookie + Browsershot with `config('displanery.pdf.chrome_path')`.
- Every label quoted in an article must be copied from `lang/en.json` / `lang/ar.json` (the Arabic article uses the Arabic label exactly as the screen shows it).

## Design
- **Content layout**: `resources/help/{en,ar}/{section}/{slug}.md`. Front matter between `---` lines, simple `key: value` (audience is a comma list of `UserRole` values or `all`); parsed by hand — no YAML package.
  ```
  ---
  title: Issue the defense letter
  summary: Draft, check, confirm and print the defense request letter.
  audience: company_admin, hr_staff
  order: 30
  ---
  ```
- **Service** `app/Support/Help/HelpLibrary.php` (+ small `HelpArticle` value object): `all(locale)`, `find(locale, section, slug)`, `forRole(locale, role)`, `search(locale, query)`, `sections()` (fixed order + translated section names in `lang/*.json`). Images: `![alt](shots/case-board)` → `/help/shots/{locale}/case-board.webp`. Internal links written as `help:hr/defense-letter` → `/help/hr/defense-letter`. Cache per locale keyed by the max file mtime.
- **Livewire** `app/Livewire/Help/HelpIndex.php` (search `q` in the query string, `all` toggle) and `HelpArticlePage.php` (article, section sidebar, previous/next). Thin components; logic in `HelpLibrary` (CLAUDE.md §19). Article body styled with a scoped `.help-prose` CSS block in `resources/css/app.css` (Tajawal, RTL-safe logical properties, images with a border and rounded corners, tables, callouts via blockquote).
- **Navigation**: "Help" item in the workspace account menu (desktop) and the mobile "More" sheet (icon: a question/info icon added to `ui.icon` if missing); Filament `userMenuItems` "Help" → `/help` in both panels.
- **Screenshots**: `app/Console/Commands/HelpScreenshots.php` (`help:screenshots {--only=}`), refuses unless `app()->environment('local')`; manifest `resources/help/screenshots.php` returns `name => ['path' => ..., 'user' => <seeded demo email>, 'selector' => optional]`; signs a session like `shots.php`, sets the user locale per run and restores it; viewport 1280×800, `setScreenshotType('webp', 80)`, `select()` when a selector is given; writes `public/help/shots/{locale}/{name}.webp`. Images are committed.
- **Docs for editors**: `resources/help/README.md`.

## Stage 1 article list (each in `en` and `ar`)
| Section | Slug | Audience |
|---|---|---|
| getting-started | welcome (what the platform does, roles, the 5 stages) | all |
| getting-started | sign-in-and-language | all |
| getting-started | finding-your-way (My work, menus, phone "More") | all |
| reporting | submit-report (incl. several employees, attachments, branch missing) | reporter, dept_manager, company_admin, hr_staff |
| reporting | track-your-reports (statuses a reporter sees) | reporter, dept_manager |
| hr | reports-inbox (report/email draft → case, duplicates within 48 h) | company_admin, hr_staff |
| hr | open-a-case (New case, several employees = incident, banners: prior cases, repeat responsible person, conflict of interest) | company_admin, hr_staff |
| hr | case-board (board, 5-dot stages, filters, deadline alerts) | company_admin, hr_staff |
| hr | defense-letter (AI or manual draft, side notes, citation check, confirmation screen, reference number, print, issue; clocks start) | company_admin, hr_staff |
| hr | paper-delivery (signature, refused to sign + 2 witnesses, delivery refused) | company_admin, hr_staff |
| hr | employee-response (scan upload, check the transcription against the scan) | company_admin, hr_staff |
| hr | committee-session (attendees from the pool, quorum, minutes, incident session) | company_admin, hr_staff |
| hr | recommendations (Doc A draft, three approvals, rejection, edits reset approvals, print, signed scan, presence check) | company_admin, hr_staff, committee_member |
| hr | legal-consultation (request, the clock keeps running) | company_admin, hr_staff, committee_member |
| hr | decision-letter (Doc B, defense accepted/partly/rejected, termination leave check, signatures GM + union, issue) | company_admin, hr_staff |
| hr | appeal-and-archive (30-day window, log an appeal, archive) | company_admin, hr_staff |
| hr | corrections (issued documents are locked; new version with reason) | company_admin, hr_staff |
| hr | employees-and-history (employee page, prior cases) | company_admin, hr_staff |
| hr | dashboard (counters, hotspots, alerts) | company_admin, hr_staff |
| hr | statistics (reports, exports, per-role scope) | company_admin, hr_staff, hr_partner_staff, platform_admin |
| rules | deadlines (3/15/30 calendar days, anchors, nothing pauses them) | all |
| rules | paper-and-signatures (paper is official; email is an alert; presence check ≠ identity) | all |
| rules | how-ai-helps (AI drafts from your facts only; you confirm; write manually if unavailable) | all |
| faq | questions | all |
| faq | glossary | all |

## Content rules (for the writer)
- Second person, short sentences, numbered steps, one task per article; lead with "What you need" when there is a prerequisite.
- Quote buttons/fields in bold with the exact label from the language file for that article's language; never invent a label. If a step's label is not in the code, the step is wrong.
- Legal statements only as written in CLAUDE.md §3 and the code; no legal advice; no article numbers of laws unless they are shown in the app.
- No real person or company data; screenshots come only from the seeded demo company.
- Arabic is written natively (not a word-for-word translation), Iraqi HR register, Western digits.

## Tests
`tests/Feature/Help/HelpLibraryTest.php`: en/ar slug parity; every front matter has title/summary/audience/order; audiences are `UserRole` values or `all`; every `shots/` image exists for both locales; every `help:` link resolves; HTML and `javascript:` are escaped; `/help` redirects guests; index and an article render for each role and both locales; search finds an English and an Arabic term. Translation coverage + behaviour inventory updated; route sweep unaffected (no model parameters). Pint + full suite. Screenshot command: unit test that it refuses outside `local`.

## Plan review resolutions

These override earlier sections where they differ.

- **Labels source**: there is no `lang/en.json`. English labels are the source strings in views/PHP (`__('…')`), Arabic labels are `lang/ar.json`; structured strings are `lang/{en,ar}/messages.php`. Articles quote the label exactly as the view renders it in that language.
- **Screenshots are not public**: images live in `resources/help/shots/{locale}/{name}.webp` and are served by an auth-only route `GET /help/shots/{locale}/{name}` that accepts only names listed in the manifest and a supported locale (404 otherwise). The screenshot command accepts `--only` values only from the manifest, signs in only as the seeded demo users listed in the manifest (all `@example.test`), refuses outside `local`, and refuses when the demo company is absent.
- **Route sweep**: `section`, `slug`, `locale`, `name` are static content parameters, not records; register them explicitly in `RouteSweepTest` with a one-line justification, and keep the sweep's completeness check.
- **Cache**: fingerprint = hash of every help file's relative path + size + content hash (sorted); no persistent cache in `local`/`testing` (read on each request is cheap for ~50 files). Adding, editing and deleting a file is reflected (tested).
- **Rendering**: rewrite `help:` links and `shots/` images on the CommonMark AST (event listener on `DocumentParsedEvent`, or a small inline-link pre-pass on the Markdown source with strict regex `[a-z0-9-]+(/[a-z0-9-]+)?`), never on rendered HTML. Unknown targets are a test failure. Do not reuse `BodyFormatter` (it strips links/images/headings); use the same safe options.
- **Search**: `#[Url]` property like `Board.php`, validated string max 100; normalise with `App\Support\Search\SearchText::normalize()`.
- **Audience corrections**: `committee-session` → company_admin, hr_staff (committee members get a Stage 2 article on what they see/do). `dashboard-and-statistics` split: `hr/dashboard` (company_admin, hr_staff) and `hr/statistics` (company_admin, hr_staff, hr_partner_staff, platform_admin, wording scoped per role).
- **Article content additions** (from the code):
  - open-a-case: draft → intake questions → duplicate review (incident time ±48 h) → open; blocked by incomplete company setup, missing facts, or no approved effective law.
  - reports-inbox: convert, attach to an existing case, dismiss with reason, map violation type; email drafts arrive here too.
  - employee-response: also the "no response after the 3-day deadline" path.
  - committee-session: ≥3 distinct eligible attendees incl. a union member, chair, per-attendee vote, minutes author, outcome, defense acceptance, majority needed; incident shared session with case-specific minutes/votes.
  - recommendations: per-attendee signature box states after the signed scan; an empty box blocks progress unless a company admin overrides with a reason; presence ≠ identity.
  - decision-letter / appeal-and-archive: recommendation-only cases close after signed recommendations (+ pending consultation), no decision or appeal; penalty path may auto-draft or be blocked (regulation comparison, AI failure → write manually); after issue record GM + union box states and the employee hand-off; an appeal filed needs its outcome before archive.
- **Tests added**: menu links (workspace desktop + mobile, both Filament panels), query-string search, cache refresh on add/edit/delete, image route auth + manifest whitelist, screenshot command refusals.
- **README**: editing on a deployed server means deploying the changed file; screenshots must be regenerated and reviewed after UI changes.
