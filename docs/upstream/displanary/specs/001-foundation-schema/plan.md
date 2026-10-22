# Implementation Plan: Platform Foundation and Core Data Model

**Branch**: `001-foundation-schema` | **Date**: 2026-10-04 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/001-foundation-schema/spec.md`

## Summary

Stand up the Laravel 13 application on Sail (MySQL, queue worker, scheduler, Arabic RTL shell) and
create the complete discipline data model from CLAUDE.md / the reconciled ERD. Legal guarantees are
built in from the first migration: a fail-closed tenant scope, DB-trigger-backed append-only logs
and document immutability, and row-locked per-company sequences. The plan also covers anonymised
pilot seed data, Pest tests for every guarantee, and corrections to the stale `docs/ERD.md` and
`docs/LAUNCH-CHECKLIST.md`.

## Technical Context

**Language/Version**: PHP 8.4

**Primary Dependencies**: Laravel 13.x, Livewire (3 or 4, follows Filament), Filament v4 or v5
(**owner decision**, research R1), spatie/laravel-permission 8.x (installed only; roles in feature
002), Laravel Sail, Pint; front-end: Vite + Tailwind v4 (Laravel defaults)

**Storage**: MySQL 8.4 (Sail service); local disk for files (ADR 0006)

**Testing**: Pest 5 against MySQL `testing` database (triggers must be exercised — no SQLite)

**Target Platform**: Linux containers locally (Sail); Ubuntu VPS later

**Project Type**: Web application (single Laravel monolith)

**Performance Goals**: N/A at this stage (<10 tenants); sequence allocation must be correct under
concurrency, not fast

**Constraints**: fail-closed tenancy; immutability enforced at DB level; no package install without
approval; no personal data beyond what CLAUDE.md requires

**Scale/Scope**: ~25 tables, ~25 models + enums, 5 small services, ~6 Pest test files, 1 seeder set

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Status | How |
|---|---|---|
| I. Tenant isolation | PASS | `BelongsToCompany` global scope, fails closed when no context (R3); isolation test over every T model |
| II. AI drafts, humans decide | PASS (n/a for logic) | schema supports `drafted_by`, citations to verified units, presence-check wording; `ai_generation_logs` AO table |
| III. Immutable & audited | PASS | model guards + MySQL triggers (R4); reference numbers only in `DocumentIssuer` (R5); AO logs |
| IV. Test load-bearing logic | PASS | Pest suites for isolation, immutability, sequences/reference numbers; deadline/quorum/approval tests belong to the workflow feature (logic not built here) |
| V. Simple Laravel, thin UI | PASS | services for issuance/correction/sequence/logging; one trait for tenancy used by ~20 models (pattern ≥3 places) |
| VI. Decisions stay decided | PASS | no `employee_violations`, no GM role, calendar-day-only, local disk; new deliberate choices (denormalised `company_id`, users outside the throwing scope, DB triggers) recorded in WEIRDNESS.md |
| Stack / approvals | **GATE** | `composer create-project`, Sail, Pest, Livewire, Filament, spatie/permission and `npm install` require owner approval before tasks run (Constitution: Technology constraints) |

Post-design re-check: PASS. No violations, so Complexity Tracking stays empty.

## Project Structure

### Documentation (this feature)

```text
specs/001-foundation-schema/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/domain-services.md
├── checklists/requirements.md
└── tasks.md            # /speckit-tasks
```

### Source Code (repository root)

```text
app/
├── Enums/                      # CaseStatus, DocumentType, DocumentStatus, Jurisdiction, PoolRole, UserRole, ...
├── Exceptions/                 # ImmutableRecordException, MissingTenantContext, TenantMismatch
├── Http/Middleware/SetTenantContext.php
├── Jobs/HeartbeatJob.php
├── Console/Commands/Heartbeat.php
├── Models/                     # Company, Branch, Location, Employee, User, CommitteeMandate, CommitteePoolMember,
│   │                           # ViolationReport, ReportWitness, DisciplinaryCase, CaseWitness, CaseEvent, CaseLink,
│   │                           # CommitteeSessionAttendance, AppealTracking, Attachment, Document, DocumentContent,
│   │                           # DocumentApproval, Signature, Law, LegalUnit, Citation, CompanySequence,
│   │                           # AuditLog, AiGenerationLog, HrPartner, LawFirmPartner
│   └── Concerns/               # BelongsToCompany, AppendOnly
├── Services/                   # SequenceService, CaseEventLogger, AuditLogger
│   └── Documents/              # DocumentIssuer, DocumentCorrector
└── Support/Tenancy/            # TenantContext, CompanyScope, LegalLibraryScope
database/
├── migrations/                 # one per table group + triggers migration
├── factories/
└── seeders/                    # PilotCompanySeeder, LawsSeeder, LocalUsersSeeder
resources/
├── css/app.css                 # §18 tokens
└── views/layouts/app.blade.php, welcome.blade.php (Arabic RTL)
tests/
├── Feature/Tenancy/            # isolation over every tenant model, fail-closed, stamping, platform admin
├── Feature/Immutability/       # documents, contents, approvals, AO logs (model + raw SQL)
├── Feature/Sequences/          # case numbers, employee codes, reference numbers (per year, manual, duplicates)
├── Feature/Documents/          # issue + correction flow
└── Feature/Seed/               # pilot structure, no real names
compose.yaml                    # + queue, scheduler services
docs/ERD.md, docs/LAUNCH-CHECKLIST.md, WEIRDNESS.md   # updated
```

**Structure Decision**: standard Laravel monolith layout; only `app/Services`, `app/Support/Tenancy`,
`app/Enums` and `app/Models/Concerns` are added, all named in CLAUDE.md §19 or required by R3/R4.

## Delegation plan

- Codex (`gpt-6-sol`) cross-checks this plan before tasks are generated.
- Implementation is split into Codex runs along the task phases (skeleton → schema → guarantees →
  seed/docs). After each run Claude reviews the diff and runs `php artisan test` + `pint --test` in
  the container before moving on.

## Complexity Tracking

No constitution violations to justify.
