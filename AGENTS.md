# AGENTS.md — Displanery

Read `CLAUDE.md` (authoritative build reference), `WEIRDNESS.md` (deliberate decisions — never
"fix" them) and `.specify/memory/constitution.md` before changing anything. Feature specs live in
`specs/<feature>/` (spec, plan, research, data-model, contracts, tasks).

## Environment

- Development runs natively in WSL Ubuntu 26.04 (PHP 8.5, MySQL 8.4, Node 24) from `~/attendance`.
  There is no Docker. Run PHP / artisan / composer / npm commands directly in that folder as your
  own user, never with `sudo` (root-owned storage folders break the web server).
- You may be working in a Windows copy of the repo without PHP. Then you cannot run the gates:
  say so in your report and the orchestrator runs them in WSL.
- Do not install anything, and do not add packages (`composer require`, `npm install <pkg>`) —
  package changes need the product owner's approval.

## Gates (must pass before you finish)

```
php artisan test --compact
./vendor/bin/pint --test
```
Fix formatting with `./vendor/bin/pint`.

## Rules

- Tests are Pest, against MySQL (database `testing_attendance`), never SQLite.
- Business logic in service classes; models/Livewire stay thin.
- Never weaken tenant scoping, CSRF, auth, append-only logs or document immutability.
- Comments explain why, not what. No ticket/task IDs or "phase"/"MVP"/"for now" wording in code.
- Do not run `git add`, `git commit` or any git write — the orchestrator commits after review.
