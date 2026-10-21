# Plan: Automatic design audit

## Pieces
- `app/Console/Commands/DevSessionCookie.php` — `dev:session-cookie {email} {--locale=en}`: local-only, `@example.test` only, prints `{"name","value"}` JSON (logic as `storage/app/e2e/session-cookie.php`, with `app('session')->forgetDrivers()` before building the store — the database handler otherwise UPDATEs a missing row on the second session).
- `scripts/design-audit.mjs` (Node, `playwright` already a devDependency; Chromium installed in ~/.cache/ms-playwright): roles from a list of seeded demo emails (company.admin, hr.staff, reporter, dept? , committee.legal, platform.admin, reviewer, partner — skip a missing user with a note), base URL `http://localhost`. For each role × locale: get cookie via `php artisan dev:session-cookie`, open the landing page, BFS same-origin links (skip list in FR-005; normalise query strings; cap 40 pages/role/locale), run checks, screenshot 1280 (full page) and 375 (viewport) as JPEG q60.
- Header signature: workspace header = `header` of `layouts/workspace` (brand, `nav[aria-label]` links + Settings menu items, search input); Filament top bar = `.fi-topbar` (brand, nav items, dropdown items, tenant menu). Record both as a normalised list so differences are visible in the report.
- Language heuristic: elements `button, label, th, nav a, [role=tab], [role=menuitem], .fi-btn, h1, h2, h3`; ignore text matching emails, digits-only, case numbers (`#?\d{4}`), `[A-Z]{2,4}` codes, and names that appear in the seeded employees/users tables (passed in from the cookie command output or a second artisan list).
- `scripts/design-audit-report.mjs` (or same file) renders `report.html`.
- `package.json` script `audit:design`; `.gitignore` `storage/app/design-audit/`.
- Tests: `tests/Feature/Dev/DevSessionCookieTest.php` (refuses non-local, refuses non-demo email, works for a demo user in local).
