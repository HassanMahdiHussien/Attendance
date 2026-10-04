# Research: Platform Foundation and Core Data Model

## R1. Framework and package versions (checked on Packagist 2026-10-04)

- **Decision**: PHP 8.4, Laravel 13.x (`laravel/framework` 13.34), Livewire (version follows the
  Filament choice), Pest 5.x, Pint 1.x, Sail 1.68, `spatie/laravel-permission` 8.x, MySQL 8.4 LTS.
- **Filament**: CLAUDE.md names **v4** (4.14, on Livewire 3). **v5** (5.9, on Livewire 4) is the
  current major; both support Laravel 13. → **Product owner approved Filament v5 + Livewire 4
  (2026-10-04)**. Recorded as ADR 0009, superseding the version pin in CLAUDE.md §2. All packages in
  the plan were approved for install the same day.
- **Rationale**: CLAUDE.md's "latest LTS" predates Laravel's move away from LTS labels; 13.x is the
  current supported line. Pest 5 requires PHP ≥ 8.4.
- **Alternatives**: Laravel 12 (older, no benefit); Filament v4 (fine, but sits on an older
  Livewire).

## R2. Local environment without host PHP

- **Decision**: Create the skeleton with the `laravelsail/php84-composer` image
  (`composer create-project laravel/laravel`) in a temp directory, move it into the repo root, then
  `sail:install --with=mysql`. All later PHP commands run via
  `docker compose exec -T laravel.test …` (works from PowerShell and Codex without WSL bash).
- **Rationale**: the host has no PHP/Composer/MySQL; Sail matches the Linux VPS (Browsershot +
  Chromium + fonts later behave the same).
- **Alternatives**: native Scoop PHP (production mismatch); `laravel.build` script (needs bash and
  creates a subfolder).

## R3. Tenant scoping that fails closed

- **Decision**: A request-/job-scoped `TenantContext` service with three states: *unset*, *company
  X*, *all companies (explicit)*. A `BelongsToCompany` trait adds a global scope:
  - company X → `where company_id = X`;
  - all → no filter (only reachable through `TenantContext::runAcrossAllCompanies()` or for the
    Platform Admin);
  - unset → **throws `MissingTenantContext`**. No silent cross-tenant read is possible (FR-005).
  The trait also stamps `company_id` on create from the context and refuses a save whose
  `company_id` differs from the context company (FR-003). A middleware sets the context from the
  signed-in user (company user → their company; Platform Admin → all). Other company-less roles get
  no context yet; their rules come with feature 002.
- **Child records** carry their own `company_id` (denormalised) instead of inheriting it through
  joins. That keeps one simple scope for every table and makes isolation tests uniform. On create,
  a child copies `company_id` from its declared parent relation and refuses a mismatch.
- **Every tenant-to-tenant FK is a composite FK** `(company_id, x_id) → parent(company_id, id)`.
  Each tenant table gets a `unique(company_id, id)` index for this. MySQL then refuses a row whose
  employee / branch / location / mandate / case / document belongs to another company, including
  raw inserts. Nullable FKs work as expected (MATCH SIMPLE: a null `x_id` is not checked). Links
  that cannot be composite are validated in the model and tested: citations → legal units (global
  statute or same-company regulation), polymorphic attachments, users → company / employee.
- **Context lifecycle**: HTTP: `SetTenantContext` middleware after auth; Filament (feature 002) maps
  `Filament::getTenant()` into `TenantContext` in its panel middleware. Queued jobs carry the
  company id and call `runForCompany` inside `handle()`; context is reset between jobs by a
  `Queue::before`/`after` listener so a worker never leaks one job's tenant into the next. Seeders
  and tests use `runAcrossAllCompanies` / `runForCompany` explicitly.
- **Users** are deliberately *not* behind the throwing scope, because authentication must load a
  user before any context exists. Isolation for user management is enforced by policies in feature
  002 (one line in WEIRDNESS.md).
- **Legal library** uses its own scope: statutes (`company_id` null) are visible to every tenant;
  internal-regulation rows are visible only to their company.
- **Alternatives**: `stancl/tenancy` (DB-per-tenant oriented, heavy, rejected by ADR 0001); inherit-
  via-join scopes (more complex queries, easy to miss); a scope that returns nothing when unset
  (fails silently and hides bugs).

## R4. Append-only logs and document immutability — defence in depth

- **Decision**: two layers.
  1. **Model layer**: `updating`/`deleting` listeners throw `ImmutableRecordException` for
     `CaseEvent`, `AuditLog`, `AiGenerationLog`, `Attachment`; and for `Document` /
     `DocumentContent` once the document is locked.
  2. **Database layer**: MySQL `BEFORE UPDATE` / `BEFORE DELETE` triggers that `SIGNAL SQLSTATE
     '45000'` on the append-only tables, on `document_contents` whose parent document is locked, and
     on `documents` when `OLD.is_locked = 1` and any protected column changes. The only allowed
     changes on a locked document are `status` issued→superseded, `superseded_by_id` null→value and
     `pdf_file_path` null→value. Deleting a locked document is always refused. `is_locked` can never
     go 1→0.
- **Rationale**: model events do not fire for query-builder `update()`/`delete()` or raw SQL. The
  triggers make immutability a property of the database, which is what ADR 0004 promises.
- **Inserts after lock**: `BEFORE INSERT` triggers on `document_contents` and `citations` refuse
  rows whose parent document is locked (adding a language or citation would change an issued
  record). Approvals may be inserted only while the document is not locked.
- A locked document row refuses *any* other column change, including `updated_at` on its own.
- Verified legal units (`verified_auto` / `verified_human`) are frozen as a whole row (law, article,
  clause path, text, page, company). The only allowed changes are `superseded_by_id` null→value; a
  correction is a new row. Enforced by trigger + model guard.
