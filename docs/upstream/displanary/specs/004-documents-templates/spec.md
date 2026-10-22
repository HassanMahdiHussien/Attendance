# Feature Specification: Document Templates and PDF Generation

**Feature Branch**: `004-documents-templates`

**Created**: 2026-10-05

**Status**: Draft

**Input**: User description: "Build the three official documents (defense request, committee
recommendations, decision letter: warning / termination) on the pilot company's attached format
(docs/document-templates.md, samples in docs/attachments/, local only), with each company's own logo
and name. Each company can customise its templates if it needs to. Product owner decisions: fixed
layout plus per-company editable letterhead, text blocks, signer titles and options per language,
versioned (no free HTML); Iraqi month names; Browsershot + Chromium + embedded OFL fonts approved."

## Clarifications

### Session 2026-10-05

- Q: How much can a company customise? → A: Letterhead (logo, name, address lines, contact, footer)
  and text blocks / labels / signer titles / options, per document type and language. The layout
  skeleton stays fixed. Every change is a new version; issued documents keep what they printed.
- Q: Date style? → A: Iraqi month names (كانون الثاني … كانون الأول), day – month – year, as in the
  samples: `18 – حزيران – 2026`; English `18 – June – 2026`.
- Q: PDF engine? → A: Browsershot with Chrome headless shell in the Docker image and embedded OFL
  fonts (Amiri, Noto Naskh Arabic, Noto Sans Arabic). Approved 2026-10-05.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - HR prints an official bilingual document in the pilot company's format (Priority: P1)

For any case document (defense request, recommendations, decision letter), HR gets one PDF: the Arabic
page first, then English (then any extra enabled language). It carries the company's logo and letterhead
and the reference number and date, with every fact pulled from the database (employee, hire date,
position, deadlines, mandate, attendees, General Manager, union representative) and the body text
written for this case. It looks like the pilot company's real letters.

**Why this priority**: printing the official paper is the product's core output.

**Independent Test**: For a seeded case, issue each document type and open the PDF: compare the layout
with the samples and check every placeholder is filled, the dates are Iraqi style, the pages are
right-to-left Arabic then left-to-right English, and the logo is shown.

**Acceptance Scenarios**:

1. **Given** an issued defense letter for a male employee, **Then** the Arabic page reads "عزيزي السيد …،
   أنت تعمل مع الشركة منذ {hire date} …", the response deadline is the computed date (issue + 3 days),
   and the employee block (name / department / signature lines) is at the bottom.
2. **Given** a female employee, **Then** the feminine forms are used (عزيزتي السيدة، أنتِ تعملين …،
   وأنتِ تعرفين …; English "Dear Ms …").
3. **Given** a recommendations document, **Then** it cites the Administrative Order number and date in
   effect, the session date, and lists the attendees with their role labels (Legal attendee: chair;
   others: member).
4. **Given** a warning decision letter, **Then** the defense outcome sentence matches the recorded
   defense acceptance, the General Manager and union representative signature blocks are side by side,
   and the employee acknowledgment lines follow.
5. **Given** a company with Kurdish enabled, **Then** a Kurdish page follows, using a font that covers
   Sorani letters (ڕ ڵ ۆ ێ ە ڤ).

---

### User Story 2 - A company adjusts its templates without breaking them (Priority: P1)

A company admin edits their company's letterhead per language (name, address lines, phone, email, logo,
footer text, or a full header image instead of logo + text) and, per document type and language, the
template's text blocks and labels using placeholders. They preview the result with sample data before
saving. Saving creates a new version; documents already issued are unaffected; a template that
references an unknown placeholder or misses a required block is refused.

**Why this priority**: the product owner requires per-company customisation; a broken template must
never reach a printed legal document.

**Independent Test**: Change the CC line and the opening paragraph, preview, save, issue a new document
(new text) and re-open an older issued PDF (old text unchanged); try an unknown placeholder.

**Acceptance Scenarios**:

1. **Given** no customisation, **Then** the company uses the system default templates (Arabic and
   English wording from the pilot samples, with the committee name unified as "اللجنة الانضباطية" /
   "Disciplinary Committee").
2. **Given** an edited block, **When** saved, **Then** a new template version exists, the previous one
   is kept, and the change is audit-logged.
3. **Given** a block containing `{employe_name}` (typo), **When** saved, **Then** it is refused, naming
   the unknown placeholder and listing the valid ones.
4. **Given** a document issued with template version 3, **When** the company saves version 4, **Then**
   the issued PDF and its stored text are unchanged.
5. **Given** HR staff (not admin), **Then** they can preview but not edit templates.

---

### User Story 3 - Drafts can be previewed before confirmation (Priority: P2)

Before issuing, HR sees what will print, except the provisional reference number and the watermark. The draft preview uses the same renderer with the
reference number shown as provisional, plus a clear "draft" watermark that never appears on the issued
PDF.

**Acceptance Scenarios**:

1. **Given** a draft, **Then** the preview shows the provisional reference number and a "مسودة / DRAFT"
   watermark.
2. **Given** issuance, **Then** the stored PDF has the final reference number, no watermark, and is
   generated once and kept (never regenerated with different data).

### Edge Cases

