# Tasks: English Default Interface with Arabic Switch

**Tests**: REQUIRED. Gates (as sail): `docker compose exec -T -u sail laravel.test php artisan test` ·
`… ./vendor/bin/pint --test`. Never run two test processes at once.

plan.md review resolutions (when added) override earlier sections.

## Run Z1 — infrastructure and workspace
- [x] T001 Locale column, SetLocale middleware, switch route/controller, layouts lang/dir (R2–R4)
- [x] T002 `Ui` formatter and direction-neutral classes in all workspace views (R4, R5)
- [x] T003 Workspace views + Livewire components + services/exception/validation messages + enum labels through
  `__()`; `lang/ar.json` with today's Arabic wording (R1, R6)
- [x] T004 TranslationCoverageTest replacing ArabicMessagesTest; update affected tests (R6, R8)

## Run Z2 — panels, outbound, remaining
- [ ] T005 Filament admin + platform panels (labels, navigation, notifications, login switch) (R3)
- [ ] T006 Mail/notifications locale, statistical export locale (R7)
- [ ] T007 Remaining strings found by the coverage test; WEIRDNESS line; tests (R8, R9)
