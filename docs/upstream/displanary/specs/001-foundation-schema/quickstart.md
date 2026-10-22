# Quickstart & Validation: Feature 001

## Prerequisites
- Docker Desktop running (WSL2 backend). No host PHP/Composer/MySQL needed.
- Git checkout of branch `001-foundation-schema`.

## First run (fresh clone, executable order)
```bash
cp .env.example .env            # set SEED_DEFAULT_PASSWORD
docker run --rm -v "$(pwd):/var/www/html" -w /var/www/html laravelsail/php84-composer:latest composer install --ignore-platform-reqs
docker compose up -d            # app, mysql, queue worker, scheduler
docker compose exec -T laravel.test php artisan key:generate
docker compose exec -T laravel.test php artisan migrate --seed
docker compose exec -T laravel.test npm install
docker compose exec -T laravel.test npm run build
```
(PowerShell: replace `$(pwd)` with `${PWD}`.) This exact sequence is validated from a clean clone at
the end of implementation (SC-004).

## Validation scenarios

| # | Scenario | Command / action | Expected |
|---|---|---|---|
| V1 | Full test suite | `docker compose exec -T laravel.test php artisan test` | all green, including Tenancy, Immutability, Sequences, Seed suites |
| V2 | Formatting | `docker compose exec -T laravel.test ./vendor/bin/pint --test` | no changes needed |
| V3 | Arabic shell | open http://localhost | RTL Arabic page, Tajawal font, indigo primary tokens |
| V4 | Queue worker | `php artisan app:heartbeat --dispatch` then `docker compose logs queue` | job processed log line |
| V5 | Scheduler | `docker compose logs scheduler` after ≥1 min | heartbeat command ran |
| V6 | Seed structure | `php artisan tinker` → count pool members by role for mandate 1097 | hr 5, legal 2, labor_union 2 |
| V7 | Locked doc at DB level | raw `UPDATE document_contents …` on an issued document | SQL error `IMMUTABLE:` |
| V8 | Fail-closed scope | `php artisan tinker` → `App\Models\Employee::count()` with no context | `MissingTenantContext` thrown |

Data model: [data-model.md](./data-model.md). Service contract: [contracts/domain-services.md](./contracts/domain-services.md).
