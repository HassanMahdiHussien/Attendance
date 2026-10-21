# Implementation Plan: Company Setup Checklist Before the First Case

**Branch**: `020-setup-gate` | **Date**: 2026-10-09 | **Spec**: [spec.md](./spec.md)

## Summary
A `CompanySetup` service computes the §23.5 checklist; `CaseOpening` refuses incomplete companies; the admin sees the
checklist on the Filament dashboard and the home page; a migration records the regulation answer.

## Technical Context
Laravel 13 · Filament 5 (admin panel widget, CompanySettings page) · existing `CompanyLetterhead`, `Branch`
(`jurisdiction`), `CommitteeMandate::inEffectOn`, `CommitteePoolMember` (`role_type`, `branch_id`, `user_id`),
`Company` (`enabled_languages`, `has_internal_regulation`, `internal_regulation_file_path`), `RegulationUpload`,
`CaseOpening`, `MyWork`/home view · one migration · no packages.

## Research (decisions)
- **R1 Data**: migration adds `companies.internal_regulation_answer` (nullable string `yes|no`),
  `internal_regulation_answered_at`, `internal_regulation_answered_by` (FK users, nullable). Backfill: companies with
  at least one case → answer `yes` if `has_internal_regulation` else `no`, `answered_at = now`, `answered_by = null`.
- **R2 Checklist** `App\Services\Companies\CompanySetup::for(Company): array` items, each `{key, done, reason, url}`:
  `letterhead` (current letterhead row with a company name and a logo or header image — reuse the template/letterhead
  service's "current version" rule), `branches` (≥1 branch; every branch has a jurisdiction), `committee` (an order in
  effect today via `inEffectOn`; for every branch, active pool members with a linked active user or a named person in
  each of legal/hr/labor_union, counting company-wide (`branch_id` null) and that branch's members), `languages`
  (`enabled_languages` contains `ar` and `en`), `regulation` (answer recorded; if `yes`, an uploaded regulation
  exists — upload/import run present). `complete(Company): bool`. Reasons are translated keys naming what is
  missing (e.g. "Branch Hilla has no labour union member"). URLs to the existing Filament pages.
- **R3 Gate**: `CaseOpening::open` calls `CompanySetup::complete()` first and throws `TransitionBlocked` with
  `messages.setup.incomplete` + the list of missing item labels; nothing else changes. Drafts, reports and email
  intake are not gated.
- **R4 UI**: Filament admin dashboard widget `SetupChecklist` (visible to company admin / Platform Admin in tenant,
  hidden when complete, `$isLazy = false`); home: a card at the top for company admins while incomplete (via
  `MyWork`), HR staff see a short notice "Company setup is incomplete — the company admin must finish it" without
  links to admin pages. CompanySettings page gets the regulation question (radio yes/no; yes shows a link to the
  internal regulation page); saving records answer/time/user (audited).
- **R5 Tests**: each item missing → incomplete with its reason; complete company passes; gate refuses with the
  translated message and opens when complete; backfill for companies with cases; HR staff notice vs admin
  checklist; tenant isolation (another company's mandate/branches never count); Arabic rendering of reasons.

## R6 Plan review resolutions (Codex review + product owner, 2026-10-09) — override R1–R5 where they differ

### Blockers
- **A1 Invariant in the workflow**: the setup check lives in `CaseWorkflow`'s Draft → Open guard (so no code path can
  open a case on an incomplete setup); `CaseOpening::open` calls the same `CompanySetup` first to produce the
  user-facing message listing missing items. Test both paths.
- **A2 Letterhead per enabled language** (product owner: every language reviewed): for each language in
  `enabled_languages`, the latest letterhead version exists (same selection rule as `DocumentRenderer`), has the asset
  its `header_mode` needs (logo for `logo_text`, header image for `image`) and company name text, and is not
  `needs_review`. The reason names the language.
- **A3 Approver accounts** (product owner: at least one account per role): the pool of the order in effect today must,
  for every branch (company-wide + that branch's members), include in each of legal/hr/labor_union at least one member
  linked to an active user account of this company whose role may approve (reuse `PoolEligibility`'s account rules,
  e.g. reporters/department managers cannot be pool approvers); name-only members remain valid additional members.
  Since the three roles each need an account, the quorum's three-member, union-present requirement is satisfiable.

### Should-fix
- **B1** Coverage is checked against the single mandate selected exactly as `CommitteeSessionService` selects it
  (latest in effect today via `inEffectOn`), never mixing versions.
- **B2 Seeds**: update `PilotCompanySeeder` (and `LocalUsersSeeder`/demo seeders as needed) so the dev company is
  complete: letterheads reviewed with logos (generated placeholder images stored on the private disk), a regulation
  answer `no`, and one active approver account per role linked in the pool. The production gate is not weakened.
- **B3 Test blast radius**: a shared test helper `completeCompanySetup(Company $company): array` (letterheads,
  branch, mandate, pool with approver accounts per role, regulation answer) used by every test that opens a case
  through `CaseOpening` or the workflow (seven call sites incl. `aiOpenCase`, `EndToEndTest`'s ordering — mandate before
  opening — and `IntakeChecksTest`); tests that factory-insert already-open cases are unaffected.
- **B4** The spec's "existing companies keep working" is narrowed: only the regulation answer is derived; all other
  items still apply to new openings. Test: a company with prior cases but no current mandate cannot open a new case.
- **B5 Regulation evidence**: "yes" is complete when a regulation upload exists whose import is not `failed` or
  `cancelled` (pending/in progress/completed/awaiting lawyer approval all count) — it has been routed to the lawyer's
  approval queue by `RegulationUpload`; test a failed import → incomplete.
- **B6 Platform Admin**: inside a company (Filament tenant) the dashboard widget shows; the workspace home stays as
  today for Platform Admin (they land on the platform panel) — spec clarification narrowed accordingly.
- **Nits**: branch jurisdiction and ar/en languages are kept as checklist items for legacy data visibility; fix links
  are tenant-aware Filament URLs (settings, document templates/letterheads, branches, administrative orders, company
  users, internal regulation) and are shown only to company admins / Platform Admin.

## Constitution Check
| Principle | Status | How |
|---|---|---|
| I. Tenancy | PASS | all checks scoped to the company |
| II. AI boundary | PASS | no AI |
| III. Audit | PASS | answer recorded with user/time via audited model |
| IV. Tests | PASS | R5 |
| V. Thin UI | PASS | CompanySetup service; widget/card render |
| VI. Decided | PASS | CLAUDE.md §23.5; defaults in spec clarifications |

## Structure
```text
database/migrations/2026_10_20_000000_add_regulation_answer_to_companies.php
app/Services/Companies/CompanySetup.php · app/Services/Cases/CaseOpening.php
app/Filament/Admin/Widgets/SetupChecklist.php (+ view) · app/Filament/Admin/Pages/CompanySettings.php
app/Services/Home/MyWork.php + resources/views/livewire/home.blade.php · lang/* · tests/Feature/Setup/*.php
```

## Delegation
Codex plan review → run G → my gates + browser check (new company in dev: checklist, gate, completion) → PR.
