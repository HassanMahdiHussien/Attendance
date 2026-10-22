# Feature Specification: OCR-First Internal Regulations

**Feature Branch**: `031-ocr-first-regulations`

**Created**: 2026-10-14

**Status**: Draft

**Input**: Product owner approved plan (2026-10-14), step 4: importing a company's internal regulation should not pay for an AI reading of every page. Read it locally first (PDF text layer or Tesseract OCR); call the AI only for pages the local reading is not confident about. Word and plain-text uploads already need no AI.

## Clarifications

### Session 2026-10-14

- Q: Does this change the two-reading rule for built-in laws (CLAUDE.md §23.2)? → A: No. Statutes keep two independent readings for every unit. The exception below applies only to company internal regulations (`laws.kind = internal_regulation`).
- Q: What replaces the second reading for a confident regulation page? → A: The company's own lawyer (§23.4), who must already approve every regulation article before it is citable. For a single-reading article the lawyer approves it individually, with a link to the page image shown next to the text; "approve all" skips these articles. Recorded as ADR 0011.
- Q: When is a page "confident"? → A: (a) a usable PDF text layer (existing `TextLayerProbe`), or (b) Tesseract OCR whose mean word confidence on the page is at least `displanery.legal_library.ocr_confidence_min` (default 85). Anything else gets the AI reading as today (model/effort/batch settings from feature 030; regulations default to reading "now").
- Q: New status? → A: `verified_single` — extracted from one confident local reading, not compared. It counts as verified for building and correction purposes but a regulation unit is only citable after lawyer approval (existing rule), and approval of a `verified_single` unit is one-by-one.
- Q: Mixed pages in one article? → A: An article touching any non-confident page follows the normal two-reading path (AI reading for those pages, comparison, mismatch queue). Only articles whose pages are all confident become `verified_single`.
- Q: Word/text uploads? → A: Unchanged (`verified_text`, no AI).

## User Scenarios & Testing *(mandatory)*

1. **Given** a scanned regulation PDF whose pages OCR at ≥ 85 confidence, **When** HR uploads it, **Then** no Claude call is made, all articles are `verified_single`, and the lawyer approves each with the page image available; "approve all" leaves them pending.
2. **Given** a scan where page 4 OCRs at 60, **Then** only page 4 gets an AI reading; articles on page 4 are compared as today; the rest are `verified_single`.
3. **Given** a digital PDF with a usable text layer, **Then** no AI call is made and articles are `verified_single`.
4. **Given** a built-in statute import, **Then** behaviour is unchanged (two readings on every page).
5. **Given** unapproved `verified_single` units, **Then** they are not citable; after individual lawyer approval they are.

## Requirements *(mandatory)*

- **FR-001**: `PdfPages` returns OCR text plus mean word confidence (Tesseract TSV); stored per page (`legal_import_pages.ocr_confidence`, nullable decimal) with `text_method`.
- **FR-002**: For internal-regulation runs, a page is confident when its text-layer reading is usable or its OCR confidence ≥ the configured minimum; confident pages set `ai_status = 'skipped'`, `ai_source = null` and queue no AI reading (now or batch). Statute runs ignore this.
- **FR-003**: `UnitBuilder` creates `verified_single` for articles whose pages are all confident (`ai_status = skipped`), using reading A only; other articles keep the existing comparison.
- **FR-004**: New `LegalUnitStatus::VerifiedSingle`; included wherever the code lists verified statuses for building, correction and archive purposes, with the same immutability triggers/guards as other verified statuses; citability for regulations still requires lawyer approval.
- **FR-005**: `RegulationApprovals::approveAllVerified` excludes `verified_single`; `approve` accepts it individually; the approval screen labels such articles ("single reading — check against the page image") and shows the page image link; audit log records `method = single_reading` on approval.
- **FR-006**: Import counts and the run page show how many pages were read locally vs. by AI.
- **FR-007**: Config `LEGAL_OCR_CONFIDENCE_MIN` (default 85). WEIRDNESS line + ADR 0011.

## Success Criteria

- A clean scanned or digital regulation imports with zero AI calls.
- No built-in statute behaviour changes (existing tests pass unchanged).
