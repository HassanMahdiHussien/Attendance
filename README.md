# Displanery

Multi-tenant SaaS for Iraqi companies to run employee disciplinary procedures under Iraqi labour
law. It is the first module of a wider employee-lifecycle platform.

- Build reference: [CLAUDE.md](CLAUDE.md) · deliberate decisions: [WEIRDNESS.md](WEIRDNESS.md)
- Architecture decisions: [docs/adr](docs/adr/README.md) · schema: [docs/ERD.md](docs/ERD.md)
- Feature specs (GitHub spec-kit): [specs/](specs/) · principles: [.specify/memory/constitution.md](.specify/memory/constitution.md)

## Stack

Laravel 13 (PHP 8.4) · MySQL 8.4 · Livewire 4 · Filament 5 · Tailwind v4 · Pest 5 · Laravel Sail.
Everything runs in Docker; the host needs only Docker Desktop and Git.

## First run (fresh clone)

```bash
cp .env.example .env          # then set SEED_DEFAULT_PASSWORD
docker run --rm -v "$(pwd):/var/www/html" -w /var/www/html laravelsail/php84-composer:latest composer install --ignore-platform-reqs
docker compose up -d          # app, mysql, queue worker, scheduler
docker compose exec -T laravel.test php artisan key:generate
docker compose exec -T laravel.test php artisan migrate --seed
docker compose exec -T laravel.test npm install
docker compose exec -T laravel.test npm run build
```

On PowerShell, replace `$(pwd)` with `${PWD}`. The app is served at http://localhost.
The seed creates the anonymised pilot company and two local accounts, X
(Platform Admin) and the pilot company admin. Both use `SEED_DEFAULT_PASSWORD`.

## Gates (run before every commit)

```bash
docker compose exec -T laravel.test php artisan test
docker compose exec -T laravel.test ./vendor/bin/pint --test
```

Tests run against the MySQL `testing` database. SQLite is never used, because the immutability
rules live in MySQL triggers.

## Server note

MySQL must run with `log_bin_trust_function_creators=1` (set in `compose.yaml` locally), or the
migrations cannot create the immutability triggers.
