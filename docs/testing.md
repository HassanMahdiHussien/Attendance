# Testing

Run the application and its MySQL test database through Sail:

```sh
docker compose up -d
docker compose exec -T -u sail laravel.test php artisan test
docker compose exec -T -u sail laravel.test php artisan test tests/Browser
docker compose exec -T -u sail laravel.test ./vendor/bin/pint --test
```

The browser suite uses Pest Browser and Playwright's Chromium. After building or recreating the Sail container, install the browser binary **as root inside the container** so Playwright can write beneath `node_modules`:

```sh
docker compose exec -T -u root laravel.test npx playwright install chromium
```

Sail sets `PLAYWRIGHT_BROWSERS_PATH=0`, so Playwright finds the browser under `node_modules/playwright-core/.local-browsers`. Run the PHP tests as `sail`, as shown above; running PHP as root can create unreadable storage directories. The image already contains Playwright's system dependencies.

The browser lives in the project's `node_modules`, so it survives container restarts and recreation; reinstall it
only after deleting `node_modules`. The plugin writes temporary files inside its own vendor folder, which must be
writable by `sail`:

```sh
docker compose exec -T -u root laravel.test chmod -R a+rwX vendor/pestphp/pest-plugin-browser node_modules/playwright-core/.local-browsers
```

To inspect a failing browser test, run one file with `--debug` (`--headed` needs a display, which the container has
not):

```sh
docker compose exec -T -u sail laravel.test php artisan test tests/Browser/ReporterToCaseTest.php --debug
```

Pest Browser saves a screenshot of the failing page to `tests/Browser/Screenshots/` (git-ignored). Use `--trace` to capture Playwright traces when interaction timing or navigation is unclear. The tests arrange prior workflow state with factories and services, then exercise the target screen in Chromium. They use waiting assertions rather than fixed sleeps.

## TesterArmy e2e (agent-driven, `tests/e2e/*.e2e.ts`)

Plain-language end-to-end tests run by [TesterArmy e2e](https://tester.army/e2e) next to the Pest browser tests.
They sign in through the real login screen with the local demo accounts and let an AI agent drive and judge
the screens, which catches layout and wording problems a scripted test would not look for.

- **Model:** the developer's ChatGPT subscription, never the Anthropic budget. Sign in once inside the
  container: `docker compose exec -u sail laravel.test npx e2e login openai --device`.
- **Run:** `docker compose exec -u sail laravel.test npm run test:e2e` (telemetry off; the seed password is read
  from `.env` at run time and never stored). Traces of failed tests land in `.e2e/results/<test>/trace.md`.
- **Data:** needs the seeded demo company plus a reporter assigned to a branch
  (`reporter@example.test`); the tests submit one report per run and change nothing else.
- Give the agent exact values (dates, codes) through `screen.getByLabel(...).fill()`; it does not know today's date.
