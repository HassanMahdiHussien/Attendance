# Feature Specification: Automatic Decision-Letter Draft and Related-Case Links

**Feature Branch**: `022-tp4-related-links`

**Created**: 2026-10-09

**Status**: Draft

**Input**: The last two gaps from the pilot-readiness audit (2026-10-08): CLAUDE.md §3b touchpoint 4 ("…if the
recommendation includes a penalty (warning/termination), automatically drafts Doc B") and §24.4 ("A case related to
another (the same incident, or a follow-up of an earlier case) can be linked manually with type `related`; it is shown
and referenced the same way").

## Clarifications

### Session 2026-10-09

- Q: When does the automatic draft start? → A: When the signed recommendations scan is recorded with every expected
  signature box filled (or an empty box is overridden by the company admin) and the confirmed outcome includes a
  penalty — exactly when the case reaches `signed_recommendations_uploaded`. It queues the same AI draft HR can start
  today; the human confirmation gate, the manual "write it yourself" path, the regulation comparison inheritance
  (feature 021) and the consultation wait stay as they are. If the AI is unavailable or a draft already exists,
  nothing breaks: HR sees the usual "AI unavailable — write it manually or try again" state.
- Q: No penalty? → A: Unchanged — HR closes the case without a decision letter (existing action).
- Q: Related links — who and how? → A: HR (company admin / HR staff with access to both cases) links the case to
  another case of the same company with an optional short reason; the link shows on both cases ("Related cases": case
  number, employee, date, violation type, outcome, reason) and is recorded in the case events. Links are permanent
  records (no deletion), like other case records; a mistaken link can be annotated "linked in error" with a reason,
  which hides it from AI context but keeps it visible with that note.
- Q: "Referenced the same way" as prior cases? → A: Related cases appear in a banner like prior cases and are passed
  to the AI as context at touchpoints 1 and 3 (case number, date, violation type, outcome — from the database, never
  AI wording). They are not printed in documents.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Decision letter drafts itself (Priority: P1)

**Acceptance Scenarios**:
1. **Given** a warning outcome and a signed scan with all boxes filled, **When** HR records the presence check,
   **Then** the AI decision-letter draft is queued automatically and the case page shows "Drafting the decision
   letter…", then the draft for review.
2. **Given** an outcome without a penalty, **Then** no decision letter is drafted.
3. **Given** the AI is unavailable, **Then** the case is not stuck: it returns to the review-ready stage with the
   "write it manually" path, exactly as a failed manual request does today.
4. **Given** a decision-letter draft already exists (e.g. HR wrote one), **Then** no automatic draft replaces it.

### User Story 2 - HR links related cases (Priority: P2)

**Acceptance Scenarios**:
1. **Given** two cases HR can see, **When** HR links them with a reason, **Then** both case pages list each other
   under "Related cases" and a case event is recorded on both.
2. **Given** a case in another company or one HR cannot see, **Then** it cannot be linked (not offered, refused).
3. **Given** a link marked "linked in error", **Then** it stays visible with the note and is excluded from AI context.

## Requirements *(mandatory)*

- **FR-001**: Automatic Doc B draft request after the transition to `signed_recommendations_uploaded` when the
  outcome has a penalty and no decision-letter draft exists; uses the existing `AiDrafting` path, token and reconcile.
- **FR-002**: Related links via `case_links` type `related`, both directions shown, permanent, with reason and an
  "in error" annotation; events on both cases; tenant and visibility checks.
- **FR-003**: Related cases in the AI payload for touchpoints 1 and 3 (database facts only), not in templates.
- **FR-004**: Translated, design-system components; tests.

## Success Criteria *(mandatory)*

- **SC-001**: A penalty case reaches a decision-letter draft without HR pressing "Draft with AI" (FakeClaudeClient).
- **SC-002**: No link can cross companies or bypass case visibility (automated tests).
