# Feature Specification: Global Search, Remembered Filters and the Case "Next Step"

**Feature Branch**: `019-search-next-step`

**Created**: 2026-10-09

**Status**: Draft

**Input**: Product owner, 2026-10-08: "faster case work — global search (case no., employee, ID), filters that stick,
clearer step-by-step case page with the next action highlighted, keyboard-friendly forms". Approved mockup (top-bar
search with Ctrl K; case page "NEXT STEP" card).

## Clarifications

### Session 2026-10-09

- Q: What can be searched? → A: Case number, employee name and employee number for staff who can see cases; employees
  by name/number for those who may open employee pages; HR also finds inbox reports/email drafts by subject or
  violation type. Reporters search only their own reports. Results never include anything the user's existing screens
  would not show.
- Q: Arabic search? → A: Names match regardless of common spelling variants (أ/إ/آ/ا, ة/ه, ى/ي, tatweel, diacritics)
  and Arabic-Indic digits match Western digits ("٠٠٠٣" finds case 0003).
- Q: Remembered filters? → A: The case board's search/branch/stage filters are remembered per user, so returning to
  the board restores them; a "Clear filters" link resets them. URL parameters still win when present.
- Q: Next step? → A: The case page shows a "Next step" card at the top from the shared `NextAction` rule (feature 018):
  what to do, who must do it, the due date with its deadline badge, and one button to the control that does it.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Find anything from anywhere (Priority: P1)

From any workspace page, the user presses Ctrl K (or taps the search icon on a phone) and types; within a moment a
dropdown shows grouped results — Cases (number, employee, stage badge), Employees (name, number, department), Reports
(for HR) — each opening its page. Keyboard: arrows move, Enter opens, Escape closes.

**Acceptance Scenarios**:
1. **Given** HR types "0003" or "٠٠٠٣", **Then** case 0003 appears first.
2. **Given** HR types "احمد" and an employee is stored as "أحمد", **Then** the employee and their cases appear.
3. **Given** HR staff without "see all cases", **Then** cases of colleagues do not appear.
4. **Given** a reporter, **Then** only their own reports can appear — never cases or employees.
5. **Given** fewer than 2 characters, **Then** nothing is searched.

### User Story 2 - Filters stay set (Priority: P2)

**Acceptance Scenarios**:
1. **Given** HR filtered the board to branch Hilla and stage 3, **When** they open a case and come back to the board,
   **Then** the filters are still applied and shown as removable chips.
2. **Given** "Clear filters", **Then** all filters reset and the remembered state is cleared.

### User Story 3 - The case page says what to do next (Priority: P1)

**Acceptance Scenarios**:
1. **Given** a case awaiting the employee's paper response, **Then** the card says "Record the employee's paper
   response", shows the due date with its badge, and its button scrolls to the hand-off form on the page.
2. **Given** a case waiting on committee approvals, **Then** HR sees "Waiting for approvals (n of 3)" without an
   action button, while an eligible pool member sees "Approve the recommendations" with a button to the approval
   screen.
3. **Given** an archived case, **Then** no card is shown.

## Requirements *(mandatory)*

- **FR-001**: One search service with role-scoped providers built on the existing visibility classes; results capped
  (8 per group), minimum 2 characters, debounced.
- **FR-002**: Arabic-aware normalisation applied to both the stored searchable text and the query.
- **FR-003**: Search UI in the workspace top bar (desktop) and as an icon + full-screen sheet (phone); keyboard
  accessible (combobox/listbox ARIA pattern).
- **FR-004**: Board filters remembered per user; URL wins; clear action.
- **FR-005**: Case page "Next step" card from `NextAction::for(case, viewer)`; button targets an on-page anchor or the
  route of the screen that performs the step.

## Success Criteria *(mandatory)*

- **SC-001**: Search returns results in under 300 ms for 10,000 employees / 5,000 cases on the dev machine (indexed).
- **SC-002**: Automated tests prove no result leaks across companies or roles.
- **SC-003**: For every case status, the card matches what the home page shows the same viewer.

## Assumptions

- Search does not look inside documents, notes or legal texts.
