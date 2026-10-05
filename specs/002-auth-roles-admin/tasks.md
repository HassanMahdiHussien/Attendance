# Tasks: Sign-in, Roles and Admin Screens

**Input**: `/specs/002-auth-roles-admin/` (spec, plan, research, data-model, contracts/access-matrix, quickstart)

**Tests**: REQUIRED (Constitution I and IV; SC-001..SC-005). Pest on MySQL, explicit tenant context.

**Execution**: Codex run D = Phases 1–3 + US4; Codex run E = US2 + US3 screens + docs. Codex has no
Docker access; after each run the orchestrator runs
`docker compose exec -T laravel.test php artisan test` and `… ./vendor/bin/pint --test`, then sends
fix briefs.

## Phase 1: Setup (run D)

- [x] T001 `config/app.php` timezone `env('APP_TIMEZONE','Asia/Baghdad')`; `APP_TIMEZONE=Asia/Baghdad` in `.env.example` (orchestrator mirrors into `.env`)
- [x] T002 `config/displanery.php` (idle timeouts default 60 / platform 30; add-on permission names)
- [x] T003 Migration: remove `company_legal_reviewer` role rows → deactivated `reporter` + permission (R3); remove enum case; update factories/tests that referenced it
- [x] T004 Migration: insert spatie permissions `review_internal_regulation`, `view_all_company_cases` (guard web), idempotent, reversible; `User` uses `HasRoles`

## Phase 2: Foundational (run D)

- [x] T005 `app/Models/Concerns/Audited.php` (R5) applied to Company, User, Branch, Location, Employee, CommitteeMandate, CommitteePoolMember, LawFirmPartner, HrPartner
- [x] T006 `app/Listeners/AuditAuthEvents.php` for Login / Logout / Failed (explicit safe metadata)
- [x] T007 Middleware `EnsureAccountIsActive`, `EnforceIdleTimeout` appended to web group; `SetTenantFromFilament` (persistent tenant middleware) per R1
- [x] T008 `User` implements `FilamentUser`, `HasTenants`; `canAccessPanel` (role + active user + active company), `getTenants`, `canAccessTenant`; scope `inCompany()`; guards from data-model (partner ids for platform roles)
- [x] T009 Panel providers `PlatformPanelProvider` (/platform) and `AdminPanelProvider` (/admin, tenant Company), both using `App\Filament\Auth\Login` (per-email limiter, R2) and custom `LoginResponse`; Arabic, Tajawal, indigo primary (theme in run E)
- [x] T010 Services: `CompanyOnboardingService`, `UserAccountService`, `CommitteeMandateService`, `PartnershipService` (R6, R4b, audit)
- [x] T011 `Employee` single-active-GM guard; `CommitteePoolMember` login-link guard
- [x] T012 Policies: Company, Branch, Location, Employee, User, CommitteeMandate, CommitteePoolMember, LawFirmPartner, HrPartner, DisciplinaryCase (view via CaseVisibility) per access matrix

## Phase 3: US1 sign-in (P1) (run D)

- [x] T013 [P] [US1] `tests/Feature/Auth/SignInTest.php`: per role allowed/refused, identical public message (unknown / wrong / inactive / wrong panel), platform admin redirect
- [x] T014 [P] [US1] `tests/Feature/Auth/ThrottleTest.php`: 5 failures then correct password refused; normalisation; per-IP; `auth.failed` / `auth.throttled` without password
- [x] T015 [P] [US1] `tests/Feature/Auth/SessionTest.php`: deactivated user / company refused on every request type (contract list); session rows purged; idle 60 / 30 minutes

## Phase 4: US4 visibility (P2) (run D)

- [x] T016 [US4] `app/Support/Access/CaseVisibility.php`, `ViolationReportVisibility.php` (R4)
- [x] T017 [P] [US4] `tests/Feature/Access/CaseVisibilityTest.php`: two-company mixed dataset, every role, exact sets (SC-002)
- [x] T018 [P] [US4] `tests/Feature/Access/ScopeBypassGuardTest.php`: `withoutGlobalScope(CompanyScope` appears only in allowed files
- [x] T019 [P] [US4] `tests/Feature/Timezone/BaghdadClockTest.php`: app tz, reference_year rollover at 00:30 Baghdad 1 Jan

**Checkpoint run D**: gates green → review → commit.

## Phase 5: US2 platform area (P1) (run E)

- [ ] T020 [US2] Platform resources: Companies (create with first admin via onboarding service; languages ar+en locked, ku optional; activate/deactivate; subscription status; no delete), LawFirmPartners + HrPartners (partnerships via PartnershipService, full_case_access), PlatformUsers
- [ ] T021 [P] [US2] `tests/Feature/Platform/*Test.php`: matrix cells via Livewire/HTTP incl. direct URLs; deactivation effects; audit entries

## Phase 6: US3 company setup (P1) (run E)

- [ ] T022 [US3] Admin resources: Branches, Locations, Employees, Users (scoped query R4b, role options by actor, add-ons, branch scope, activate/deactivate), CommitteeMandates (+ pool relation manager, register via service), CompanySettings page; Filament theme with §18 tokens
- [ ] T023 [P] [US3] `tests/Feature/Admin/*Test.php`: matrix cells for company_admin / hr_staff / platform_admin-in-tenant; other-company ids → 404/403; select options scoped; GM rule; EMP code; mandate date rules; last-admin guard
- [ ] T024 [P] [US3] `tests/Feature/Admin/TenantContextInPanelTest.php`: Livewire actions, tenant switch, relation manager, direct URLs (R1)
- [ ] T025 [P] `tests/Feature/Audit/AuditInventoryTest.php`: one entry per action in the matrix, before/after, no secrets (SC-004)

## Phase 7: Polish (run E + orchestrator)

- [ ] T026 [P] CLAUDE.md §3 bullet + WEIRDNESS line: termination requires HR "not on leave / sick absence" confirmation (who/when); WEIRDNESS: consultation visibility is firm-wide; legacy company_legal_reviewer → deactivated reporter
- [ ] T027 [P] docs/ERD.md: UserRole list, permissions note
- [ ] T028 Orchestrator: quickstart V2–V10 in the browser, full gates, PR

## Dependencies
Phase 1 → 2 → (US1, US4) → US2/US3 screens → polish.
