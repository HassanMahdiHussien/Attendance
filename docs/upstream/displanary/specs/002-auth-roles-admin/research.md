# Research: Sign-in, Roles and Admin Screens

## R1. Two Filament panels

- **Decision**: `platform` panel (path `/platform`, Platform Admin only, no tenancy) and `admin`
  panel (path `/admin`, tenancy with tenant = `Company`, slug = id; company admin, HR staff, and
  Platform Admin). Both panels have their own Filament login pages. A custom `LoginResponse` sends
  the Platform Admin to `/platform` even when they signed in at `/admin`.
- `User` implements `FilamentUser` (`canAccessPanel`) and `HasTenants` (`getTenants`: own company,
  or all active+inactive companies for the Platform Admin; `canAccessTenant` accordingly).
- **Tenant context, single source per request**: the global web middleware `SetTenantContext` runs
  first and sets the user's base context (company user → company; platform admin → all; others →
  unset). It clears the context in `finally` after the response. Inside it, the `admin` panel
  registers `SetTenantFromFilament` as **persistent** tenant middleware, so it also runs on Livewire
  update requests. It requires `Filament::getTenant()` to be set and `canAccessTenant`, then calls
  `setCompany($tenant)`, overriding "all" for the platform admin. For company users it asserts that
  the tenant equals their own company (403 otherwise). Panel requests therefore always run in exactly
  one company. Tests cover the initial page, Livewire actions (table actions, form save), tenant
  switch, relation managers, global search (disabled on resources that don't need it), and direct
  record URLs with another company's id. The `platform` panel works in `setAllCompanies()` (it manages companies and
  partners, which are platform tables) and never shows tenant records directly.
