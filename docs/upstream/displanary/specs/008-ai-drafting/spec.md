# Feature Specification: AI Drafting with Verified Citations

**Feature Branch**: `008-ai-drafting`

**Created**: 2026-10-07

**Status**: Draft

**Input**: User description: "AI drafting (CLAUDE.md §3b touchpoints 1, 3, 4c and 5; §3bb; §3c; §23.3). The AI turns
human-entered facts and decisions into legal-quality drafts of the defense request letter, the committee
recommendations and the decision letter, in every enabled language with Arabic authoritative, citing only
verified legal-library units by id. Every draft passes the citation checks (code check + an independent AI
relevance check) and the existing human confirmation. A 'write it manually' path is always available.
Scan reading (response transcription, signature presence) is feature 009; email intake is a later feature."

## Clarifications

### Session 2026-10-07

- Q: Split of the AI work? → A: Product owner chose two features: 008 drafting (this), 009 scan reading
  (handwritten response transcription, signature-presence check). Email intake is a later, separate feature.
- Q: What may the AI decide? → A: Nothing. It drafts wording from facts and decisions a person entered
  (incident fields, committee minutes and outcome, approved recommendations). It never supplies facts, the
  outcome, the penalty, dates, names or numbers (CLAUDE.md §3).
- Q: How are articles cited? → A: Only by citation markers pointing at legal-library units the system offered
  for that case; the system prints the article reference and text (CLAUDE.md §23). A typed article number in the
  prose blocks issuance (existing gate).
- Q: What if the AI is unavailable? → A: The case returns to where it was, the screen says "AI unavailable — write
  it manually or try again", and the manual path is identical except `drafted_by = human` (CLAUDE.md §3bb).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - HR gets an AI draft of the defense request letter (Priority: P1)

On an open case, HR presses "صياغة بالذكاء الاصطناعي". The draft is prepared in the background (the page shows
progress and stays usable). When ready, the editor shows the letter body in every enabled language, citation
markers rendered as article references, and a side panel of AI notes (missing information, unclear points). HR
edits freely, then submits it to the citation check and the existing confirmation screen.

**Acceptance Scenarios**:
1. **Given** an open case with the incident fields filled, **When** HR requests a draft, **Then** within the
   background job the draft body exists for Arabic and English (plus Kurdish if enabled), `drafted_by = ai`, and
   the AI generation log records the input given and the output produced.
2. **Given** the AI returns text containing an article number not written as a marker, or a marker for a unit that
   was not offered, **Then** the output is rejected and retried; after the retries the case returns to "open"
   with a clear message and nothing is saved.
3. **Given** the AI is unreachable, **Then** after the retries the case is back in "open", the editor shows "AI
   unavailable — write it manually or try again", and the manual editor works as today.
4. **Given** HR ticked "include prior record", **Then** the draft may mention prior cases only through the
   prior-record block filled from the database, never with numbers or dates typed by the AI.

### User Story 2 - Every cited article is checked twice before anyone can confirm (Priority: P1)

When a draft (AI or manual) with citations is submitted, the system checks each marker (unit exists, verified,
current, applicable to the case — the existing rule) and runs an independent relevance check: a separate AI
call that did not write the draft reads each claim sentence with the cited unit's official text and answers
supports / partially / does not support, with a one-line reason. The confirmation screen shows the result per
citation. "Does not support" blocks confirmation unless the reviewer overrides it with a reason; "partially" is
shown as a warning and also needs an explicit override. Overrides are logged.

**Acceptance Scenarios**:
1. **Given** a citation judged "supports", **Then** it shows green and does not block.
2. **Given** a citation judged "does not support", **Then** issuance is blocked until a reviewer overrides it with a
   reason (logged with who and when) or edits the draft (which re-runs the check).
3. **Given** the relevance check cannot run (AI unavailable), **Then** the citation stays "not checked", which
   blocks issuance until the check runs or a reviewer overrides each citation with a reason.
