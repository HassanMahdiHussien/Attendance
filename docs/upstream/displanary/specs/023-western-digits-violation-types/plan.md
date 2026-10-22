# Implementation Plan: Western Digits Everywhere and a Managed Violation-Type List

**Branch**: `023-western-digits-violation-types` | **Spec**: [spec.md](spec.md)

## Technical context

Laravel 13 / PHP 8.4 / MySQL 8.4 / Livewire 4 / Filament 5 / Pest, Sail. Tenancy: `company_id` + composite
same-company foreign keys + `TenantContext` + `BelongsToCompany`-style model guards (see `Location`). Translation
coverage and UI behaviour inventory tests guard new strings/controls.

## Design

### A. Digits (FR-001, FR-002)
- `App\Support\Format\Ui::number` → returns the value unchanged in every locale; `Ui::date` Arabic branch drops the
  `ArabicDigits::convert` call (keeps the Iraqi month names). `ArabicDigits` is removed if nothing else uses it.
- Documents already render Western digits from facts/`IraqiDate`. Add a normaliser
  (`App\Support\Text\ArabicText::westernDigits()` — reuse the digit map already in `ArabicText` rather than a new
  helper) and apply it where draft body text is stored before review: the AI draft job's write of
  `document_contents` and the human edit/manual-write save paths. Never applied to locked (issued) documents.
- `App\Ai\DraftPrompt`: one instruction line — use Western digits (0-9) for every number in every language.
- Livewire/JS: nothing formats digits client-side today; verify with a grep for `toLocaleString`/`Intl`.

### B. Violation types (FR-003 … FR-012)
- Migration: `violation_types` (id, company_id, `names` JSON `{lang: name}`, is_active, sort_order, timestamps;
  unique (company_id, id) for composite FKs). `cases.violation_type_id`, `violation_reports.violation_type_id`
  nullable with composite FK (company_id, violation_type_id) → violation_types(company_id, id).
- Model `ViolationType` (tenant model like `Location`) with `name(string $lang)` fallback lang → ar. Factory.
  Add to `CompositeForeignKeyTest` model map.
- `App\Support\Format\Violation::name(DisciplinaryCase|ViolationReport|object $row, ?string $lang = null)` — list name
  in `$lang ?? app()->getLocale()` → Arabic → free text. Used by every view/service listed in FR-005 (grep
  `violation_type` in `app/` and `resources/views`).
- Forms: `SubmitReport` and `CreateCase` get a select of active types plus the existing text input as fallback
  ("Other — describe"); validation: one of the two required. `CaseDrafts`/`CaseOpening` carry `violation_type_id`
  from report → case.
- Mapping (FR-008): on the case page (HR only, when `violation_type_id` is null) a small "Map to a violation type"
  select; service method `CaseViolationTypes::map(case, type, user)` writes the id and a `violation_type_mapped` case
  event (old text, type id). Same on the HR inbox for draft reports.
- Filament: `ViolationTypeResource` in the company admin panel (cluster/group next to Locations), Arabic + English
  name fields required plus one optional field per extra enabled language; deactivate instead of delete when used;
  audit-log create/update.
- Grouping (FR-006): `Hotspots` and `ReportBuilder` group key = `vt:<id>` or `txt:<normalised text>`; label via the
  helper in the export's locale.
- Checks (FR-010): repeat-violation and duplicate checks compare ids when both set, else existing text logic
  (`CaseInsights`, `CaseWorkflow`).
- AI (FR-010): `PayloadBuilder`/`CandidateUnits` use the Arabic name when mapped.
- Search (FR-009): `GlobalSearch` also matches `violation_types.names` (JSON search on the company's types → ids).
- Seeders: starter list (e.g. Fight/شجار, Lateness/تأخر عن الدوام, Absence without leave/غياب بدون إذن,
  Safety rule breach/مخالفة تعليمات السلامة, Misuse of company property/إساءة استخدام ممتلكات الشركة,
  Insubordination/عدم الامتثال للتعليمات, Other); demo cases mapped.

## Tests
- Digits: Arabic locale renders `Ui::number(1234)` = "1234"; a rendered Arabic page (board/case/employee) and a PDF
  report contain no `[٠-٩۰-۹]`; AI draft and manual save store Western digits; a locked document's content unchanged.
- Violation types: English viewer sees the English name on board/case/report export; unmapped free text shown as-is;
  mapping writes the event; hotspots group by id; cross-company type rejected (composite FK test); deactivated type
  not offered; used type cannot be deleted; AI payload carries the Arabic name.
- Full suite + Pint + translation coverage + behaviour inventory updated.

## Constitution check
Single source of truth (one list, one helper); tenant scoping via composite FK; no AI facts (names come from the list);
immutability preserved (issued docs untouched). No new packages.

## Plan review resolutions (Codex, 2026-10-09) — these override the sections above

- **R1 (blocker 1 / Q1):** `violation_type` stays NOT NULL. When a list type is selected, the form stores the type's
  Arabic name as a snapshot in `violation_type` *and* the id; for "Other" only the typed text. Existing guards keep
  working. Display uses the current list name (viewer's language) whenever the id is set; the snapshot is the
  historical record.
- **R2 (blocker 2):** normalise digits at the service boundaries `DocumentDrafts` and `RecommendationsEditor`
  (both `body` and `secondary_body`), before change detection, approval invalidation and `CitationSync`. Normalise
  the AI response before `DraftValidator`'s numeric comparison; keep the raw response in `ai_generation_logs`.
  Digit-only edits still count as edits (approvals reset). Use a digit-only `ArabicText::westernDigits()` — never
  `normalize()` on legal prose.
- **R3 (blocker 3):** additionally normalise visible digits when building the new frozen HTML at issuance (facts,
  reference numbers, citation renderings, prior-record lines). Existing frozen HTML/contents/PDFs untouched. Tests:
  Arabic + English pages, a manually entered Arabic-Indic reference number, a citation.
- **R4 (blocker 4):** mapping a draft report is an audited report action (audit log); mapping a case writes the
  `violation_type_mapped` case event.
- **R5:** Hotspots threshold grouping, threshold links and `CaseMetrics::thresholdQuery()` all use the same key
  (`vt:<id>` / `txt:<normalised text>`); HR-partner aggregate path unchanged.
- **R6 (Q2):** repeat banner: same type when both cases have ids and they match; otherwise compare normalised text
  (the snapshot makes mapped cases comparable to legacy text). The 48-hour duplicate check is unchanged (it never
  compared types).
- **R7:** search: mapped names (all languages) added to case and report queries, free text retained, existing
  `CaseVisibility`/inbox scopes applied first; report `search_text` includes the names.
- **R8:** display sites per review B4, including `CaseInsights` prior banner, `DocumentFacts` prior records (Arabic
  name on the Arabic page, viewer-language name on other language pages), `MyWork`, `GlobalSearch`, both
  `ReportBuilder` case lists, `Hotspots`, employee/inbox/case/consultation/related/drilldown views;
  `ReportService::convert()` carries the id. Board/My cases unchanged.
- **R9:** `ViolationTypePolicy` with the same access as `LocationPolicy` (company admin + HR staff who manage
  locations); tests for allowed role, denied role and cross-company create/update/map. Service-level validation of
  names and ids; inactive types excluded from new selections, existing references kept.
- **R10:** update `tests/Unit/ArabicDigitsTest.php` (remove or invert).
