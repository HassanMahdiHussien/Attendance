# Feature Specification: Case Screens — Committee to Archive

**Feature Branch**: `006-committee-decision`

**Created**: 2026-10-06

**Status**: Draft

**Input**: User description: "Case screens part 2 (after feature 005): committee session, recommendations
(Doc A) drafting + three approvals by pool members, issuance and paper signatures, signed scan upload
and signature presence record, then either archive (no penalty) or the decision letter (warning /
termination) with the HR leave confirmation, optional external consultation, issuance, receipt
hand-off, appeal window and appeal logging to final archive; plus the committee member and external
reviewer views. Same custom Livewire workspace as 005."

## Clarifications

### Session 2026-10-06

- Q: Wording for partially / fully accepted defense outcomes? → A: Product owner approved the proposed
  wording (to be reviewed by the company's lawyer; companies can edit it in their templates):
  partial "وبعد دراسة إفادتك التحريرية ومراجعة نتائج التحقيق، قررت اللجنة الأخذ بجزء من الدفوع
  المقدمة من قبلك، مع توجيه العقوبة للأسباب التالية:"; full "وبعد دراسة إفادتك التحريرية ومراجعة
  نتائج التحقيق، قررت اللجنة الأخذ بالدفوع المقدمة من قبلك" (with English equivalents).
- Q: Feminine role labels? → A: Yes. "رئيسةً / عضوةً" for female attendees (from the linked employee
  record); name-only pool members without a known gender stay masculine.
- Q: Who types the session minutes? → A: CLAUDE.md §3b: a committee member's typed notes. HR / company
  admin records the session, and the minutes record which attending pool member typed them (revised
  after plan review, research R1 A1).
- Q: Consultation requested but no penalty? → A: archive is blocked until the firm completes it or a
  company admin cancels it with a recorded reason (research R1 A5).
- Q: Approver names on Doc A? → A: attendee signature lines as in the samples, plus one line naming
  the three in-system approvers (research R1).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - The committee session is recorded correctly (Priority: P1)

After the response stage (or after the response deadline with no response), HR or an attending pool
member records the session. They pick the attendees from the eligible pool of the mandate in effect
(conflicted members excluded, or shown with the HR clearance option for name-only matches), the chair
(defaulting to the Legal attendee), each attendee's vote, the typed minutes (what happened and the
decision), the outcome (recommendation only / warning / termination) and the defense acceptance. The
screen explains quorum failures in plain Arabic.

**Acceptance Scenarios**:
1. **Given** 3 attendees without a union member, **Then** saving is refused with "يلزم حضور ممثل نقابة
   العمال".
2. **Given** a valid quorum and a 2–1 vote, **Then** the session is saved, the case moves to "committee
   session held" and the majority is reached; with 1–1–1, it is saved without a majority and HR is told
   a re-vote is needed.
3. **Given** a conflicted employee-linked pool member, **Then** they are not selectable; a name-only
   match is selectable only after HR clearance with a reason.

### User Story 2 - Recommendations are drafted, approved by three pool members, issued and signed (Priority: P1)

HR writes the recommendations (findings + recommendations, every enabled language) with a live
preview, then submits it (citation check) for approval. Every eligible pool member with a login sees
it under "اللجنة", with all language versions side by side, and approves or rejects with a mandatory
comment. They cannot edit. The first approval per category counts. A rejection returns it to HR with
the comment. Any HR edit invalidates the approvals. With all three approvals in, HR confirms and issues
it (mockup confirmation screen), prints it, collects the attendees' paper signatures, uploads the
signed scan and records, per attendee, whether their signature box is filled ("presence check", never
"verified"). Pool members are notified by email and in-app when an approval is needed.

**Acceptance Scenarios**:
1. **Given** approvals from Legal and HR only, **Then** "issue" is not available and the screen shows
   which category is missing.
2. **Given** a rejection with a comment, **Then** HR sees the comment and the case returns to drafting;
   after an edit, all approvals are invalidated.
3. **Given** the signed scan uploaded and one signature box recorded as empty, **Then** the case page
   flags "خانة التوقيع تبدو فارغة" for that attendee and asks HR to resolve it. The wording never
   claims identity verification.

### User Story 3 - No penalty → archive; penalty → decision letter (Priority: P1)

With outcome "recommendation only", HR closes the case to final archive after the signed scan. With a
penalty, HR writes the decision letter reasons (every enabled language). The letter's template prints
the defense outcome sentence, the penalty and the GM / union signers. For a termination, HR must tick
"الموظف ليس في إجازة قانونية أو غياب مرضي موثق" before issuance; who and when are recorded. Issuance
uses the same confirmation screen. The letter receipt hand-off is then recorded.

