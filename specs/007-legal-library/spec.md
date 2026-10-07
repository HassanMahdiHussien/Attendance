# Feature Specification: Legal Library Import

**Feature Branch**: `007-legal-library`

**Created**: 2026-10-07

**Status**: Draft

**Input**: User description: "Legal library import (CLAUDE.md §23, §22, §5, §19). The Platform Admin
imports the built-in laws from docs/laws/ (Law 37/2015 federal — broken text layer, read from page
images; Law 71/1987 Kurdistan — text layer works but drops letters; Law 18/2023 inside Gazette 4734 —
only a page range). Split into atomic units (article, clause) with number, clause path, exact text and
source page. Two independent readings per unit (text layer or OCR; AI reading of the page image),
compared after normalisation: identical → verified automatically; different → a mismatch queue where a
human compares both readings with the page image and fixes the text. Only verified units are citable.
Platform Admin manages statutes (versions, repeal, jurisdictions); fixes create new versions. Company
internal regulations go through the same pipeline, scoped to the company, approved article by article
by the company's own lawyer. Drafting, retrieval and citation checks are feature 008."

## Clarifications

### Session 2026-10-07

- Q: OCR for Law 37/2015 (broken text layer)? → A: Product owner approved adding an Arabic OCR engine
  and PDF tools to the runtime image, so the two readings stay independent (OCR + AI vision).
- Q: AI key? → A: The product owner adds the AI key later. Until then the import runs the first reading
  only and shows "AI reading unavailable"; no unit can become verified without both readings (or a
  human fix). Tests never call the real service.
- Q: Who resolves mismatches? → A: Statutes: Platform Admin only (CLAUDE.md §23.1b). Company
  regulation: a company user with the "review internal regulation" permission.
- Q: Granularity of units? → A: Every article is a unit, and every clause (أولاً/ثانياً…, then أ/ب…) is
  also its own unit under that article, so a later citation can point at either the whole article or
  one clause.
- Q: Text and Word uploads (no page image)? → A: The uploaded text is itself exact, so there is no
  second reading to compare; units are marked "verified from text source" and still require the
  company lawyer's approval before they are citable.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Platform Admin imports a built-in law (Priority: P1)

The Platform Admin opens a law in the library (the three built-in laws already exist without text),
chooses its source file and, for a gazette that contains several laws, the page range that belongs to
this law, then starts the import. The import runs in the background and shows its progress (pages read,
units found, verified, in the mismatch queue, failed). When finished, the law shows how many articles
and clauses were found and how many are citable.

**Acceptance Scenarios**:
1. **Given** Law 71/1987 imported with both readings available, **When** a unit's two readings are
   identical after normalisation, **Then** it is "verified automatically" and citable.
2. **Given** a unit whose readings differ (e.g. a dropped letter in the text layer), **Then** it is
   placed in the mismatch queue and is not citable.
3. **Given** Gazette 4734 with the page range of Law 18/2023, **Then** only units from those pages are
   created for Law 18/2023.
4. **Given** the AI reading is unavailable (no key, outage after retries), **Then** the import completes
   the first reading, marks the law "AI reading pending", creates no verified unit, and offers "retry AI
   reading" later without repeating the first reading.
5. **Given** an import is running, **When** another import of the same law is started, **Then** it is
   refused with a clear message.

### User Story 2 - A human clears the mismatch queue (Priority: P1)

The Platform Admin opens the mismatch queue: each item shows the article / clause, the two readings with
their differences highlighted, and the original page image side by side. They choose one reading, or
edit the text, and confirm; the unit becomes "verified by a person" with who and when recorded. They can
also mark an item "not a real unit" (a heading or page noise) so it is excluded.

**Acceptance Scenarios**:
1. **Given** a mismatch item, **When** the admin confirms a corrected text, **Then** the unit becomes
   citable with status "verified by a person", and the audit log records the admin, the time and both
   original readings.
