# Quickstart & Validation: Feature 002

Prerequisite: stack running (`docker compose up -d`), `migrate:fresh --seed` done (see README).

| # | Scenario | Action | Expected |
|---|---|---|---|
| V1 | Gates | `docker compose exec -T laravel.test php artisan test`; `docker compose exec -T laravel.test ./vendor/bin/pint --test` | all green |
| V2 | Platform sign-in | http://localhost/platform/login as `platform.admin@example.test` | platform dashboard in Arabic RTL, indigo theme |
| V3 | Company switch | platform admin opens http://localhost/admin | company switcher lists the pilot company; employees list shows 12 |
| V4 | Company admin | sign in at /admin as `company.admin@example.test` | own company only; no /platform access (403) |
| V5 | Throttle | 6 wrong passwords for one email | 6th refused with wait message; `auth.failed` rows in audit_logs |
| V6 | Deactivate | platform admin deactivates pilot company | company admin's open session is logged out on next request |
| V7 | New employee | add employee without number | gets next `EMP-` code |
| V8 | New mandate | register order with later effective date | previous order shows superseded |
| V10 | Timed onboarding (SC-003) | stopwatch from opening "new company" to last pool member saved: company + first admin, 1 branch, mandate with 9 pool members | under 15 minutes |
| V9 | Clock | `docker compose exec -T laravel.test php artisan tinker --execute='echo now()->timezoneName;'` | `Asia/Baghdad` |
