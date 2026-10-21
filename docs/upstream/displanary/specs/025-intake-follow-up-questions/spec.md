# Feature Specification: AI Follow-Up Questions at Intake

**Feature Branch**: `025-intake-follow-up-questions`

**Created**: 2026-10-09

**Status**: Draft

**Input**: Product-owner feedback (2026-10-09): "when open case, who is type case must be asked about case, for example
'حدث شجار بين عاملين عند خط الإنتاج 3 صباح اليوم.' what is the name of employees, and what happen in details, why
happened and how — AI must ask this question if there is no explanation."

## Clarifications

### Session 2026-10-09

- Q: Who answers? → A: The person writing the report or the case — never the AI (product owner: "Ask, human
  answers"). The AI only reads the description and lists what is missing as questions. Its questions must be neutral
  (no suggested answer, no assumption of guilt, no legal conclusion). Answers typed by the person are facts entered by
  a human (CLAUDE.md §3: the AI never supplies facts).
- Q: Where? → A: On both the reporter's form (SubmitReport) and HR's case form (CreateCase, including when HR
  completes a draft from a report or email).
- Q: When? → A: After the description is written, the person presses "Check my description". It is not automatic on
  every keystroke and does not block submission.
- Q: Must every question be answered? → A: No. Each question can be answered or left open (the reporter may not know).
  Unanswered questions stay visible as "open points" to HR on the report/draft and on the case page, so HR can collect
  the missing facts before the defense letter.
- Q: Where do answers go? → A: Stored as question/answer pairs linked to the report and carried to the case (not
  merged into the description text), shown under the description, and included in the facts the AI receives when
  drafting the defense letter (touchpoint 1) — the answers as facts, the questions only as labels.
- Q: AI unavailable or no API key? → A: The same button shows a fixed checklist (who exactly was involved; what
  happened step by step; when exactly; where exactly; why it happened, if known; how it happened; who saw it; any
  evidence) — the form never depends on the AI (CLAUDE.md §3bb spirit).
- Q: Language? → A: Questions appear in the person's interface language; answers are kept as typed.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Reporter is asked what is missing (Priority: P1)

**Acceptance Scenarios**:
1. **Given** a reporter wrote only "A fight happened between two workers at production line 3 this morning",
   **When** they press "Check my description", **Then** within seconds they see questions such as "What are the
   names of the workers involved?", "What exactly happened, step by step?", "Why did it happen, if known?", "How did it
   end?", "Who saw it?", each with an answer box.
2. **Given** they answer three and leave two empty, **When** they submit, **Then** the report saves with the three
   answers and two open points.
3. **Given** a complete description, **Then** the check says the description looks complete (no questions).
4. **Given** the AI is unavailable, **Then** the fixed checklist appears instead and submission still works.

### User Story 2 - HR sees answers and open points (Priority: P1)

**Acceptance Scenarios**:
1. **Given** a report with answers and open points, **When** HR opens it in the inbox / case form, **Then** both are
   shown under the description; HR can answer open points and run the check again.
2. **Given** a case opened from that report, **Then** the case page shows the questions and answers, and the defense
   letter AI payload includes the answers as facts.

## Requirements *(mandatory)*

- **FR-001**: New AI touchpoint `intake_questions` (queued job, retries + clear unavailable state, logged in
  `ai_generation_logs` with input and output). Input: description, violation type name, place, time — nothing else
  (no national IDs, salaries, phone numbers; CLAUDE.md §20). Output: structured `{complete: bool, questions:
  [{aspect: who|what|when|where|why|how|witnesses|evidence|other, question}]}` with at most 6 questions, in the
  requested interface language; any other shape is rejected.
- **FR-002**: Prompt rules: ask only about information missing from the description; neutral wording; never propose
  an answer, a cause, a culprit, a penalty or a legal article.
- **FR-003**: Storage: question/answer rows attached to a report and/or a case (tenant-scoped, composite FKs), each
  with source (`ai` | `checklist`), aspect, question text, answer (nullable), who answered and when. Carried from
  report to case on conversion; email drafts behave like reports.
- **FR-004**: The forms poll the queued result (no AI call inside the web request). A new check replaces the
  previous unanswered questions; answered pairs are kept.
- **FR-005**: Display: inbox report view, case form and case page show answered pairs and open points (internal
  case view only — not printed in documents; the AI may use the answers when drafting).
- **FR-006**: Touchpoint 1 payload adds the answered pairs as facts (minimised like the rest of the payload).
- **FR-007**: Audit: each check request and each answer save is recorded (case event once a case exists; audit log
  before).
- **FR-008**: Rate limit: at most 5 checks per user per 10 minutes.

## Success Criteria

- A short description yields targeted questions; answers flow to the case and the defense-letter draft.
- With no API key the checklist appears and nothing blocks the report or the case.
