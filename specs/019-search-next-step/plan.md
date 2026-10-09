# Implementation Plan: Global Search, Remembered Filters and the Case "Next Step"

**Branch**: `019-search-next-step` | **Date**: 2026-10-09 | **Spec**: [spec.md](./spec.md)

## Summary
A `GlobalSearch` service with role-scoped providers, normalised search columns for names, a Livewire search component
in the workspace top bar, per-user remembered board filters, and a "Next step" card on the case page driven by the
existing `NextAction` service.

## Technical Context
Laravel 13 · Livewire 4 · MySQL 8.4 · existing `CaseVisibility`, `ViolationReportVisibility`, `EmailDraftVisibility`,
`EmployeeProfilePolicy`, `NextAction`, `Tone`, `Ui`, `x-ui` components · one migration · no new packages.

## Research (decisions)
- **R1 Normalisation** `App\Support\Search\SearchText::normalize(string): string`: lower-case Latin; strip Arabic
  diacritics and tatweel; أ إ آ ٱ → ا; ة → ه; ى → ي; ؤ → و; ئ → ي; Arabic-Indic and Persian digits → Western;
  collapse whitespace. Applied to queries and to stored search columns.
- **R2 Stored search columns** (migration): `employees.search_name` (normalised Arabic + English name),
  `email_drafts.search_subject`, `violation_reports.search_text` (violation type + place); maintained in model
  `saving` hooks; backfilled in the migration in chunks (through models so tenant rules apply, or raw per company with
  the same normaliser). Indexes: `(company_id, search_name)`, `(company_id, employee_code)`, `(company_id,
  case_number)` (if missing), `(company_id, search_subject)`. Case number and employee number matched by normalised
  digits/prefix; names by `LIKE '%term%'` on the normalised column (bounded by company index; fine at stated scale).
- **R3 Providers** (`App\Services\Search\GlobalSearch::for(User, string $q): array` → groups):
  cases — `CaseVisibility::for($user)` joined to employees, matching case number / employee search_name / employee
  code; employees — only for roles that may view employee pages (`EmployeeProfilePolicy::viewAny`) within their scope;
  reports — HR inbox (`ViolationReportVisibility::inbox` + `EmailDraftVisibility::inbox`); reporters/department
  managers — own reports/email drafts only (bare status, no case link); committee/reviewer — cases via
  `CaseVisibility` only. 8 per group, ordered exact case number / code first, then name match.
- **R4 UI** `App\Livewire\GlobalSearch` included in the workspace layout top bar: input with combobox ARIA, 250 ms
  debounce, results listbox grouped with headings, arrow/Enter/Escape via Alpine, Ctrl/⌘ K focuses it; phone: search
  icon in the compact top bar opens a full-screen sheet with the same component. Empty/no-result states. All text
  translated.
- **R5 Remembered filters**: `users.preferences` JSON column (migration; nullable) holding `board_filters`. Board
  `mount()` restores them when the request has no filter query parameters; `updated()` saves; chips with remove
  buttons and "Clear filters". (Per-user DB rather than session so it follows the user across devices.)
- **R6 Next-step card**: `ShowCase` computes `NextAction::for($case, auth()->user())`; view shows `<x-ui.card>` at the
  top (indigo border) with label, actor line ("You" / "Committee" / "Legal reviewer" / "Waiting on …"), due date +
  deadline badge, and one button: on-page actions use anchors (`#handoff`, `#response`, `#decision-signatures`,
  `#consultation`, `#appeal`, `#documents`) added to the existing sections; screen actions use the action URL.
  Gray "waiting" actions have no button. Archived → no card. Behaviour inventory updated (anchors only; no binding
  changes).
- **R7 Tests**: normaliser table; search per role (HR scoped/all, reporter own only, committee visible only, reviewer,
  partner none or as allowed), tenant isolation, Arabic variant and digit matching, min length, caps; filters
  remembered/restored/cleared and URL precedence; card per representative status matches `MyWork` for the same viewer;
  query-count bound for search.

## Constitution Check
| Principle | Status | How |
|---|---|---|
| I. Tenancy | PASS | providers only use existing visibility classes; tenant tests |
| II. AI boundary | PASS | no AI |
| III. Audit | PASS | read-only features; preferences are user settings |
| IV. Tests | PASS | R7 |
| V. Thin UI | PASS | GlobalSearch service + NextAction; components render |
| VI. Decided | PASS | product owner chose these; mockup approved |

## Structure
```text
database/migrations/2026_10_19_000000_add_search_columns_and_preferences.php
app/Support/Search/SearchText.php · app/Services/Search/GlobalSearch.php · app/Livewire/GlobalSearch.php (+ view)
models: Employee, EmailDraft, ViolationReport (saving hooks), User (preferences cast)
app/Livewire/Cases/{Board,ShowCase}.php + views · resources/views/layouts/workspace.blade.php
lang/* · tests/Feature/Search/*.php · tests/Feature/Cases/NextStepCardTest.php
```

## Delegation
Codex plan review → run S (all) → my gates + browser check (search EN/AR, phone sheet, card) → PR.