4. **Given** the cited unit was corrected (superseded) after drafting, **Then** issuance shows "النص القانوني
   صُحح — حدّث الاستشهاد" and offers to replace the marker with the current version (re-check follows).

### User Story 3 - The committee's decision becomes the recommendations draft (Priority: P1)

After the session is recorded (typed minutes, outcome, defense acceptance), HR presses "صياغة التوصيات بالذكاء
الاصطناعي". The AI drafts the findings and the recommendations sections in every enabled language from the
minutes and the recorded outcome only. The prior-cases paragraph comes from the database. HR edits and submits
for the three approvals as today.

**Acceptance Scenarios**:
1. **Given** minutes and outcome "warning", **Then** the printed recommendation sentence states a warning from
   the recorded outcome; AI text that mentions a different penalty is flagged to the reviewer.
2. **Given** no minutes recorded, **Then** the AI action is not offered (the manual editor still is).

### User Story 4 - The decision letter is drafted from the approved recommendations (Priority: P2)

After the signed recommendations are uploaded and the outcome includes a penalty, HR presses "صياغة كتاب القرار
بالذكاء الاصطناعي". The AI drafts the reasons paragraph from the issued recommendations text and the recorded
outcome, penalty and defense acceptance. The rest of the letter (penalty sentence, defense-outcome sentence,
signers) stays template text filled from data.

**Acceptance Scenarios**:
1. **Given** issued recommendations recommending termination, **Then** the draft body supports a termination
   decision and the leave confirmation is still required before issuance.

### User Story 5 - Manual writers can cite verified articles too (Priority: P2)

In every editor (AI-drafted or manual), "إدراج مادة" opens a search over the units citable for this case
(article, clause, first words of the text). Choosing one inserts a marker at the cursor. The same checks apply.

**Acceptance Scenarios**:
1. **Given** a manual draft with an inserted marker, **When** submitted, **Then** the relevance check runs for it
   exactly as for an AI draft.

### Edge Cases

- No citable units for the case's jurisdiction (library not yet verified): the AI drafts without citations and
  adds a side note "لا توجد مواد موثقة قابلة للاستشهاد"; issuance is not blocked by the absence of citations.
- The AI output for one language is missing or empty: rejected and retried; never save a partial set.
- The AI changes a fact (a different date, place, outcome or penalty than entered): facts are not in the body by
  design (template placeholders carry them); a body that states an outcome or penalty different from the recorded
  one is rejected by a deterministic check.
- Two drafting requests at once for the same document: the second is refused while one is running.
- A draft edited by a person after the relevance check: the check result is cleared and re-runs at the next submit.
- The case moves on (e.g. reassigned, status changed) while a job runs: the job saves nothing if the case is no
  longer in the drafting state it started from.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The system MUST offer AI drafting for the defense request letter (case open), the recommendations
  (session recorded with minutes and a majority) and the decision letter (signed recommendations uploaded,
  penalty outcome), each running as a background job with a visible progress state.
- **FR-002**: The AI input MUST be limited to the facts and decisions needed for that document: incident fields
  (violation type, place, time, description, alleged responsible person as entered), prior-record summaries
  (type, date, outcome) when allowed, committee minutes and recorded outcome/defense acceptance, the issued
  recommendations text, and the candidate legal units. It MUST NOT include national ID numbers, salaries, phone
  numbers, addresses or other personal data not needed for the wording.
- **FR-003**: Before drafting, the system MUST select candidate units: only units citable for the case (existing
  rule), chosen by the units' topic tags for discipline and by keyword overlap with the violation type and
  description, capped at a configurable number (default 30), each passed with its id.
- **FR-004**: The AI MUST return structured output: per enabled language the body (and, for recommendations, the
  findings and the recommendations sections), using `[[cite:<unit_id>]]` markers only, plus internal notes. Output
  not in this structure, with a marker for a unit not offered, with an article number in prose, or with a missing
  language MUST be rejected and retried (up to 2 retries with backoff), then reported as unavailable.
