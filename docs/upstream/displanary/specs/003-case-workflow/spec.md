# Feature Specification: Case Workflow Engine

**Feature Branch**: `003-case-workflow`

**Created**: 2026-10-05

**Status**: Draft

**Input**: User description: "Feature 003 = CLAUDE.md build step 3: the case workflow engine. It covers
the state machine in CLAUDE.md §7, the legal deadlines (3-day response, 15-day procedure, 30-day appeal,
in calendar days), committee quorum and majority, the Doc A three-category approval gate, duplicate-report
detection, the conflict-of-interest check, prior-case linking and banners, the repeat-responsible-person
pattern, paper hand-offs (signed / refused to sign / delivery refused), external consultation on request,
appeal tracking with auto-archive, scheduled deadline alerts, and the HR leave confirmation before a
termination letter. Engine only (services, rules, scheduler, tests); case screens come in a later
feature."

## Clarifications

### Session 2026-10-05

- Q: Engine and screens together? → A: Engine only. The case board, case page and confirmation
  screen follow after the document templates feature, built on this engine's services.
- Q: Termination leave guard source? → A: HR confirms "not on statutory leave or documented sick
  absence" before a termination decision letter is issued; the system records who and when
  (CLAUDE.md §3).
- Q: Who sees consultations? → A: Firm-wide reviewers of the partnered law firm (feature 002), and
  only once Doc A is signed (CLAUDE.md §3); the request itself may be recorded earlier.
- Q: Name-only pool member whose name matches a party? → A: (orchestrator decision) excluded by
  default; HR may clear it after checking identity, with reason, who and when recorded. Employee-linked
  matches are always excluded.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - A case moves only through legal steps, with every step recorded (Priority: P1)