2. **Given** a unit that is already verified and was cited, **When** the admin later corrects its text,
   **Then** a new version is created, the old version stays unchanged and every existing citation still
   points at the old version.
3. **Given** a company user (any role), **Then** the statute mismatch queue and statute editing are not
   available to them.

### User Story 3 - Platform Admin manages statutes (Priority: P2)

The Platform Admin can add a new law (name, number, year, category, source file), upload a new version of
an existing law, set its effective date, mark it repealed with a date, and choose which jurisdictions it
applies to (federal Iraq, Kurdistan Region, or both). A repealed or superseded law stays in the library
for old cases but its units are no longer offered for new citations.

**Acceptance Scenarios**:
1. **Given** Law 18/2023 applying to both jurisdictions, **When** the admin removes Kurdistan, **Then**
   its units are no longer citable for Kurdistan branches; existing citations are unchanged.
2. **Given** a law marked repealed on a date, **Then** its units are not citable for cases opened after
   that date, and remain visible on older cases.

### User Story 4 - A company uploads its internal regulation and its lawyer approves it (Priority: P2)

A company admin uploads the company's Ministry-certified internal regulation (plain text, Word, or PDF —
text or scanned). It goes through the same split and readings. The company user with the "review
internal regulation" permission resolves any mismatches and approves the units article by article (or
all verified units at once). Only approved units are citable, and only for that company.

**Acceptance Scenarios**:
1. **Given** an uploaded scanned regulation, **Then** units are created with two readings and mismatches
   are queued for the company's reviewer, not the Platform Admin.
2. **Given** units approved by the company reviewer, **Then** they are citable for that company only;
   another company's users and the AI retrieval for another company never see them.
3. **Given** a company user without the review permission, **Then** they can upload (if company admin)
   but cannot approve or edit units.
4. **Given** a new version of the regulation is uploaded, **Then** the old units stay for existing
   citations and the new units start unapproved.

### Edge Cases

- A page that fails permanently: units spanning it are never verified automatically (mismatch, reason shown).
- A gazette page range: the first and last pages are shown before import and the admin confirms the law's start and end.

- A page whose OCR or AI reading fails individually: that page is marked failed and retried; other
  pages continue.
- Articles split across pages: a unit keeps the page where it starts and records that it continues.
- Article numbers written in words (المادة الأولى) or digits (المادة 1 / المادة ١) are recognised as the
  same article number.
- A reading finds an article the other reading does not: the unit goes to the mismatch queue as
  "found in one reading only".
- Repeated imports of the same unchanged file do not duplicate units: units are matched by law, article
  and clause, and an unchanged verified unit is kept as is.
- Very large files: the import is limited to a configurable page count per file (default 300) and file
  size (default 50 MB).
- A Word file with tracked changes or comments: only the accepted text is read.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The system MUST split a law source into units: one per article and one per clause under an
  article, each with the law, article number, clause path (e.g. `43/ثانياً/ز`), exact text and source
  page number.
- **FR-002**: For PDF sources the system MUST produce two independent readings per unit: (a) the PDF text
  layer when it is usable, otherwise OCR of the page image; (b) an AI reading of the page image.
- **FR-003**: The system MUST decide per law (and allow the Platform Admin to override) whether the text
  layer is usable; Law 37/2015 MUST use OCR.
- **FR-004**: The system MUST compare the two readings after normalisation (compatibility forms, Arabic-
  Indic and Western digits unified, tatweel, diacritics and whitespace removed) and mark identical units
  "verified automatically" and different ones "mismatch".
- **FR-005**: Only units in a verified state (automatic, by a person, or from a text source) — and, for a
  company regulation, approved by the company reviewer — MUST be citable. Unverified, mismatched,
  excluded, superseded units and units of repealed or non-applicable laws MUST NOT be citable.
