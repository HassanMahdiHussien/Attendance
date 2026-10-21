# Feature Specification: Browser Tests for the Core Flows

**Feature Branch**: `027-browser-tests`

**Created**: 2026-10-13

**Status**: Draft

**Input**: Product owner (2026-10-13): test the project end to end in a real browser. Decision: Pest's own browser
testing (`pestphp/pest-plugin-browser`, Playwright underneath) — same language, runner, factories and isolated test
databases as the existing suite; no AI steps, deterministic. (TesterArmy `e2e` considered; may be used later only for
exploratory checks with demo data.)

## Clarifications

### Session 2026-10-13

- Q: Which flows? → A: The ones where a break hurts most and that unit/Livewire tests cannot see (JavaScript, real
  redirects, Livewire round trips, layouts): sign-in per role, language switching (workspace and admin panel), the
  reporter → HR inbox → case path, the defense-letter confirm/issue screen, the three committee approvals, the in-page
  file viewer, mobile navigation. Every visited page asserts no JavaScript errors.
- Q: How much state through the browser? → A: Use the browser for the steps a person performs on the screens under
  test; arrange earlier state with factories/services (as Feature tests do) so tests stay fast and focused.
- Q: Languages and sizes? → A: Each flow in English; sign-in, the case page and navigation also in Arabic (RTL) and at
  375 px.
- Q: AI? → A: None. AI drafting paths use the manual "write it yourself" path or the FakeClaudeClient already used in
  tests.

## Requirements *(mandatory)*

- **FR-001**: `tests/Browser` suite registered with Pest (same TestCase, RefreshDatabase, fakes) and in `phpunit.xml`.
- **FR-002**: Sign-in: each role signs in at `/login` and lands on its home with no JavaScript errors; a wrong password
  shows the generic error.
- **FR-003**: Language: switching to Arabic and back in the workspace sidebar and in the admin panel user menu returns
  to the same page with `dir="rtl"` / `ltr` (regression for the 405 bug).
- **FR-004**: Reporter flow: a reporter submits a report (with the intake checklist fallback), HR sees it in the inbox,
  completes it and opens the case; the case page loads.
- **FR-005**: Defense letter: HR writes the letter manually, sees the confirmation screen (Amiri preview card), confirms
  and issues it with a reference number; the case moves to awaiting response.
- **FR-006**: Committee approvals: the three pool members (legal, HR, union) each approve the recommendations from
  their own session; after the third approval the document is confirmed.
- **FR-007**: File viewer: on a case with an issued PDF and an image attachment, View opens the overlay, Next moves,
  Escape closes.
- **FR-008**: Mobile (375 px): the bottom navigation and More sheet open and navigate; the case page has no horizontal
  overflow.
- **FR-009**: The browser binaries and server setup are documented (`docs/testing.md` or README section): Sail image has
  Playwright deps; `npx playwright install chromium` as root in the container; `PLAYWRIGHT_BROWSERS_PATH=0` (Sail).