HR opens a case (directly or from a reporter's draft) and drives it through the CLAUDE.md §7 stages.
The system refuses any step that is not allowed from the current stage or whose preconditions are not
met, and records every transition (who, when, from, to, why) in the case history.

**Why this priority**: the procedure's legal validity depends on steps happening in order and being
provable.

**Independent Test**: Drive one case end-to-end through both the warning path and the
recommendation-only path, try every illegal jump, and inspect the case history.

**Acceptance Scenarios**:

1. **Given** a draft from a reporter, **When** HR completes the required facts and opens it, **Then**
   it becomes an open case with a case number, the labour-law version of its branch's jurisdiction,
   and a `case_opened` history entry.
2. **Given** an open case, **When** someone tries to jump to "committee session held", **Then** it is
   refused with the reason, and nothing changes.
3. **Given** the AI is unavailable, **When** HR chooses "write manually", **Then** the case can move
   from open to the citation check without passing through an AI drafting stage, and the document is
   marked human-drafted.
4. **Given** a case stuck in an AI drafting stage, **When** HR cancels the AI attempt, **Then** the
   case returns to the previous stage (never stuck).
5. **Given** a recommendation with no penalty, **When** the signed recommendations are processed,
   **Then** no decision letter is required and the case moves to archived as final.

---

### User Story 2 - Deadlines are computed correctly and nobody misses one (Priority: P1)

Issuing the defense letter starts two clocks, both in calendar days and anchored to the issue date:
the employee's 3-day response and the 15-day procedure deadline. Issuing a decision letter starts the
30-day appeal window. Nothing pauses them. Cases approaching or past a deadline raise alerts on a
schedule, independent of anyone opening the app.

**Why this priority**: missing the 15-day deadline can invalidate the procedure.

**Independent Test**: Issue defense letters on varied dates (month ends, year end, Baghdad midnight
edges), advance the clock, run the scheduled alerts and check due dates, statuses and notifications.

**Acceptance Scenarios**:

1. **Given** a defense letter issued 18 June 2026, **Then** the response is due 21 June 2026 and the
   procedure deadline is 3 July 2026, regardless of weekends, holidays or employee leave.
2. **Given** a case 48 hours or less from its 15-day deadline with no decision issued, **When** the
   scheduled check runs, **Then** HR (company admins, the assigned HR person and the opener) receive
   one alert. It is not repeated for the same deadline stage, and it is recorded in the case history.
3. **Given** the deadline has passed with no decision, **Then** the case shows as overdue (red,
   top priority) and a second alert is sent.
4. **Given** a pending external consultation, **Then** it appears in the deadline alerts with the
   clock still running.
5. **Given** a decision issued 10 August, **Then** the appeal window ends 9 September (30 calendar
   days). If no appeal is logged by then, the scheduler archives the case as final.

---

### User Story 3 - The committee session follows the Administrative Order (Priority: P1)

For a case's session, HR picks who actually attended from the pool of the mandate in effect on the
defense letter's issue date, filtered to the case's branch. Quorum: at least 3 attendees, including a
labour-union member. The chair defaults to the Legal attendee. Each attendee's vote is recorded, and
the decision is the majority of those present. Pool members with a conflict of interest in the case
cannot attend.

**Why this priority**: an invalid committee composition voids the recommendation.

**Independent Test**: With the seeded 5/2/2 pool, record sessions with 2 attendees, 3 without union,
3 with union, a conflicted member, a member from a superseded mandate, and split votes.

**Acceptance Scenarios**:

1. **Given** 3 attendees without a labour-union member, **When** the session is recorded, **Then** it
   is refused (quorum).
2. **Given** a valid quorum, **Then** the Legal attendee is chair unless HR picks another attendee,
   and the case moves to "committee session held" with the typed minutes stored.
3. **Given** votes 2 approve / 1 reject, **Then** the decision passes. **Given** 1 approve / 1 reject /
   1 abstain, **Then** there is no majority and the case cannot proceed to drafting until a new vote.
4. **Given** a pool member who is also a witness, the alleged responsible person or the employee in
   the case, **Then** they are flagged at case opening and refused as an attendee.
5. **Given** a mandate superseded after the defense letter was issued, **Then** the session still uses
   the mandate in effect on the issue date.

---

### User Story 4 - The recommendations document needs three approvals (Priority: P1)

The recommendations document (Doc A) waits for one approval each from the Legal, HR and Labour-Union
pools (first approver per category wins). An approver can approve, or reject with a mandatory comment,
but cannot edit. Rejection returns it to HR. Any HR edit invalidates all prior approvals. Each approval
records which language versions existed at that moment.

**Why this priority**: CLAUDE.md §16 lists this gate as legally load-bearing.

**Independent Test**: Approve with each category; try a second approval in the same category; try a
non-pool user; reject without a comment; edit after two approvals and confirm both are invalidated.

**Acceptance Scenarios**:

1. **Given** approvals from Legal and HR only, **Then** the document cannot be issued.
2. **Given** a user who is not in the case's pool for that category, **When** they approve, **Then** it
   is refused.
3. **Given** two approvals, **When** HR edits any language version, **Then** both approvals are marked
   invalidated (kept for history), and three fresh approvals are required.
4. **Given** a rejection without a comment, **Then** it is refused. With a comment, the case returns to
   HR with the comment visible in the case history.

---

### User Story 5 - Intake checks warn HR before mistakes happen (Priority: P2)

When a report or case is created, the system checks for a duplicate (another report or case on the same
employee within 48 hours) and offers linking or merging. On opening a case, it links all the
employee's prior cases and builds the prior-cases banner (count, each one's date, type and outcome; a
same-type repeat within 12 months is highlighted). It also builds the responsible-person banner when the
same alleged responsible employee is named in 2+ other cases in the same branch within 12 months. All
of these are visibility only; nothing is decided automatically.

**Independent Test**: Seed prior cases and reports at varied dates; create new ones and inspect the
duplicate candidates, links and banner data.

**Acceptance Scenarios**:

1. **Given** a report on employee E at 10:00 and another at 09:00 the next day, **Then** the second is
   flagged as a possible duplicate; HR can link it (`duplicate_merge`) or continue.
