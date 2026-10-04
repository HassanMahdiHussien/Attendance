# Tasks: Platform Foundation and Core Data Model

**Input**: Design documents from `/specs/001-foundation-schema/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/domain-services.md, quickstart.md

**Tests**: REQUIRED. Constitution Principle IV covers tenant isolation, immutability and sequencing,
so these suites are mandatory. All tests use Pest against MySQL.

**Execution**: Phase 1 is done by the orchestrator (package installs inside Docker, approved
2026-10-04). Phases 2–7 are delegated to Codex in runs, each followed by review plus the full gate
(`php artisan test`, `pint --test`).

Gate commands (run inside the app container):
`docker compose exec -T laravel.test php artisan test` ·
`docker compose exec -T laravel.test ./vendor/bin/pint --test`

## Format: `[ID] [P?] [Story] Description`

---

## Phase 1: Setup (orchestrator)

- [x] T001 Create Laravel 13 skeleton via `laravelsail/php84-composer` and move it into the repo root, keeping existing docs, `.specify/`, `.claude/`, `.gitignore`, `.gitattributes`
- [x] T002 Install Sail with MySQL (`sail:install --with=mysql`), PHP 8.4 runtime; `.env`/`.env.example` set to `DB_CONNECTION=mysql`, `QUEUE_CONNECTION=database`, `FILESYSTEM_DISK=local`, `SEED_DEFAULT_PASSWORD=`
- [x] T003 Add `queue` (`php artisan queue:work --tries=3`) and `scheduler` (`php artisan schedule:work`) services to `compose.yaml`
- [x] T004 Replace PHPUnit tests with Pest 5 + pest-plugin-laravel; configure `phpunit.xml` to use MySQL database `testing`
- [x] T005 [P] Install livewire/livewire 4, filament/filament 5 (no panel yet), spatie/laravel-permission 8 (publish config + migration)
- [x] T006 [P] `npm install` + `npm run build` succeed in container
- [x] T007 Bring the stack up, run default migrations and the default test suite; confirm green

---

## Phase 2: Foundational (Codex run A)

- [x] T008 [P] Enums in `app/Enums/`: CaseStatus (exact list in data-model.md), DocumentType, DocumentStatus, DraftedBy, PenaltyType, Outcome, DefenseAcceptance, Jurisdiction, PoolRole, UserRole, ReportSource, ReportStatus, CaseLinkType, Vote, SignaturePurpose, SignatureStatus, PresenceCheck, AttachmentKind, LawKind, LawApprovalStatus, LegalUnitStatus, RelevanceCheck, SequenceKey, ApprovalDecision, SubscriptionStatus, AiGenerationStatus
- [x] T009 [P] Exceptions in `app/Exceptions/`: ImmutableRecordException, MissingTenantContext, TenantMismatch, IssuanceBlocked
- [x] T010 `app/Support/Tenancy/TenantContext.php` (scoped singleton), `CompanyScope.php`, `LegalLibraryScope.php` per contracts
- [x] T011 `app/Models/Concerns/BelongsToCompany.php` (scope + stamping + parent copy/mismatch) and `AppendOnly.php` (model guard)
- [x] T012 Migrations, platform tables: companies (incl. subscription_status), hr_partners, law_firm_partners, pivots; modify users (company_id, employee_id unique, primary_role, partner FKs, is_active)
- [x] T013 Migrations, tenant tables with `unique(company_id,id)` and composite FKs: branches, locations, employees, branch_user, committee_mandates, committee_pool_members, violation_reports, report_witnesses, cases, case_witnesses, case_events, case_links, committee_session_attendances, appeal_trackings, attachments, documents, document_contents, document_approvals, signatures, citations, company_sequences
- [x] T014 Migrations, legal library: laws, law_jurisdictions, legal_units
- [x] T015 Migrations, audit_logs and ai_generation_logs
- [x] T016 Triggers migration (up creates / down drops) implementing every DB rule in contracts/domain-services.md
- [x] T017 Eloquent models for every table (relations, casts, BelongsToCompany/AppendOnly, guards for locked documents/contents/citations/approvals, verified legal units, no-delete on cases/documents/signatures/approvals, users role/company invariant, enabled_languages ar+en invariant); case model `DisciplinaryCase` on table `cases`
- [x] T018 Factories for every model (create inside transactions where numbering applies)

## Phase 3: User Story 1 — Tenant isolation (P1) (Codex run A)

**Independent test**: two companies; a company-A context never reads/writes company-B data; Platform Admin sees both.

- [x] T019 [US1] `app/Http/Middleware/SetTenantContext.php` registered in web group; Queue before/after listener resetting context (`AppServiceProvider`)
- [x] T020 [P] [US1] `tests/Feature/Tenancy/IsolationTest.php`: dataset over every T model — list, find, count, builder update/delete, create stamping, explicit mismatch
- [x] T021 [P] [US1] `tests/Feature/Tenancy/ContextTest.php`: fail-closed when unset, runForCompany restore, runAcrossAllCompanies, platform admin all, job context reset, legal library scope (statutes global, regulation own-company)
- [x] T022 [P] [US1] `tests/Feature/Tenancy/CompositeForeignKeyTest.php`: each tenant FK rejects another company's row via Eloquent and raw SQL; citation/attachment/user model validations

**Checkpoint**: gate green → review → commit.

## Phase 4: User Story 2 — Legal record integrity (P1) (Codex run B)

- [ ] T023 [US2] `app/Services/SequenceService.php` (row lock, transaction required, per company/year)
- [ ] T024 [US2] Case-number and employee-code assignment in model `creating` hooks via SequenceService
- [ ] T025 [US2] `app/Services/CaseEventLogger.php`, `app/Services/AuditLogger.php`
- [ ] T026 [US2] `app/Services/Documents/DocumentIssuer.php` (issue + previewReference + gate R6b + manual-skip numbering + supersede previous)
- [ ] T027 [US2] `app/Services/Documents/DocumentCorrector.php`
- [ ] T028 [P] [US2] `tests/Feature/Immutability/*Test.php`: full invariant matrix rows for logs, attachments, documents, contents, citations, approvals, legal units (Eloquent, builder, raw SQL)
- [ ] T029 [P] [US2] `tests/Feature/Sequences/SequenceTest.php`: case numbers, employee codes (given vs generated), reference per year reset, manual override, manual skip, duplicate manual rejected, outside-transaction throws, rollback doesn't consume
- [ ] T030 [P] [US2] `tests/Feature/Documents/IssueAndCorrectTest.php`: gate (languages, second review, Doc A approvals), issue, correct, abandoned correction, supersede on re-issue, case events written
- [ ] T031 [P] [US2] `tests/Feature/Migrations/TriggerLifecycleTest.php`: migrate:fresh + rollback recreate/drop triggers

**Checkpoint**: gate green → review → commit.

## Phase 5: User Story 3 — Pilot seed data (P2) (Codex run C)

- [ ] T032 [US3] `database/seeders/LawsSeeder.php` (37/2015 federal_iraq, 71/1987 kurdistan_region, 18/2023 both; no units)
- [ ] T033 [US3] `database/seeders/PilotCompanySeeder.php` (anonymised: company, Hilla branch federal_iraq, locations, ~12 employees incl. GM, mandate 1097 dated 2026-08-26, pool 5 HR / 2 Legal / 2 Union)
- [ ] T034 [US3] `database/seeders/LocalUsersSeeder.php` (platform admin + company admin; password from `SEED_DEFAULT_PASSWORD`, refuse to run if empty) and `DatabaseSeeder`
- [ ] T035 [P] [US3] `tests/Feature/Seed/PilotSeedTest.php`: structure counts, jurisdictions, no real names from the Administrative Order (list of the 9 real names + GM name in the test)

## Phase 6: User Story 4 — Arabic shell (P3) (Codex run C)

- [ ] T036 [US4] `resources/css/app.css` tokens (§18) + `resources/views/layouts/app.blade.php` (`lang="ar" dir="rtl"`, Tajawal/Amiri/JetBrains Mono) + Arabic home view
- [ ] T037 [US4] `app/Jobs/HeartbeatJob.php`, `app/Console/Commands/Heartbeat.php` (`app:heartbeat [--dispatch]`), scheduled every minute in `routes/console.php`
- [ ] T038 [P] [US4] `tests/Feature/Shell/HomePageTest.php` (RTL, Arabic, fonts) and heartbeat job test

## Phase 7: Polish (Codex run C + orchestrator)

- [ ] T039 [P] Rewrite `docs/ERD.md` to match the implemented schema and CLAUDE.md (drop LEGAL_ARTICLES / COMPANY_LEGAL_EXCERPTS / content_snapshot; add missing tables/columns; note composite FKs and triggers)
- [ ] T040 [P] Update `docs/LAUNCH-CHECKLIST.md` (sensitive trigger = request-only; leave does not pause deadlines — both resolved per CLAUDE.md)
- [ ] T041 [P] `WEIRDNESS.md`: denormalised company_id + composite FKs; users outside the throwing scope; DB triggers for immutability; reference auto-numbering skips manual values
- [ ] T042 [P] `docs/adr/0009-filament-v5-livewire-4.md` + index row
- [ ] T043 README.md with the quickstart (orchestrator)
- [ ] T044 Validate quickstart from a clean clone; full gate; Pint (orchestrator)

## Dependencies

Phase 1 → Phase 2 → (US1 ∥ US2 after T018; executed in runs A then B) → US3/US4 → Polish.
US2 depends on Phase 2 models; US3 seeders depend on US2 numbering hooks.

## Implementation Strategy

Ship in four commits on `001-foundation-schema`: setup, run A (foundation + US1), run B (US2),
run C (US3 + US4 + docs). Open one PR when all are green.