- **Alternatives**: a single panel with role-based navigation (mixes platform and tenant records on
  one screen, which makes accidental cross-tenant lists easy); no Filament tenancy (the Platform
  Admin could not enter a company's setup area cleanly).

## R2. Sign-in hardening

- Filament's `Login::authenticate()` already uses a timebox, rate-limits at 5 per minute per
  component/IP, and refuses users for whom `canAccessPanel()` is false (same generic error, so roles
  and inactive accounts are not revealed).
- **Decision**: a custom `App\Filament\Auth\Login` extending Filament's page adds a second limiter
  keyed by `login-email:` + trimmed lower-cased email. It is checked **before** credentials, so once 5
  failures accumulate, even a correct password is refused until decay. It is hit on every failure,
  including refusal because of role or inactive account, and cleared on success. A throttled attempt
  is audited as `auth.throttled`. The `Failed` listener builds metadata explicitly
  (`email`, `ip`, `panel`, `user_agent`), never from `$event->credentials` as a whole. The public
  message is the same for unknown email, wrong password, inactive account and wrong panel.
- `canAccessPanel()` returns false when the user is inactive, their company is inactive, or their
  role has no area in that panel.
- **Existing sessions**: `EnsureAccountIsActive` and `EnforceIdleTimeout` are appended to the global
  `web` group (after session/auth resolution), so they protect every authenticated request: panel
  pages, Livewire updates and future workflow routes. They log out and redirect to login when the
  user or company became inactive or the session idled out. Deactivation also deletes the
  user's rows from the `sessions` table (database session driver).
- **Idle timeout**: an `EnforceIdleTimeout` middleware stores the last activity time in the session
  and logs out after 60 minutes, or 30 for platform-level roles. Limits live in `config/displanery.php`.
  `SESSION_LIFETIME` stays the absolute upper bound.
- **Audit**: listeners on `Illuminate\Auth\Events\Login`, `Logout` and `Failed` write `auth.login`,
  `auth.logout` and `auth.failed` (email, IP, guard; never the password) through `AuditLogger`.

## R3. Roles and add-on abilities

- **Decision**: `primary_role` (enum) stays the single main role. `UserRole::CompanyLegalReviewer`
  is removed, and a data migration converts any such user to a **deactivated `reporter`** with the
  `review_internal_regulation` permission. This never escalates access; a company admin reassigns
  the account. `User` gains spatie's `HasRoles` trait (guard `web`) for permissions only. spatie/laravel-permission provides only two global
  permissions: `review_internal_regulation` and `view_all_company_cases`. They are seeded by a
  migration, not a seeder, so they always exist. Teams mode is off because each user belongs to at
  most one company.
- Add-ons are valid only for company roles: `view_all_company_cases` for HR staff, and
  `review_internal_regulation` for company admin and HR staff. When a role changes,
  `UserAccountService` strips any add-ons that no longer apply.
- Policies check role + add-on; nothing reads spatie roles.

## R4. Case visibility (FR-020/021)

- **Decision**: `App\Support\Access\CaseVisibility::for(User $user): Builder` is the single entry
  point.
  - company admin → company cases (through the tenant scope);
  - HR staff → `opened_by_user_id = me OR assigned_hr_user_id = me`, or all with the add-on;
  - committee member → cases with a `committee_session_attendances` row whose pool member has
    `user_id = me`;
  - dept manager / reporter → none;
  - external legal reviewer → `withoutGlobalScope(CompanyScope)`, `legal_review_requested_at IS NOT
    NULL`, and the company is partnered with the reviewer's law firm (`company_law_firm_partner`). The
    product owner confirmed firm-wide visibility (2026-10-05);
  - HR-partner staff → `withoutGlobalScope(CompanyScope)`, the company is partnered with their HR
    partner with `full_case_access = true`;
  - Platform Admin → all (explicit `withoutGlobalScope`).
  Removing the scope is allowed only inside this class, which a test enforces by grepping `app/` for
  `withoutGlobalScope(CompanyScope` outside the allowed files.
- `ViolationReportVisibility::for(User)`: reporters and dept managers see their own reports (status
  only, via a dedicated resource later); HR follows case-like rules.
- `DisciplinaryCasePolicy::view` delegates to `CaseVisibility`.
- External reviewers / HR-partner staff get no default tenant context (the middleware leaves it
  unset), so any other query they trigger fails closed.

## R4b. Unscoped models in the admin panel (users, partnerships)

- `users` has no tenant scope, so `Admin\UserResource::getEloquentQuery()` explicitly filters
  `company_id = Filament::getTenant()->id`, and for HR staff also `primary_role IN (reporter,
  dept_manager)`. `UserPolicy` re-checks company and role on view / update / activate. Every select
  that lists users (pool member login link, case assignee later) uses the same scoped query helper
  `User::query()->inCompany($company)`.
- Partnership pivots and platform users exist only in the `platform` panel (platform admin), never in
  `admin`.
- Tests: another company's user id via direct URL → 404/403; form select options contain only the
  tenant's users; HR staff cannot open or save a company_admin / hr_staff / committee_member account.

## R5. Audit trail for admin changes

- **Decision**: an `App\Models\Concerns\Audited` trait (used by Company, User, Branch, Location,
  Employee, CommitteeMandate, CommitteePoolMember, LawFirmPartner, HrPartner and both partnership
  pivots, so 3+ places justify a trait). It logs created / updated (before/after of dirty attributes,
  excluding `password`, `remember_token` and hidden attributes) and deleted through `AuditLogger`.
  Add-on (permission) changes are logged explicitly in `UserAccountService`, because spatie writes
  pivots directly. Partnerships are created and removed only through `PartnershipService`
  (attach / detach / toggle `full_case_access`), which logs `partnership.*` with before/after, never
  through raw relationship `sync`. An audit-inventory test performs every action in the access matrix
  and asserts one entry per action, with actor, before/after and no `password` key anywhere.

## R6. Services

- `CompanyOnboardingService::create(array $company, array $admin, User $actor)`: company + first
  company admin in one transaction.
- `UserAccountService`: create, update, change role, set add-ons, set branch scope, activate /
  deactivate (session purge), last-active-admin guard.
- `CommitteeMandateService::register(array $data, User $actor)`: in a transaction, locks the
  company's mandates, refuses an `effective_from` not later than the latest mandate's, sets the
  latest mandate's `superseded_at` to the new `effective_from` (start of day, Baghdad), and audits
  `mandate.registered`. Tests: first mandate, future-dated (old stays in force until then, checked
  via `inEffectOn`), backdated refused, equal date refused.
- `EmployeeService` is not needed: numbering already lives in the model hook, and the
  single-GM rule is a model `saving` guard. Filament forms call services through `mutateFormData` /
  `handleRecordCreation` overrides to keep Filament resources thin.

## R7. Timezone

- **Decision**: `config/app.php` `timezone` → `env('APP_TIMEZONE', 'Asia/Baghdad')`. Iraq has had no
  DST since 2008, so offsets are stable. Laravel stores timestamps in the app timezone. Existing
  local data is dev-only. A test pins `now()` to 2026-12-31 21:30 UTC (00:30 on 1 January Baghdad)
  and checks that an issued document gets `reference_year = 2027`.

## R8. Arabic Filament theme

- **Decision**: `APP_LOCALE=ar` gives Filament's Arabic translations and RTL automatically. A custom
  Filament theme (`make:filament-theme`, Tailwind v4, no new npm packages beyond what Laravel already
  ships; flag if the generator asks for any) sets primary = indigo `#4F46E5`, the §18 status colours
  and the Tajawal font for both panels.
- Labels in resources are written in Arabic directly (UI language is Arabic-only for now).

## R9. Leave confirmation decision (record only)

- Add a confirmed bullet to CLAUDE.md §3 and a WEIRDNESS line: before a termination decision letter
  is issued, HR must tick "the employee is not on statutory leave or documented sick absence". The
  system records who and when (`documents` or `cases` columns, designed in the workflow feature).
  Until the leave module exists, this is the source of truth.