2. **Given** an employee with 3 prior cases (one the same violation type 8 months ago), **When** a case
   is opened, **Then** 3 `prior_record` links exist and the banner marks the same-type repeat.
3. **Given** a responsible employee named in 2 other cases in the same branch in the last 12 months,
   **Then** the responsible-person banner says "named in 2 other cases".

---

### User Story 6 - Paper hand-offs, consultation and appeal are recorded properly (Priority: P2)

Each of the three hand-offs (defense letter receipt, response submission, decision letter receipt) is
recorded as signed, refused to sign (2 witnesses), or delivery refused (date, place, attempting
person, 2 witnesses). Anyone on the case can request external consultation (recorded who and when). The
case then waits for the firm's reviewer note before the decision letter. HR logs appeals manually.
A termination decision letter cannot be issued until HR records the leave/absence confirmation.

**Acceptance Scenarios**:

1. **Given** "refused to sign" with one witness, **Then** it is refused; two witnesses are required.
2. **Given** "delivery refused", **Then** date, place, attempting person and two witnesses are all
   required, and the case status reflects "response delivery refused" where relevant.
3. **Given** a consultation request, **Then** `legal_review_requested_by/_at` is set, the case is marked
   sensitive, and decision-letter issuance is blocked until a reviewer completes the consultation.
4. **Given** a termination decision letter, **When** issuance is attempted without the HR leave
   confirmation, **Then** it is refused; with it, the confirmer and time are stored.
5. **Given** an appeal logged in the window, **Then** the case goes to appeal pending, then to appeal
   resolved with the outcome text, then to archived as final.

### Edge Cases

- Defense letter issued at 23:30 Baghdad time: the response is due 3 calendar days after the Baghdad
  date, not the UTC date.
- Deadline computations across month ends, Feb 28/29 and the year end.
- A second defense letter version (correction) after issuance does not restart the clocks; the anchor
  stays the first issuance.
- A pool member who is both HR and Legal in different mandates is counted by their role in the mandate
  in effect.
- Two HR users act on the same case at once: transitions are serialised and the second is refused if
  the stage changed.
- An archived case accepts no further transitions.
- An alert already sent for a stage is never re-sent by later scheduler runs.

## Requirements *(mandatory)*

### Functional Requirements

**State machine**
- **FR-001**: Case stages MUST follow an explicit allowed-transition map covering every CLAUDE.md §7
  stage, including manual-writing paths around AI stages and a cancel path out of every AI stage.
- **FR-002**: Every transition MUST check its preconditions, serialise concurrent changes, and write a
  case-history entry (actor, from, to, reason, payload).
- **FR-003**: Opening a case (leaving draft) MUST require the facts (employee, branch, violation type,
  time, place or location) and set case number, `jurisdiction_at_opening` and `law_version_id` (the
  labour law in force for that jurisdiction).
- **FR-004**: Issuing documents through the existing issuer MUST drive the matching transitions:
  defense letter issued sets `defense_letter_issued_at` (first issuance only), recommendations issued,
  decision issued sets `decision_issued_at`.

**Deadlines & alerts**
- **FR-005**: Response due = issue date + 3 days; procedure due = issue date + 15 days; appeal end =
  decision date + 30 days. All are Baghdad calendar dates, with no holiday or leave logic. The day
  counts are config values.
- **FR-006**: Each case MUST expose deadline status per clock: on track / approaching (≤ 48 h) /
  overdue / met.
- **FR-007**: A scheduled command MUST send alerts (email + in-app notification) for: procedure
  deadline approaching and overdue, response deadline passed, consultation pending, appeal window
  ending. Each alert is sent once per case per stage and recorded in the case history.
- **FR-008**: The scheduler MUST auto-archive cases whose appeal window ended with no appeal logged.

