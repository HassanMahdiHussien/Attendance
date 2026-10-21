# Displanery

Multi-tenant SaaS for Iraqi companies to run employee disciplinary procedures under Iraqi labour
law. It is the first module of a wider employee-lifecycle platform.

- Build reference: [CLAUDE.md](CLAUDE.md) · deliberate decisions: [WEIRDNESS.md](WEIRDNESS.md)
- Architecture decisions: [docs/adr](docs/adr/README.md)
- Feature specs (GitHub spec-kit): [specs/](specs/) · principles: [.specify/memory/constitution.md](.specify/memory/constitution.md)

## Stack

Laravel 13 (PHP 8.5) · MySQL 8.4 · Livewire 4 · Filament 5 · Tailwind v4 · Pest 5.
Development runs natively in WSL Ubuntu 26.04 (the same runtime as `deploy/dev-setup.sh` installs); there is no Docker.

## First run (fresh clone in WSL, e.g. `~/attendance`)

```bash
cp .env.example .env          # DB_HOST=127.0.0.1, DB_DATABASE=attendance, APP_URL=http://localhost:8001; set SEED_DEFAULT_PASSWORD
composer install
php artisan key:generate
php artisan migrate --seed
npm ci && npm run build
php artisan serve --port=8001
```

The `attendance` database must exist and be granted to the `.env` user (one-time, with sudo).
The seed creates the anonymised pilot company and two local accounts, `platform.admin@example.test`
(Platform Admin) and `company.admin@example.test` (pilot company admin). Both use `SEED_DEFAULT_PASSWORD`.

## Gates (run before every commit)

```bash
php artisan test --compact
./vendor/bin/pint --test
```

Tests run against the MySQL `testing_attendance` database, so they never share a database with
Displanary's suite on the same machine. SQLite is never used, because the immutability rules live
in MySQL triggers.

## Server note

MySQL must run with `log_bin_trust_function_creators=1` (set by `deploy/dev-setup.sh` locally), or
the migrations cannot create the immutability triggers.