**Acceptance Scenarios**:
1. **Given** a termination without the leave confirmation, **Then** issuance is refused with that
   reason.
2. **Given** an issued warning, **Then** the appeal window (30 days) starts and is shown on the case
   page.

### User Story 4 - External consultation on request (Priority: P2)

Anyone on the case (HR, an attending pool member) can request external consultation while the case is
open; the request and the running 15-day clock are shown prominently. Once Doc A is signed, reviewers
of the partnered firm see the case under "حالاتي", read it (documents, minutes, response scan) and
complete the consultation with a note. The decision letter cannot be issued while a requested
consultation is incomplete.

**Acceptance Scenarios**:
1. **Given** a request before Doc A is signed, **Then** the reviewer does not see the case yet; after
   signing, they do.
2. **Given** a pending consultation, **Then** the decision letter shows "بانتظار الاستشارة القانونية" and
   cannot be confirmed.

### User Story 5 - Appeal window and final archive (Priority: P2)

During the 30-day window, HR logs whether an appeal was filed (date) and later its outcome. Without an
appeal, the case archives itself after the window (existing scheduler). Archived cases are read-only.

**Acceptance Scenarios**:
1. **Given** an appeal logged, **Then** the case shows "appeal pending" until the outcome is recorded,
   then archives as final.
2. **Given** an archived case, **Then** no action buttons are shown and any direct action is refused.

### Edge Cases
- A pool member who is also the case's HR user: they act in each role separately; one person cannot
  approve two categories.
- A reviewer from a firm not partnered with the company: never sees the case.
- The stale-stage protection from 005 applies to every new action.
- Corrections of Doc A or Doc B after issuance follow 004 / 005 rules (approvals for a corrected Doc A
  are fresh).

## Requirements *(mandatory)*

- **FR-001**: Session screen calling `CommitteeSessionService` (quorum / majority / conflicts / mandate
  in effect / chair default), with `ConflictClearance` for name-only matches. Authorised for company
  admin, HR (case visible), and eligible attending pool members with a login.
- **FR-002**: Recommendations editor via `RecommendationsEditor` (findings and recommendations per
  language), submitted through the workflow transitions (citation gate).
- **FR-003**: Approval screen for pool members via `DocumentApprovalService` (approve / reject with
  comment, all languages side by side, read-only text); "اللجنة" nav item listing pending approvals;
  notification to eligible pool members when a document enters awaiting approvals.
- **FR-004**: Generalise the confirmation screen to all three document types (Doc A requires three
  valid approvals; Doc B requires consultation completion when requested and, for termination, the
  leave confirmation via `TerminationLeaveConfirmation`).
- **FR-005**: Signed scan upload (`signed_document_scan`) moving the case to signed recommendations
  uploaded, and a per-attendee signature presence record (filled / appears empty) through
  `HandOffService` signatures; UI copy never says "verified" or "authentic".
- **FR-006**: No-penalty close (signed recommendations → archived_final) and penalty path to decision
  letter drafting (`DocumentDrafts`), plus the decision letter receipt hand-off.
- **FR-007**: Consultation request (any open stage) and reviewer completion screen
  (`ConsultationService`), with visibility from signed Doc A only.
- **FR-008**: Appeal logging screen (`AppealService`); archived cases read-only.
- **FR-009**: Default templates updated with the approved defense outcome wording (ar / en) and
  feminine role labels (`chair_label_female`, `member_label_female`); the renderer picks by the
  attendee's employee gender.
- **FR-010**: Every action is authorised in the service and the component, re-checked at the Livewire
  update endpoint, and writes a labelled timeline entry.

## Success Criteria *(mandatory)*
- **SC-001**: A case can be driven in the browser from "response recorded" to "archived final" on both
  paths (warning with appeal window; recommendation only) by HR plus three pool member accounts.
- **SC-002**: Role × action matrix (actions: record session, draft/submit Doc A, approve/reject, issue, upload signed scan, override empty box, close without penalty, draft/submit Doc B, leave confirmation, request/complete/cancel consultation, record receipt, log appeal/outcome) fully tested (HR, admin, each pool category, non-pool committee
  member, reviewer partnered / not, reporter): 0 unauthorised actions succeed.
- **SC-003**: No UI string claims signature verification (automated text check on the views).
- **SC-004**: Notifications for pending approvals reach exactly the eligible pool members with logins.

## Assumptions
- AI touchpoints remain manual (CLAUDE.md §3bb): the presence check is recorded by HR from the scan.
  The AI vision check is a later feature.
- Reporting / dashboards and CSV import are later features.
