# Implementation Plan: Browser Tests for the Core Flows

**Branch**: `027-browser-tests` | **Spec**: [spec.md](spec.md)

## Already in place (orchestrator)
- `pestphp/pest-plugin-browser` ^5.1 (composer dev), `playwright` (npm dev), Chromium installed in the Sail container
  under `node_modules/playwright-core/.local-browsers` (Sail sets `PLAYWRIGHT_BROWSERS_PATH=0`).
- `tests/Pest.php` applies the shared TestCase/RefreshDatabase/fakes to `Browser`; `phpunit.xml` has a `Browser` suite.
- `tests/Browser/SmokeTest.php` passes (company admin signs in, opens the main pages, no JS errors).

## Design
- One file per flow under `tests/Browser/` (SignInTest, LanguageSwitchTest, ReporterToCaseTest,
  DefenseLetterIssueTest, CommitteeApprovalTest, FileViewerTest, MobileNavigationTest).
- Arrange state with factories and the existing helpers in `tests/Pest.php` (`completeCompanySetup`, pool/mandate
  helpers, case-at-status helpers used by Feature tests); act and assert through the browser (`visit`, `fill`,
  `select`, `press`, `click`, `assertSee`, `assertPathIs`, `assertNoJavaScriptErrors`, `on()->mobile()` / viewport,
  `withLocale` or user `locale`).
- Prefer stable selectors: visible labels and existing accessible names; add `data-test` attributes only where a label
  is ambiguous (keep them minimal; behaviour inventory must stay green).
- Keep each test focused; avoid sleeping — use Pest browser's waiting assertions.
- `docs/testing.md`: how to run (`php artisan test tests/Browser`), browser install steps, debugging (`--debug`,
  screenshots on failure).

## Tests
- All browser tests green in the container; full suite + Pint green.
