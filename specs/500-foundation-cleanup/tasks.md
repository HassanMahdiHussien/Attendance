# Tasks: Foundation cleanup — remove the discipline module

**Input**: [spec.md](spec.md), [plan.md](plan.md), [research.md](research.md)

Each phase is one implementation run and one commit. After every phase, in WSL `~/attendance`: `php artisan test --compact` and `./vendor/bin/pint --test` pass, and the diff is checked for mangled `\r\n` escapes and Blade files mixing inline `@php(...)` with `@php … @endphp`. Paths and exact edits come from research.md §1–§5.

## Phase 0 — Dev env (done, `139ef5d`)

- [x] T001 Test DB `testing_attendance` in `phpunit.xml`
- [x] T002 AGENTS.md, README.md, CLAUDE.md describe native WSL; log both in `docs/upstream/FOUNDATION-CHANGES.md`

## Phase 1 — Docs move (no code)

- [x] T003 Move `docs/{PRD,PRD-EN,ERD,LAUNCH-CHECKLIST,document-templates,PLATFORM-PRD}.md`, `docs/Admin_Order_*.pdf` to `docs/upstream/displanary/` (`docs/laws/` moves in T023: backup config, restore drill and seeder read it)
- [x] T004 Move discipline ADRs 0002–0004 and 0011 (0005 Browsershot stays: printable statements) to `docs/upstream/displanary/adr/`; update `docs/adr/README.md`
- [x] T005 Move `specs/001-*` … `specs/063-*` to `docs/upstream/displanary/specs/`
- [x] T006 Fix links in kept docs (README, CLAUDE.md, AGENTS.md, ROADMAP, ADR 0013, DESIGN.md, PRODUCT.md, WEIRDNESS.md)

## Phase 2 — Screens, routes and entry points (research §3 step-2 rows, §4 step 2)

- [ ] T007 Delete discipline Livewire components and views
- [ ] T008 Delete discipline Filament pages, resources, widgets, concerns and their views
- [ ] T009 Delete discipline/attachment/export controllers and `resources/views/{documents,reports}` output views
- [ ] T010 `routes/web.php` and `routes/console.php`: remove discipline routes, schedules and imports
- [ ] T011 Home and HR dashboard → "Attendance dashboard — coming in spec 505" placeholder (en + ar)
- [ ] T012 Employee page → identity fields + empty Attendance section; `EmployeeProfile` trimmed
- [ ] T013 `GlobalSearch` (service, component, view) → employees only
- [ ] T014 `Landing`, `Nav`, `AdminNav`, `PlatformNav`, `DashboardNav`, `EmployeeActions`, workspace layout/navigation, welcome page → platform + HR items only
- [ ] T015 `CompanySettings`, `CompanySetup`, `SetupChecklist`, `AdminShortcuts`, `CompaniesOverview` drop discipline items
- [ ] T016 Help: delete discipline articles and screenshots (keep `getting-started/sign-in-and-language.md`, trimmed `hr/employees-and-history.md`, rewritten welcome/finding-your-way), update `HelpLibrary`, `screenshots.php`, `HelpScreenshots`
- [ ] T017 `SearchBenchmark` → HR viewer, employees only
- [ ] T018 Tests: delete screen-only tests and browser/e2e discipline flows; update `BehaviourInventoryTest` + `tests/fixtures/ui-inventory.json`, nav/landing/home/search/help/setup tests

## Phase 3 — Domain code (research §3 step-3 rows, §4 step 3)

- [ ] T019 Delete `app/{Ai,Documents,LegalLibrary,Workflow,Statistics,Notifications}`, discipline service folders and single services
- [ ] T020 Delete discipline models, jobs (keep `HeartbeatJob`), commands, policies, enums, exceptions, support classes, `lang/*/report_assistant.php`
- [ ] T021 Trim kept models: `Employee`, `Company`, `User`, `AiGenerationLog`
- [ ] T022 Trim kept services/policies/providers: `EmployeeImport`, `EmployeePicker`, `EmployeeProfilePolicy`, `UserAccountService`, `AppServiceProvider`, `config/displanery.php`, `Tone`
- [ ] T023 Delete discipline factories, `DocumentDemoSeeder`, `LawsSeeder`, `database/legal-library/`; move `docs/laws/` to `docs/upstream/displanary/laws/` and drop it from `config/backup.php` and `BackupRestoreDrill`
- [ ] T024 Tests: delete domain-only tests, fakes and fixtures; trim mixed audit/tenancy/operations tests

## Phase 4 — Schema, roles and seed data (research §1, §2, §5)

- [ ] T025 Edit/delete migrations exactly per research §1 (up and down)
- [ ] T026 Roles: `UserRole` = `PlatformAdmin`, `HrStaff`; HR gets company-panel duties in its own company (policies, gates, resources, `UserAccountService`, `CompanyOnboardingService`, `LoginResponse`, `WorkspaceTenants`)
- [ ] T027 Models' casts/fillable/defaults, kept factories, `DatabaseSeeder` / `PilotCompanySeeder` / `LocalUsersSeeder` (platform admin + HR only)
- [ ] T028 Tests: `CompositeForeignKeyTest`, `TriggerSmokeTest`, panel/resource access matrices, seed, setup, sign-in, browser/e2e accounts
- [ ] T029 Verify `migrate:fresh` and step-by-step `migrate:rollback` / `migrate` round-trip on the test DB; append-only triggers still fire

## Phase 5 — Docs and governance

- [ ] T030 Constitution 2.0.0 (plan D5), version line and amendment note
- [ ] T031 README, PRODUCT.md, DESIGN.md product wording → attendance (design tokens unchanged)
- [ ] T032 `WEIRDNESS.md`: discipline lines → `docs/upstream/displanary/WEIRDNESS.md`
- [ ] T033 Prune unused keys from `lang/{en,ar}/messages.php` and `lang/ar.json`; `TranslationCoverageTest` passes
- [ ] T034 `docs/upstream/FOUNDATION-CHANGES.md` lists every kept foundation file edited (with "port" / "do not apply")

## Phase 6 — Verification (orchestrator)

- [ ] T035 `migrate:fresh --seed` on the dev DB `attendance`
- [ ] T036 Grep: no references to removed classes, tables, routes, translation keys
- [ ] T037 Browser smoke test: platform admin creates company, branch and HR user; HR logs in, switches to Arabic, imports a CSV, opens an employee page, searches
- [ ] T038 Open PR
