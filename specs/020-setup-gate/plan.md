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
