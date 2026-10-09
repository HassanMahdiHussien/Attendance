# Tasks: Global Search, Remembered Filters and the Case "Next Step"

**Tests**: REQUIRED. Gates (as sail): `docker compose exec -T -u sail laravel.test php artisan test` ·
`… ./vendor/bin/pint --test`. Never run two test processes at once.

plan.md review resolutions (when added) override earlier sections.

## Run S
- [ ] T001 SearchText normaliser; migration with search columns, preferences, indexes, backfill; model hooks (R1, R2)
- [ ] T002 GlobalSearch service with role-scoped providers (R3)
- [ ] T003 GlobalSearch Livewire component in the top bar + phone sheet, keyboard/ARIA (R4)
- [ ] T004 Remembered board filters with chips and clear (R5)
- [ ] T005 Case page Next-step card + section anchors (R6)
- [ ] T006 Tests (R7); behaviour inventory updated
