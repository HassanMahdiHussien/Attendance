# Tasks: Automatic Decision-Letter Draft and Related-Case Links

**Tests**: REQUIRED. Gates (as sail): `docker compose exec -T -u sail laravel.test php artisan test` ·
`… ./vendor/bin/pint --test`. Never run two test processes at once.

plan.md R8 (review resolutions + product owner) overrides earlier sections.

## Run L
- [x] T001 AutoDecisionDraft hooked into recordPresence and overrideEmpty (R1, R2) with tests
- [x] T002 Related links data + CaseLinks service (R3, R4) with tests
- [x] T003 Case page Related cases card + banner line + link/in-error actions (R5)
- [x] T004 Related cases in the AI payload for touchpoints 1 and 3 (R6) with tests
- [x] T005 Translations; WEIRDNESS (links are permanent, "in error" annotation; auto-draft never blocks presence)