- **FR-005**: The Arabic text is authoritative; the other languages are renderings of it. The prompt MUST say so.
- **FR-006**: Saving an AI draft MUST create or update the document draft through the existing services with
  `drafted_by = ai`, create the citation rows for the markers, store the AI notes for the draft, and write the AI
  generation log (input, output, model, tokens, duration, status). Notes are never inserted into the letter.
- **FR-007**: The AI MUST NOT write the operative decision: the recommended outcome and the decision letter's
  penalty and defense-outcome sentences are template text filled from the recorded decision. An outcome/penalty
  keyword detector (Arabic, English) flags drafts that mention a different penalty, shown to the reviewer; every
  number or date in AI prose MUST occur in the request input, otherwise the output is rejected and retried.
- **FR-008**: On submit of any draft with citations (AI or manual), the system MUST run the independent relevance
  check (a separate AI call that is given only each claim sentence and the cited unit's official text) and store
  supports / partial / does not support with a one-line reason per citation. The result is cleared when the text
  changes.
- **FR-009**: Issuance MUST stay blocked for a claim whose citation is not "supports" (including not checked)
  unless a reviewer overrides it with a reason; overrides are logged in the audit log (who, when, the flagged
  result). The check runs while the draft is edited, so results and overrides are visible before submit. Each
  claim (sentence containing a marker) is checked separately.
- **FR-010**: When the AI is unavailable or the output keeps failing validation, the case MUST return to the state it
  was in before drafting, with a clear message, and the manual path MUST remain available. No case can stay in an
  AI drafting state after its job ends.
- **FR-011**: Every editor MUST offer "إدراج مادة" to insert a marker for a unit citable for the case.
- **FR-012**: Citations MUST render in each language: Arabic "المادة (X) البند (Y) من <اسم القانون>" and English
  "Article X(Y) of <English law name>"; laws therefore carry an English name, editable by the Platform Admin.
- **FR-013**: A unit superseded after it was cited MUST be offered for one-click replacement with its current
  version; replacement re-runs the relevance check.
- **FR-014**: The Platform Admin MUST be able to tag units with discipline topics in the library (used by FR-003).
- **FR-015**: Kurdish is drafted only when the company enabled it (existing per-company setting); otherwise only
  Arabic and English.

### Key Entities

- **AI draft note**: internal notes the AI returned with a draft (missing information, unclear points, items to
  double-check); belongs to one document draft and one generation log; never printed.
- **Citation** (existing): document, legal unit (exact version), claim sentence, relevance result and reason,
  override (who, when, reason).
- **Law** (existing): gains an English name.
- **Legal unit tags** (existing JSON): gains discipline topic tags managed by the Platform Admin.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: An HR user gets a complete bilingual defense-letter draft in under 2 minutes from pressing the button
  (when the AI is reachable).
- **SC-002**: 100% of issued documents contain only article references produced from verified units, each with a
  "supports" result or a logged override.
- **SC-003**: With the AI unreachable, a case is never stuck: 100% of failed jobs leave the case in its previous
  state with the manual path available.
- **SC-004**: No AI request contains national ID numbers, salaries or phone numbers (verified by automated tests
  on the request payloads).
- **SC-005**: A reviewer sees every relevance problem on the confirmation screen before confirming; no "does not
  support" citation can be issued without a logged override.

## Assumptions

- The legal library (feature 007) supplies the units; until real laws are verified, drafting works without
  citations and says so in its notes.
- Model: Claude Opus 5.5 (`claude-opus-5-5`) for drafting and for the relevance check, configurable; the relevance
  check is a separate request with no access to the drafting conversation.
- The prior-record paragraph of the recommendations and (when ticked) the defense letter is printed from the
  database by the templates (feature 004); the AI does not write it.
- Facts such as names, dates, reference numbers, deadlines and the penalty sentence are template placeholders
  filled from data (feature 004); the AI writes only the case-specific prose sections.
- Response transcription and signature-presence reading are feature 009; until then the recommendations draft uses
  the minutes, not the employee's response text.
