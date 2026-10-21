# Feature Specification: Site-wide readability pass

**Branch**: `058-readability-pass` (stacked on `057-open-case-from-report`) | **Created**: 2026-10-18 | **Status**: Done

**Input**: Product owner: "UI not easy to read, we need to check all website to be easy to read." Method: the existing design-audit crawl (`npm run audit:design`: 7 roles × Arabic/English × desktop/phone, 480 pages), a rendered-page readability probe (text under 12px, WCAG AA contrast, lines over ~95ch, left-pinned English in Arabic pages), the Impeccable detector, and a visual review of every daily screen.

## Findings fixed
- **FR-001 Arabic letter-spacing**: tracked chips and table headers broke Arabic joining («ج د يد», «تشرين ا ل ث ا ن ي»). Arabic pages reset letter-spacing everywhere (workspace and admin themes); the mono font falls back to Readex Pro so Arabic month names never render in a system font.
- **FR-002 Case page length**: about 6,000 px for a long case. Actions and documents now follow the stage directly; related cases move below attachments with the link form folded; the timeline shows the latest 8 events with the rest folded; per-document correction controls fold.
- **FR-003 Reports inbox**: each card repeated the description three times and carried controls now handled on the open-case screen. A card is the shared report row (what, who, place, date, status), reporter and sent time, the approved summary (capped measure), one "Convert to case" (secondary: repeated list action) and folded "More actions" (link to an existing case, map violation type, dismiss).
- **FR-004 Reporter home**: bare "Report 3 · Received" rows now use the shared report row (latest 5).
- **FR-005 Home activity**: the run-on line becomes the event, then case · person · time on a quieter line.
- **FR-006 Dashboard drill-down**: the employee-page link sat outside its card; the case number was a tiny trailing digit.
- **FR-007 Mixed direction**: committee minutes and the legal review note get `dir="auto"` (English sentences no longer jump their full stop).
- **FR-008 Small text**: deadline-strip headers 0.7rem → 0.75rem (the DESIGN.md label size).
- **FR-009 Help**: "Submit a report" and "Open a case" rewritten for the chat assistant and the open-from-report screen (both languages); screenshots regenerated.

## Checked and not changed
Contrast flags on the dark date/countdown chips and some tinted panels were measurement false positives (backgrounds drawn as images); the screenshots read correctly. "Wrong language" flags on the bilingual company settings and the Arabic law text in the legal mismatch queue are intended.