- **FR-006**: Every unit read from a PDF MUST link to the page image it was read from (text and Word units show their source text instead); page images are stored privately
  and shown only to users allowed to see that law (statutes: any signed-in internal user; regulation:
  that company's users and the Platform Admin).
- **FR-007**: The mismatch queue MUST show both readings with differences highlighted and the page image,
  and let the authorised person pick a reading, edit the text, or exclude the item; the result and both
  original readings MUST be recorded in the audit log.
- **FR-008**: Correcting a unit that has been verified MUST create a new version and leave the previous
  version and its citations untouched.
- **FR-009**: Only the Platform Admin MAY create, import, version, repeal or edit statutes or set their
  jurisdictions; companies MUST NOT be able to change statutes through any route.
- **FR-010**: Law applicability to jurisdictions MUST be many-to-many and editable without code; Law
  18/2023 applies to both jurisdictions by default.
- **FR-011**: Imports MUST run in the background, report progress, be resumable per page, refuse a second
  concurrent import of the same law, and allow retrying only the AI reading.
- **FR-012**: When the AI reading is unavailable, the system MUST say so clearly ("AI reading
  unavailable — retry later"), keep the first reading, and create no verified unit from a single
  reading.
- **FR-013**: A company admin MUST be able to upload the internal regulation as text, Word or PDF; the
  same pipeline applies; text and Word sources are marked "verified from text source".
- **FR-014**: Regulation mismatches and approvals MUST be handled by company users with the "review
  internal regulation" permission; approvals are per unit, with an "approve all verified" action, and
  are recorded (who, when).
- **FR-015**: Regulation units MUST be scoped to their company at the data layer and never returned for
  another company.
- **FR-016**: The library MUST let the Platform Admin and authorised users browse a law: articles in
  order, each clause, status, version history and page link.
- **FR-017**: The data sent to the AI for reading MUST be the page image only (statute or regulation
  page), never case or employee data.
- **FR-018**: All library changes (import started/finished, unit verified, corrected, excluded, approved,
  law repealed, jurisdiction changed) MUST be written to the append-only audit log.

### Key Entities

- **Law**: a statute (platform-wide) or a company's internal regulation; name, number, year, category,
  source file, effective date, repeal date, version, jurisdictions (statutes), approval state.
- **Legal unit**: an article or a clause of a law version; article number, clause path, official text,
  source page, the two readings, status (unverified / mismatch / verified automatically / verified by a
  person / verified from text source / excluded / superseded), version links, who verified and when;
  for regulations, who approved and when.
- **Import run**: one import of a law source; page range, text-layer decision, per-page progress and
  errors for each reading, overall state.
- **Page image**: the rendered image of one source page, privately stored, linked from units.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: All three built-in laws can be imported end to end; every article of each law appears in
  the library with its page link.
- **SC-002**: 100% of units shown as citable have either two matching readings or a recorded human
  verification (or a text source plus, for regulations, the company reviewer's approval).
- **SC-003**: A reviewer can resolve a typical mismatch item (compare, fix, confirm) in under 1 minute.
- **SC-004**: An import of a 90-page law completes in under 30 minutes, and a failure on one page does not
  stop the others.
- **SC-005**: No company user can read another company's regulation or change any statute, verified by
  automated tests across every route.
- **SC-006**: Correcting a cited unit never changes the text of any existing citation or issued document.

## Assumptions

- The three built-in laws already exist as rows (seeded) without units; the import fills them.
- Units are created per article and per clause; sub-clause depth beyond two levels (أولاً → أ) is kept
  inside the clause text.
- Page images are rendered at a resolution readable by both OCR and the AI (about 200–300 dpi).
- The AI key is added by the product owner later; all tests use a fake AI reader.
- The effective dates of the seeded laws are confirmed by the product owner later and set in the admin
  screen.
- Retrieval of candidate units, the citation markers, the two citation checks and the law-vs-regulation
  conflict view belong to feature 008 (AI drafting). The existing citation gate keeps using the
  verified/approved rules defined here.
- Kurdish-language law text is out of scope; the built-in laws are Arabic.
