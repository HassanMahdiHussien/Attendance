# Feature Specification: Western Digits Everywhere and a Managed Violation-Type List

**Feature Branch**: `023-western-digits-violation-types`

**Created**: 2026-10-09

**Status**: Draft

**Input**: Product-owner feedback after testing (2026-10-09): "when I download report and My dashboard in English I see
report type in Arabic it must be English also" and "number must be in English for Arabic also".

## Clarifications

### Session 2026-10-09

- Q: Why do English reports show Arabic? → A: Every label is already translated; the Arabic text is the case's
  `violation_type`, which HR/reporters type as free text (in Arabic). Fix: a **managed violation-type list per
  company**, each type with a name per enabled language (Arabic and English required; further enabled languages such
  as Kurdish optional, falling back to Arabic). The report form and the case form pick a type from the list; the UI,
  dashboard, hotspots, reports and exports show the name in the viewer's/export's language.
- Q: Existing free-text values? → A: Kept as a fallback (like locations, CLAUDE.md §24.5). HR can map an existing
  free-text violation to a list type later; until mapped it shows as typed. Free text stays possible only as a
  fallback when no list type fits, exactly like `violation_place_freetext`.
- Q: Which digits? → A: Western digits (0-9) everywhere — every screen in both languages, reports, Excel/PDF exports
  and the **Arabic pages of printed documents** too (product owner: "UI and documents").
- Q: Already-issued documents? → A: Untouched (immutability, CLAUDE.md §3). The rule applies to documents rendered
  from now on; the AI drafting prompt asks for Western digits and new draft text is normalised to Western digits
  before review, so what HR approves is what prints.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - English viewer sees English violation names (Priority: P1)

**Acceptance Scenarios**:
1. **Given** a case with a list violation type named "شجار" / "Fight", **When** an English-language user views the
   board, case page, dashboard hotspots, employee page, search results, a report preview or a PDF/Excel export,
   **Then** they see "Fight"; an Arabic-language user sees "شجار".
2. **Given** a legacy case with only free text, **Then** the free text shows unchanged in both languages until HR maps
   it to a list type.
3. **Given** hotspots by violation type, **Then** cases are grouped by list type (all "Fight" cases together, whatever
   their legacy wording once mapped).

### User Story 2 - HR manages the list and picks from it (Priority: P1)

**Acceptance Scenarios**:
1. **Given** a company admin, **When** they open the admin panel, **Then** they can add, rename and deactivate violation
   types (no hard delete once used), with Arabic and English names required.
2. **Given** the reporter's form or HR's case form, **Then** the violation is chosen from the active list, with a
   free-text fallback.
3. **Given** a free-text violation on an existing case or draft, **Then** HR can map it to a list type; the mapping is
   recorded in the case events.
4. **Given** the AI drafting payload, **Then** it carries the Arabic name of the list type (Arabic governs) — or the
   free text when unmapped.

### User Story 3 - Western digits everywhere (Priority: P1)

**Acceptance Scenarios**:
1. **Given** the Arabic UI, **Then** case numbers, counts, dates, percentages and every other number show as 0-9.
2. **Given** a new defense letter / recommendations / decision letter, **Then** its Arabic page prints 0-9, including
   reference numbers, dates and article numbers; numbers typed by HR or produced by the AI with Arabic-Indic digits
   are shown as 0-9 in the draft HR reviews.
3. **Given** an already-issued document, **Then** its stored text and PDF are unchanged.

## Requirements *(mandatory)*

- **FR-001**: `Ui::number` and `Ui::date` return Western digits in every locale; no screen, report or export converts
  to Arabic-Indic digits.
- **FR-002**: Document rendering (Blade templates, `IraqiDate`, reference numbers) uses Western digits in every
  language. The AI drafting prompt instructs Western digits. Draft text saved from the AI or from a human edit is
  normalised to Western digits (Arabic-Indic and Extended Arabic-Indic) before it is stored for review; issued text is
  never rewritten.
- **FR-003**: New tenant table `violation_types` (company-scoped): names per language (extensible — not fixed columns
  per language, matching the `enabled_languages` principle), `is_active`, sort order. Arabic and English names are
  required; other enabled languages optional and fall back to Arabic.
- **FR-004**: `cases.violation_type_id` and `violation_reports.violation_type_id` (nullable, same-company composite FK).
  The existing `violation_type` text column is kept as the free-text fallback and historical record.
- **FR-005**: One display helper resolves a case's/report's violation name for a given language: list type name in
  that language → Arabic name → free text. Every place that shows `violation_type` today uses it (board, case page,
  drilldown, employee page, inbox, consultation review, search, My work, dashboard hotspots, statistical reports and
  exports).
- **FR-006**: Hotspots and reports group by `violation_type_id` when set, by normalised free text otherwise.
- **FR-007**: Company admins manage the list in Filament (create, rename, deactivate, reorder); a used type cannot be
  hard-deleted. Changes are audit-logged. Rename changes display everywhere but issued documents keep their printed
  text.
- **FR-008**: HR can map a free-text violation (case or draft report) to a list type; recorded as a case event with
  the old text and the chosen type.
- **FR-009**: Search matches both the list names (all languages) and the free text.
- **FR-010**: AI payloads (touchpoints 1 and 3) send the Arabic list name, or the free text when unmapped. The
  Duplicate-report and repeat-violation (same violation type within 12 months) checks use the type id when both cases
  have one, else the existing text comparison.
- **FR-011**: The pilot/demo seeders create a starter list (Arabic + English) and map the demo cases to it.
- **FR-012**: Company setup (feature 020) does not newly require the list; an empty list simply means free text only.

## Success Criteria

- An English-language user sees no Arabic violation names for mapped cases on any screen or export.
- No Arabic-Indic digit appears in any screen, export or newly rendered document (test asserts the absence).
- Existing tests stay green; issued documents' stored text/PDF hash unchanged.
