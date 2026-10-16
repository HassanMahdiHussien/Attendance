# Testing

Development runs natively in WSL (Ubuntu 26.04: PHP 8.5, MySQL 8.4, Node 24), set up once with
`sudo bash deploy/dev-setup.sh [backup.sql]`. From `~/displanery`:

```sh
php artisan test --parallel --processes=8
php artisan test tests/Browser
./vendor/bin/pint --test
```

Tests use their own `testing` database (`testing_test_N` in parallel runs), never the dev database. Run one suite at a
time: two runs at once share `testing` and fail with `QueryException`.

The browser suite uses Pest Browser and Playwright's Chromium. Install the browser once per machine, as your own user
(it goes to `~/.cache/ms-playwright`):

```sh
npx playwright install chromium
```

Pest Browser leaves its Playwright server running after a run. It is harmless, but it keeps a terminal that captured
the output from returning; clear it with `pkill -f "playwright run-server"`.

To inspect a failing browser test, run one file with `--debug` (`--headed` needs a display):

```sh
php artisan test tests/Browser/ReporterToCaseTest.php --debug
```

Pest Browser saves a screenshot of the failing page to `tests/Browser/Screenshots/` (git-ignored). Use `--trace` to capture Playwright traces when interaction timing or navigation is unclear. The tests arrange prior workflow state with factories and services, then exercise the target screen in Chromium. They use waiting assertions rather than fixed sleeps.

The queue worker and scheduler run as systemd services; restart the worker after changing job code:
`sudo systemctl restart displanery-queue`.

## TesterArmy e2e (agent-driven, `tests/e2e/*.e2e.ts`)

Plain-language end-to-end tests run by [TesterArmy e2e](https://tester.army/e2e) next to the Pest browser tests.
They sign in through the real login screen with the local demo accounts and let an AI agent drive and judge
the screens, which catches layout and wording problems a scripted test would not look for.

- **Model:** the developer's ChatGPT subscription, never the Anthropic budget. Sign in once:
  `npx e2e login openai --device`.
- **Run:** `npm run test:e2e` (telemetry off; the seed password is read from `.env` at run time and never stored).
  Traces of failed tests land in `.e2e/results/<test>/trace.md`.
- **Data:** needs the seeded demo company plus a reporter assigned to a branch
  (`reporter@example.test`); the tests submit one report per run and change nothing else.
- Give the agent exact values (dates, codes) through `screen.getByLabel(...).fill()`; it does not know today's date.