**Committee**
- **FR-009**: Session recording MUST enforce: attendees from the mandate in effect on
  `defense_letter_issued_at`, restricted to the case branch or company-wide; ≥ 3 attendees; ≥ 1 labour
  union; no conflicted member. The chair defaults to the Legal attendee. Votes are approve / reject /
  abstain per attendee, and the decision passes only with a strict majority of those present.
- **FR-010**: The typed minutes (what happened + the decision), outcome (recommendation only / warning
  / termination) and defense acceptance (full / partial / rejected) MUST be stored with the session.

**Doc A approvals**
- **FR-011**: Approve / reject services per CLAUDE.md §3: approver must be the linked user of a pool
  member of that category for the case; first valid approval per category counts; rejection needs a
  comment; approver cannot edit; `languages_present` recorded.
- **FR-012**: Any content edit of the recommendations document before issuance MUST invalidate all
  current approvals.

**Intake checks**
- **FR-013**: Duplicate detection (same employee, ± 48 h by occurrence time, reports and cases) MUST
  return candidates; linking creates a `duplicate_merge` link.
- **FR-014**: Opening a case MUST create `prior_record` links to all of the employee's earlier cases and
  provide banner data (count; each case's date, type, outcome; same type within 12 months flagged).
- **FR-015**: Responsible-person pattern data: same `alleged_responsible_employee_id` in ≥ 2 other cases
  of the same branch within 12 months.
- **FR-016**: Conflict-of-interest check: pool members (case branch or company-wide, mandate in effect
  or current) who are witnesses, the alleged responsible employee or the case employee are returned as
  conflicts at opening and refused at session recording.

**Hand-offs, consultation, appeal, leave**
- **FR-017**: Hand-off records per CLAUDE.md §3 with the witness / place / attempter rules.
- **FR-018**: Consultation request and completion services; decision-letter issuance blocked while a
  requested consultation is not completed.
- **FR-019**: Appeal logging (filed y/n, date, outcome) driving the appeal states.
- **FR-020**: Termination decision letters MUST require a recorded HR leave/absence confirmation (who,
  when) before issuance.

**Access**
- **FR-021**: Every engine service MUST check the actor through the existing policies /
  `CaseVisibility` and run inside the tenant context; no new cross-company path.

### Key Entities

- **Case** (existing) — status, anchors, outcome, defense acceptance, consultation fields.
- **Case event** (existing) — the history written by every transition.
- **Committee session attendance** (existing) — attendee, chair, vote.
- **Document approval** (existing) — Doc A approvals with invalidation.
- **Signature** (existing) — hand-off records.
- **Appeal tracking** (existing).
- **Law** (existing) — gains a category (labour / social security) to pick the case's labour law.
- **Alert record** — which alert was sent for which case and stage (to send once).
- **Leave confirmation** — who / when on the termination decision letter.
- **Consultation note** — reviewer, completion time, note.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: 100% of (stage → stage) pairs are tested: every allowed transition succeeds when its
  preconditions hold, and every other pair is refused.
- **SC-002**: Deadline calculations are correct on a table of at least 20 dates, including month ends,
  leap day, year end and Baghdad-midnight edges.
- **SC-003**: Every named guard case in research R10 (quorum, majority, mandate boundary, conflict
  types, approval cases) has its own test.
- **SC-004**: Running the scheduler twice in the same hour (or concurrently) sends each alert exactly
  once; a failed delivery is retried and then sent exactly once.
- **SC-005**: A full warning-path case and a full recommendation-only case can be driven end-to-end
  through the services in automated tests, producing a complete case history.

## Assumptions

- No screens in this feature; services are called from tests now and from Livewire / Filament later
  (Constitution V).
- AI touchpoints are not built yet. The AI stages exist as states, with manual and cancel paths so
  nothing depends on the AI.
- PDFs and templates are a later feature. Here documents are created with plain content rows through
  the existing services.
- Email uses Laravel notifications with the configured mailer (log mailer locally). In-app
  notifications use Laravel's database notifications table (framework migration, no new package).
- Holidays and leave never affect discipline deadlines (CLAUDE.md §3, WEIRDNESS).