- **Testing**: tests run against MySQL (Sail's `testing` database), not SQLite, so triggers are
  exercised. Triggers are created and dropped in migrations (`up`/`down`), and a test runs
  `migrate:fresh` + `migrate:rollback` to prove they rebuild cleanly.
- **Alternatives**: model events only (bypassable); MySQL user privileges without UPDATE on log
  tables (operationally awkward on a solo VPS; can be added later).

## R5. Gap-free sequences (case numbers, reference numbers, employee codes)

- **Decision**: `company_sequences(company_id, sequence_key, year, next_value)` with a unique key.
  `SequenceService::next()` runs inside the caller's transaction and does `SELECT … FOR UPDATE` on
  the row (creating it on first use with insert-ignore + re-select). Keys: `case` (year 0),
  `employee_code` (year 0), `document_reference` (calendar year of issuance).
- **Reference numbers**: assigned only inside `DocumentIssuer::issue()` in the same transaction that
  locks the document. A manual override does not advance the counter. Automatic allocation **skips
  any value already used** in that company + year (by a manual entry), advancing until it finds a
  free number, so a manual `1` can never strand the counter. The value pre-filled on the
  confirmation screen (`DocumentIssuer::previewReference()`) is provisional; the number is only
  fixed when the issuance transaction commits. Uniqueness is enforced by
  `unique(company_id, reference_year, reference_number)`.
- **Transaction boundary**: `SequenceService::next()` throws if `DB::transactionLevel() === 0`.
  Case and employee numbering in `creating` hooks therefore *requires* the caller to wrap creation
  in a transaction (services do; factories wrap). A failed insert rolls back the counter with it.
- **Formats**: case number stored as an integer, displayed zero-padded (`0135`, as in the mockup);
  employee code `EMP-0001`; reference number stored as a string (manual values like `963-B` are
  legal), generated values are plain integers.
- **Alternatives**: `MAX()+1` (race-prone); auto-increment ids (global, not per company/year, and
  gaps).

## R6. Document versioning semantics

- **Decision**: a correction creates a **new draft** (`version + 1`, `previous_version_id` → old,
  `correction_reason`, contents copied) and logs a `document_correction_started` case event. The old
  document stays the valid issued one until the new version is issued. At that moment, in the same
  transaction, the old one becomes `superseded` with `superseded_by_id` → new. An abandoned
  correction never touches the old document and never consumes a reference number. At most one open
  draft per (case, document type).
- **Rationale**: until the corrected text is issued, the old issued text is still what the employee
  holds, so marking it superseded early would misstate the record.

## R6b. Issuance gate (what the issuer enforces in feature 001)

- Every language in the company's `enabled_languages` (always `ar` + `en`) must have a content row.
- The issuing user is recorded as the confirmer (`issued_by`). The confirmation screen itself comes
  later, but the issuer is the only path to `issued`.
- If `companies.requires_second_review` is on, issuance requires `second_reviewed_by` (a different
  user than `issued_by`) and `second_reviewed_at` to be set on the document first.
- Doc A (recommendations) additionally requires three valid, non-invalidated approvals, one per
  category (legal, hr, labor_union). The check lives in the issuer; the approval UI is the workflow
  feature.
- **Deferred (open question for the product owner)**: PLATFORM-PRD §3.7 forbids issuing any
  termination letter while the employee is on statutory leave or documented sick absence. Phase 1
  has no leave data, so the workflow feature must define the source of that fact before termination
  decision letters can be issued.

## R7. Enums and status columns

- **Decision**: PHP backed enums (`CaseStatus`, `DocumentType`, `DocumentStatus`, `Jurisdiction`,
  `PoolRole`, `UserRole`, `SignatureStatus`, …) cast on models, stored as `string` columns (not MySQL
  `ENUM`), so adding a value never needs a column migration. `CaseStatus` lists every state in
  CLAUDE.md §7, verbatim.

## R8. Deletion policy

- **Decision**: FKs use `restrictOnDelete()` throughout. Companies, branches, locations, employees and
  users have `is_active`. `CaseModel`, `Document`, `Signature`, `DocumentApproval` and the logs refuse
  model deletes. No `SoftDeletes` (deactivation is a business state, not a trash bin).

## R9. Application shell

- **Decision**: Blade layout `layouts/app.blade.php` with `<html lang="ar" dir="rtl">`, Tailwind v4
  via Vite (Laravel default), the CLAUDE.md §18 tokens as CSS variables/Tailwind theme, Tajawal /
  Amiri / JetBrains Mono from Google Fonts (UI only; PDFs will embed local font files in the
  Browsershot feature). Queue: `QUEUE_CONNECTION=database`; a `queue` service and a `scheduler`
  service (`schedule:work`) are added to `compose.yaml` so `sail up` runs everything. A trivial
  `HeartbeatJob` plus a scheduled `heartbeat` command prove both run.
- **npm**: `npm install` (Laravel default front-end deps) needs product-owner approval like any
  install.

## R10. Seed data anonymisation

- **Decision**: invented Arabic names; structure only from the real order. Company "شركة الواحة
  للمشروبات الغازية" (the pilot client itself, kept by name), branch "الحلة" (federal_iraq), mandate
  order `1097` dated 2026-08-26, effective the same day, 5 HR + 2 Legal + 2 Labour Union pool
  members (company-wide, branch null). The chair defaults from the Legal pool at session time.
  Employees: ~12 across departments, including one General Manager. Laws: 37/2015 (federal_iraq),
  71/1987 (kurdistan_region), 18/2023 (both), with no units yet. Local users: one Platform Admin and
  one company admin, password from `.env` (`SEED_DEFAULT_PASSWORD`), never committed.
