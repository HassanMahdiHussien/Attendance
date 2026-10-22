# Feature Specification: Foundation cleanup — remove the discipline module

**Branch**: `500-foundation-cleanup` | **Created**: 2026-10-21 | **Status**: Draft — awaiting product-owner review

**Input**: Product owner (2026-10-21): build attendance in a separate repo copied from Displanary (ADR 0013). This repo is a copy of Displanary `main` at `2d85ddb`. Before any attendance code, remove everything that only serves discipline and keep the shared foundation, so the attendance module starts on a clean, tested base that can later merge back into Displanary.

## Rules for this spec

- **Remove, don't rewrite.** Delete discipline code; do not refactor what stays, except the minimum needed for it to work without the removed code. Every such edit to a kept file is listed in `docs/upstream/FOUNDATION-CHANGES.md`.
- **Keep this cleanup in its own PR**, never mixed with attendance features. At merge-back time it is the one commit Displanary must not receive.
- Work in small steps; the full suite and Pint must pass after each step. Tests that only exercise removed code are removed with it.
- If something is unclear — a kept file depends deeply on discipline code, or something looks shared but isn't listed — **stop and ask** instead of guessing.

## Requirements

- **FR-001 Keep (shared foundation)** — stays working and tested:
  - Tenancy: `CompanyScope`, `TenantContext`, `BelongsToCompany`, composite foreign keys, isolation tests.
  - Companies, branches (with `jurisdiction`), locations, company settings and onboarding (minus discipline steps), company status, sequences.
  - Users, login, login throttling, roles and permissions, platform admin, user preferences and `SetLocale` (en/ar).
  - Employees: model, Filament resource, CSV import with preview, employee index and page (with the discipline section removed), employee picker, global search over employees.
  - Audit: `audit_logs`, `AuditLogger`, `Audited`, `AppendOnly`, immutability triggers for kept tables.
  - Help center shell, heartbeat, failure checks, backup drill, test mail, deploy scripts, design system, layouts, Filament theme, translations for kept screens.
- **FR-002 Remove (discipline only)**: cases and the case workflow (`Workflow`, `Livewire/Cases`, `Services/Cases`, case events, alerts, links, witnesses, conflicts, appeals), documents and templates (`app/Documents`, `Services/Documents`, document contents, approvals, signatures, letterheads, PDF document output), the legal library and citations (`LegalLibrary`, laws, legal units, regulation comparisons, mismatch queue, legal page images, import/export commands), committee mandates and pools, violation types and reports, incidents, report assistant, intake questions, email intake, AI drafting (`app/Ai` and its jobs), HR-partner and law-firm-partner features, case dashboards, hotspots, case statistics, and discipline notifications.
  - **Also removed (product owner, 2026-10-21, plan D0/D1):** attachments and report exports (both exist only for discipline owners and statistics; attendance builds its own file storage in 501/502 and exports in 505), and every role except `platform_admin` and `hr_staff` (HR takes over the company admin's duties in its own company).
  - Remove their migrations' tables by editing the original migrations (this repo has no production database yet), so a fresh `migrate` creates only kept tables.
  - Remove discipline routes, nav entries, translations, help articles, seeders, factories and tests.
  - `docs/PRD.md`, `docs/PRD-EN.md`, `docs/ERD.md`, `docs/laws/`, discipline ADRs and `specs/001`–`063` move to `docs/upstream/displanary/` for reference, not deleted. ADR numbering continues from 0013.
- **FR-003 What stays shared but needs a small edit**:
  - The home screen and HR dashboard become empty attendance placeholders ("Attendance dashboard — coming in spec 505") so navigation still works.
  - The employee page keeps identity fields and an empty "Attendance" section placeholder.
  - Company onboarding drops committee, letterhead and internal-regulation steps.
  - `.specify/memory/constitution.md` is amended for attendance (version 2.0.0, product-owner approval required): Principle I unchanged; Principle II becomes "AI proposes, deterministic rules calculate, humans confirm"; Principle III becomes "Source records and audit are immutable"; Principle IV lists the attendance test priorities from CLAUDE.md §7; the technology section drops document/legal rules.
  - `README.md`, `PRODUCT.md` and `DESIGN.md` wording updated from discipline to attendance where it describes the product (design tokens unchanged).
- **FR-004 Done means**:
  - `php artisan migrate:fresh --seed` works and creates only kept tables.
  - Platform admin can create a company, branches and users; HR can log in, switch language, import employees from CSV, open an employee page, and search.
  - Tenant isolation, audit append-only, login, employee import and i18n test suites pass. Full suite and Pint pass.
  - `grep` finds no references to removed classes, routes, tables or translation keys.
  - `docs/upstream/FOUNDATION-CHANGES.md` lists every kept file edited, with a one-line reason.

## Out of scope

Any attendance feature (spec 501 onward). Any change to Displanary itself.
