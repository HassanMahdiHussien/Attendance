# Feature Specification: Case Screens — Intake to Employee Response

**Feature Branch**: `005-case-screens`

**Created**: 2026-10-05

**Status**: Draft

**Input**: User description: "Case screens, part 1 of 2 (product owner split, 2026-10-05): a shared
sign-in for every role; the case board per the mockup; reporter reports and HR intake; opening a case
with duplicate / conflict / prior-case / responsible-person banners; the case page with deadlines and
timeline; manually writing the defense letter; the issuance confirmation screen per the mockup;
printing; recording the paper hand-offs and the employee response. Custom Livewire app with the
mockup's top bar (not Filament), per CLAUDE.md §2 and §18. Part 2 (006) covers the committee session,
approvals, decision letter, appeal, consultation and the committee / reviewer views."

## Clarifications

### Session 2026-10-05

- Q: Split? → A: Two features. 005 = intake to response; 006 = committee to archive.
- Q: Where do cases live? → A: A custom Livewire app following the mockup (top bar, cards), separate
  from the Filament setup area. One shared sign-in page for every role, sending each role to its own
  place.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Everyone signs in at one door and lands in the right place (Priority: P1)

All internal roles sign in on one Arabic sign-in page with the same protections as today (per-email
throttle, identical refusal message, inactive accounts refused, idle timeout, audit). HR staff and
company admins land on the case board. Reporters and department managers land on "my reports".
Committee members and external reviewers land on "my cases" (read-only list in this feature).
HR-partner staff land on a placeholder until the dashboards feature. The Platform Admin lands on the
platform area. Company admins also see a link to the setup area.

**Independent Test**: Sign in as each role; check the landing page, the top-bar items and that every
other area is refused by direct URL.

**Acceptance Scenarios**:

1. **Given** any role, **When** signing in at `/login`, **Then** it lands on its page, and the existing
   Filament sign-in pages keep working.
2. **Given** a reporter, **When** opening `/cases` by URL, **Then** access is refused.
3. **Given** 5 wrong passwords, **Then** the shared page throttles exactly as the panel sign-in does
   (the same limiter and audit).

---

### User Story 2 - Reporters report, HR turns reports into cases (Priority: P1)

A reporter (or department manager, within their branch scope) submits a violation report: place
(location from their branch list, else free text), date/time, violation type, alleged responsible
person (employee search or free text), witnesses, description and optional attachments (photos / PDF).
They see only the bare status of their own reports. HR sees an inbox of new reports, can dismiss one
with a reason, or turn it into a case with the facts pre-filled. HR can also open a case directly.
Before a case is opened, possible duplicates (same employee within 48 hours) are shown, and HR can
link to an existing case instead.

**Acceptance Scenarios**:

1. **Given** a reporter scoped to branch A, **Then** they can pick only branch A locations and see only
   their own reports' status (received / under review / converted / dismissed), never case details.
2. **Given** a report converted by HR, **Then** the case draft carries its facts, the report status
   becomes "converted", and the report links to the case.
3. **Given** another case on the same employee 30 hours earlier, **When** HR opens the new case, **Then**
   a duplicate warning lists it with "link as duplicate" or "continue anyway".
4. **Given** the case is opened, **Then** it gets its case number, prior cases are linked, and the case
   page shows the prior-cases banner (same type within 12 months highlighted), the responsible-person
   banner when applicable, and any committee conflicts.

---

### User Story 3 - The case board shows what needs attention (Priority: P1)

The board matches the mockup: a red banner when cases approach or pass the 15-day deadline;
single-row cards with initials avatar, name, case number (mono), "position — department", a 5-dot
stage indicator and a status pill (remaining days in red when urgent, amber for waiting on a
signature, green for closed); closed cases faded. Urgent cases sort first. Filters: branch, stage,
text search. Each user sees only the cases their role allows.

**Acceptance Scenarios**:

1. **Given** a case 1 day from its 15-day deadline, **Then** it is at the top with a red "باقي يوم"
   pill and counted in the red banner.
2. **Given** HR staff without the "see all" add-on, **Then** only their opened or assigned cases
   appear.
3. **Given** an archived case, **Then** it renders faded with a green "مغلقة" pill.

---

### User Story 4 - HR writes, confirms and issues the defense letter (Priority: P1)

On the case page, HR writes the defense letter body in Arabic and English (the template wording is
added automatically) with a live preview. HR submits it for review, which runs the citation check,
and then reaches the confirmation screen from the mockup. It shows the document preview in its own
gray card in the Amiri font, the warning "تأكد من صحة الأسماء والتواريخ — لا يمكن التعديل بعد الإصدار",
and the reference number pre-filled with the provisional value (editable). Then "تأكيد وإصدار" or
"تعديل". If the company requires a second review, another user must mark it reviewed first. After
issuing, HR prints the PDF once it is ready. A correction starts a new version with a mandatory
reason.

**Acceptance Scenarios**:

1. **Given** a draft, **When** confirmed and issued, **Then** the case moves to awaiting the employee's
   response, both clocks start, and the PDF becomes downloadable when generated (with a "being prepared"
   state before that).
2. **Given** a typed reference number already used this year, **Then** issuance is refused with a clear
   message.
3. **Given** a company with mandatory second review, **When** the same user tries to issue without a
   second reviewer, **Then** it is refused; a second HR user can mark it reviewed.