- An employee with no recorded gender: the document cannot be issued; the preview shows a clear
  missing-data warning. Existing employees are backfilled as "unspecified" and HR must set it.
- A missing required fact (e.g. no General Manager flagged, no union attendee) blocks issuance with a
  named reason instead of printing a blank.
- Body text with HTML or script content is printed as plain text (escaped); only a small formatting
  subset is supported (paragraphs, bullet and numbered lists, bold).
- A citation marker `[[cite:id]]` renders as the official article reference from the legal library; an
  unknown or unverified id blocks rendering.
- Very long bodies flow onto further pages; letterhead and footer repeat on each page.
- Logo upload: only PNG / JPG under 2 MB (no SVG).
- An extra language enabled with no template in that language: issuance blocked with a reason
  (no automatic translation of fixed wording).

## Requirements *(mandatory)*

### Functional Requirements

**Data**
- **FR-001**: Employees MUST have a gender (male / female; "unspecified" allowed only for legacy rows)
  editable in the employee screens; issuance requires male or female.
- **FR-002**: Companies MUST have a letterhead per language: company name, address lines, phone, email,
  footer text (default "CONFIDENTIAL"), optional header image; one shared logo.
- **FR-003**: Document templates MUST be stored per company, document type and language as immutable
  versions of named text blocks and options; when none exists the system default applies.
- **FR-004**: Each issued document MUST record which template and letterhead versions (per language)
  and logo hash it was rendered with, keep its frozen HTML, and keep its PDF permanently.
- **FR-004b**: Reference number and issue date MUST always print; prior-record paragraphs come only
  from the database; recommendations carry findings and recommendations as two body sections.

**Rendering**
- **FR-005**: One renderer MUST build the document HTML from: letterhead + template blocks with resolved
  placeholders + the document's per-language body + computed facts (dates, deadlines, reference number,
  mandate, attendees, signers), with `dir="rtl"` for Arabic/Kurdish pages and `dir="ltr"` for English,
  embedded fonts (Amiri / Noto Naskh Arabic for Arabic and Kurdish, a Latin serif for English), A4.
- **FR-006**: Placeholders MUST come from a fixed documented list; gendered wording uses a template
  syntax that selects masculine / feminine forms from the employee's gender.
- **FR-007**: Dates MUST use Iraqi month names in Arabic and English month names in English,
  day – month – year; digits Western (as in the samples).
- **FR-008**: Body text MUST be escaped and support only paragraphs, lists and bold; citation markers
  MUST render from the legal library and fail closed on unknown/unverified ids.
- **FR-009**: Draft previews MUST carry a watermark and a provisional reference number; issued PDFs never
  do.

**PDF**
- **FR-010**: At issuance the document HTML MUST be rendered and frozen inside the issuance transaction;
  a queued job converts only that frozen HTML to PDF (Browsershot), stores it once on the private local
  disk (temp file, verify, no-replace promotion, sha256), and sets path / sha / status once. Failures
  are retried, become a visible failed status with an HR alert and a retry command, and never undo
  the issuance.
- **FR-010b**: Issuance MUST pass the citation gate (research R8): every marker backed by an
  applicable, verified, relevance-checked (or overridden) citation record; no article number in prose
  outside a marker.
- **FR-011**: The issued PDF MUST be downloadable only by users who can view the case (CaseVisibility).

**Customisation UI**
- **FR-012**: Company admins (and the Platform Admin inside the company) MUST be able to edit letterhead
  and templates per language and document type, with a live preview on sample data, placeholder help,
  validation (unknown placeholders, required blocks), and version history; HR staff can only preview.
- **FR-013**: Template and letterhead changes MUST be audit-logged with before/after.

**Quality**
- **FR-014**: The fonts used MUST cover all Arabic and Sorani Kurdish letters; this is verified by an
  automated test on the font files.

### Key Entities

- **Employee** — gains gender.
- **Company letterhead** (per language) — name, address lines, phone, email, footer, header image.
- **Document template version** — company, document type, language, version, blocks (named texts),
  options, author, created at; immutable.
- **Document** — gains template/letterhead version references; existing contents (body per language)
  and pdf path.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: For the two pilot sample cases re-created as seed data (anonymised), the generated
  documents match the samples' structure block by block (checked against docs/document-templates.md).
- **SC-002**: 100% of placeholders in the default templates resolve for a complete case; a missing fact
  blocks issuance with a named reason in 100% of tested cases.
- **SC-003**: Issued PDFs are generated within 30 seconds of issuance on the dev stack and are
  byte-identical when re-downloaded.
- **SC-004**: A company admin can change the CC line and footer and preview the result in under
  2 minutes.
- **SC-005**: Font coverage test passes for all Arabic and Sorani letters.

## Assumptions

- The case screens (where HR writes or reviews the body and presses "issue") come in the next feature;
  here documents are created and issued through the existing services, and the renderer / preview /
  template editor are usable on their own.
- The body is supplied by AI (later feature) or manual writing; this feature only renders it.
- Kurdish default wording is not provided by the system (no lawyer-reviewed Kurdish text yet); a company
  enabling Kurdish must supply its Kurdish templates.
- The pilot samples stay local (git-ignored); the default wording is taken from them as documented in
  docs/document-templates.md, with the committee name unified per PRD §25.1.
