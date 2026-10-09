# Feature Specification: View Files In the Site and Employee Case Cards

**Feature Branch**: `024-inline-viewer-employee-cases`

**Created**: 2026-10-09

**Status**: Draft

**Input**: Product-owner feedback after testing (2026-10-09): "I need to see pdf in same web site and also can download,
coz it's will not easy that every case I must download all pdfs, same for photo, cases in every section must be easy
to see everything" and "when I open employee file I can see that he have cases but I can't see cases, also I need to
see brief about his cases."

## Clarifications

### Session 2026-10-09

- Q: What does "see in the site" mean? → A: Every PDF (issued documents, draft previews, scans) and every image
  attachment (photos, scans) opens in a viewer inside the page — a large overlay with the file, its name, a Download
  button and a Close button — without leaving the page. Download stays available everywhere it is today.
- Q: Which places? → A: Everywhere a document, scan or attachment is listed: case page (documents and versions,
  attachments, response scan, witness papers), signed-scan page, confirmation screen, consultation review, reporter
  inbox/report attachments, employee page.
- Q: Several files on one case? → A: The viewer has Previous/Next to move through the files of the same list without
  closing it; images show as a thumbnail grid in the attachments card.
- Q: Phones? → A: Images open in the viewer on every screen size. Phone browsers often cannot show a PDF inside a
  page; on small screens a PDF opens in a new browser tab (the phone's own PDF viewer) with the Download button kept.
- Q: Employee page — the brief? → A: Built from case data, no AI (product owner, 2026-10-09). Every case of the
  employee (open ones included, newest first) shows as a card: case number (link), date, violation (viewer's
  language, feature 023), location, current stage (5-dot indicator) or outcome, the first ~200 characters of the
  incident description, and its issued documents with View and Download. The open case is no longer only a small line
  at the top.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - View a document without downloading (Priority: P1)

**Acceptance Scenarios**:
1. **Given** a case with three issued documents, **When** HR clicks "View" on the decision letter, **Then** the PDF
   opens in an overlay on the case page; Next shows the recommendations; Download saves the file; Close (or Esc)
   returns to the page where it was.
2. **Given** a photo attachment, **Then** the attachments card shows a thumbnail and clicking it opens the viewer.
3. **Given** a user who cannot see the case, **Then** the file URLs return 404 exactly as downloads do today.

### User Story 2 - See an employee's cases at a glance (Priority: P1)

**Acceptance Scenarios**:
1. **Given** an employee with one open and two closed cases, **When** HR opens the employee page, **Then** three case
   cards are listed with the brief, stage/outcome and documents; each document can be viewed in place.
2. **Given** HR staff limited to their own cases, **Then** only visible cases are listed and the "other cases you
   cannot see" note stays.

## Requirements *(mandatory)*

- **FR-001**: One reusable viewer (Blade component + small Alpine script, no new package) used on every page listed
  above. Keyboard: Esc closes, arrow keys move; focus is trapped and returned; RTL-aware.
- **FR-002**: Inline routes reuse the existing authorisation exactly: attachments via the existing `?preview=1` mode
  (PNG/JPEG/PDF only), issued documents via an inline variant of `documents.pdf` (same policy and integrity check,
  `Content-Disposition: inline`). Other file types show only Download. No file is served without the checks of its
  download route.
- **FR-003**: Inline responses keep `X-Content-Type-Options: nosniff`, `Cache-Control: private, no-store`, and a CSP
  that still lets the browser's built-in PDF viewer work; the app pages may frame them (same origin only).
- **FR-004**: Image thumbnails load the same authorised preview URL (no new public storage).
- **FR-005**: Small screens: PDFs open in a new tab instead of the overlay; images use the overlay.
- **FR-006**: Employee page lists every visible case as a card (FR clarification above), reusing `EmployeeProfile`,
  the violation helper (023), the stage indicator and `Ui` formatting; counters stay.
- **FR-007**: Viewing a file is not a new audit event (downloads are not logged today either).

## Success Criteria

- From the case page and the employee page, every PDF and image can be viewed without leaving the page.
- Authorisation tests: the inline routes deny exactly what the download routes deny.