4. **Given** an issued letter, **When** HR starts a correction with a reason, **Then** a new draft
   version opens and the issued one stays valid until the correction is issued.

---

### User Story 5 - Paper hand-offs and the employee's response are recorded (Priority: P1)

HR records the defense letter receipt (signed, refused to sign with 2 witnesses, or delivery refused
with date, place, attempting person and 2 witnesses). HR then records the response: received / refused
to sign / delivery refused, with the scanned response uploaded. If nothing arrives by the deadline, HR
can mark "no response". The case page shows each record in the timeline and the response clock as met
or missed.

**Acceptance Scenarios**:

1. **Given** "refused to sign" with one witness, **Then** the form refuses with the reason.
2. **Given** an uploaded response scan, **Then** it is stored privately, listed on the case page,
   downloadable only by users who can see the case, and the case moves to "response scanned".
3. **Given** the response deadline passed with nothing received, **Then** "no response" is available
   and the case can move on to the committee stage (006).

### Edge Cases

- Two HR users act on the same case at once: the second action fails with a "the case changed,
  reload" message, never a server error.
- Upload limits: PDF / JPG / PNG, ≤ 10 MB each, stored privately, never publicly linked.
- Arabic-Indic digits in the UI as in the mockup (case number ٠١٤٢, "باقي يومان"); documents keep the
  Western digits of the samples.
- Session expiry during typing: drafts are saved explicitly ("حفظ المسودة") and not lost on validation
  errors.
- A user without access opening a case URL gets 404 (no information leak).
- Mobile phone widths: the board and forms stay usable (single column).

## Requirements *(mandatory)*

### Functional Requirements

**Sign-in & shell**
- **FR-001**: One sign-in page `/login` for all roles. It MUST share the hardening of the panel sign-in
  (one implementation of the limiter, the generic refusal and the audit), and add role-based landing.
- **FR-002**: The workspace layout MUST follow the mockup: a white top bar with the app name, role-based
  navigation (الحالات, البلاغات, and for admins a link to setup), company name and initials avatar,
  sign-out; Tajawal UI font, §18 tokens, Amiri only inside document previews, JetBrains Mono for
  reference numbers; RTL; responsive.
- **FR-003**: Every page MUST be authorised through the existing policies and `CaseVisibility`; no new
  cross-company path.

**Reports**
- **FR-004**: Reporters / department managers submit reports within their branch scope (location list
  or free text, time, type, responsible person, witnesses, description, attachments). They see only the
  status of their own reports.
- **FR-005**: HR has a reports inbox: dismiss with a reason (audited), or convert to a case draft
  (pre-filled), with report ↔ case linking.

**Cases**
- **FR-006**: HR opens cases (directly or from a report) with the CLAUDE.md §3 facts. The duplicate
  check runs before opening; on opening, priors are linked, and banners and conflicts are computed
  (`CaseOpening`, `CaseInsights`, `ConflictClearance`).
- **FR-007**: The board per the mockup, with urgency sorting, the red banner count, filters, the
  visibility rule, and the 5-stage indicator mapping in contracts/screens.md.
- **FR-008**: The case page shows header, banners, deadlines (response / procedure / appeal with state
  colours), timeline (case events in Arabic), documents with versions and PDF status, attachments and
  stage-appropriate actions only.

**Documents & issuance**
- **FR-009**: Writing a defense letter (ar + en bodies, markdown subset, live preview) through a draft
  service; submit for review runs the workflow transitions (citation gate included).
- **FR-010**: The confirmation screen per the mockup, with an editable provisional reference, the
  second-review step when the company requires it, and issuance through `CaseDocumentIssuance`.
- **FR-011**: PDF download for issued documents (pending / failed states shown); a correction with
  reason through `DocumentCorrector`.

**Hand-offs & response**
- **FR-012**: Hand-off forms for the defense letter receipt and the response, with the witness /
  place / attempter rules (`HandOffService`), response scan upload (attachments, private disk), and
  "no response after the deadline".

### Key Entities
Existing: case, violation report, report witnesses, case witnesses, document (+ contents), signature,
attachment, case event. New services: `CaseDrafts` (create / update the draft case from HR or a
report), `ReportService` (submit, dismiss, convert), `DocumentDrafts` (create / update non-recommendation
drafts), `AttachmentService` (validated private uploads).

## Success Criteria *(mandatory)*

- **SC-001**: A defense letter can go from a reporter's report to an issued, printable PDF in under
  10 minutes of HR work in a walkthrough.
- **SC-002**: For every role, automated tests prove the exact pages and actions reachable (direct URLs
  included); 0 cross-company leaks.
- **SC-003**: The board and confirmation screen match the mockup's structure (header, banner, card
  anatomy, 5-dot indicator, pills; preview card in Amiri with warning and two buttons). This is
  checked in the browser by the orchestrator.
- **SC-004**: Every action on the case page produces the expected case-history entry and state, with
  tests per action.

## Assumptions
- AI drafting is not built; the "write manually" path is the only drafting path for now (CLAUDE.md
  §3bb). The UI leaves room for an "AI draft" button later.
- Committee, approvals, decision letter, appeal and consultation screens are feature 006.
- Notifications reuse feature 003's alerts; no new channels.
