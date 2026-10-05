# AGENTS.md — Displanery

Read `CLAUDE.md` (authoritative build reference), `WEIRDNESS.md` (deliberate decisions — never
"fix" them) and `.specify/memory/constitution.md` before changing anything. Feature specs live in
`specs/<feature>/` (spec, plan, research, data-model, contracts, tasks).

## Environment

- No PHP, Composer or MySQL on the host. Everything runs in Docker (Laravel Sail compose file).
- The stack must be running: `docker compose up -d`.
- Run every PHP / artisan / composer / npm command inside the app container **as the `sail` user**:
  `docker compose exec -T -u sail laravel.test <command>`. Running as root (the exec default) creates
  private storage folders (mode 0700) that the web server, which runs as `sail`, cannot read.
- Do **not** install PHP, Composer, Laravel Boost or anything else on the host, and do not add
  packages (`composer require`, `npm install <pkg>`) — package changes need the product owner's
  approval.

## Gates (must pass before you finish)

```
docker compose exec -T -u sail laravel.test php artisan test
docker compose exec -T -u sail laravel.test ./vendor/bin/pint --test
```
Fix formatting with `docker compose exec -T -u sail laravel.test ./vendor/bin/pint`.

## Rules

- Tests are Pest, against MySQL (database `testing`), never SQLite.
- Business logic in service classes; models/Livewire stay thin.
- Never weaken tenant scoping, CSRF, auth, append-only logs or document immutability.
- Comments explain why, not what. No ticket/task IDs or "phase"/"MVP"/"for now" wording in code.
- Do not run `git add`, `git commit` or any git write — the orchestrator commits after review.
