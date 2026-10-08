# Tasks: "My Work" Home for Each Role

**Tests**: REQUIRED. Gates (as sail): `docker compose exec -T -u sail laravel.test php artisan test` ·
`… ./vendor/bin/pint --test`. Never run two test processes at once.

plan.md review resolutions (when added) override earlier sections.

## Run H
- [x] T001 NextAction service + value object, every CaseStatus mapped, urgency rules (R1)
- [x] T002 MyWork service per role, batched queries (R2)
- [x] T003 Role-aware Home page + view per the mockup (R3)
- [x] T004 Landing → /home, "My work" nav item (R4)
- [x] T005 Tests (R5); behaviour inventory updated for the new home view
