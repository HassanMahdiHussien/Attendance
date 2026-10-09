# Feature Specification: Law vs Internal-Regulation Comparison Before Drafting

**Feature Branch**: `021-law-regulation-conflicts`

**Created**: 2026-10-09

**Status**: Draft

**Input**: CLAUDE.md §23.4 "When drafting, the law comes first" — gap found in the pilot-readiness audit
(2026-10-08): today only candidate ordering and one prompt sentence express the rule; nothing shows the matching law
and regulation side by side, records conflicts, or recognises a worker-favourable regulation.

## Clarifications

### Session 2026-10-09

- Q: Where is a conflict recorded? → A: As an internal review note (both texts quoted, both unit ids stored) visible
  to HR, the committee and the external lawyer on the review/confirmation screens and kept on the case — never
  printed in the issued letter, which simply cites the law (product owner).
- Q: Who confirms the comparison? → A: HR, before drafting: HR sees the matched law/regulation pairs side by side
  with the AI's suggestion and accepts or changes each verdict; the draft is then written from the confirmed
  comparison (product owner).
- Q: When does this step apply? → A: Only when the case's company has approved internal-regulation units that are
  citable for the case (§23.4: unapproved regulation articles are not citable). Otherwise drafting works as today.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - HR compares before drafting (Priority: P1)

HR presses "Draft with AI" for the defense letter (touchpoint 1) or the recommendations (touchpoint 3) in a company
with an approved regulation. Instead of drafting immediately, the system shows "Compare the law and your internal
regulation": a list of pairs — labour-law article (official text) beside the matching regulation article (official
text) — each with the AI's suggestion and a one-line reason: **Consistent**, **Conflict — the law applies**, or
**Regulation gives the worker more** (not a conflict). HR accepts or changes each verdict (or marks a pair "not
relevant"), then confirms; the AI draft starts from the confirmed comparison.

**Acceptance Scenarios**:
1. **Given** a confirmed "Conflict" pair, **Then** the draft cites the law article and never the conflicting
   regulation article, and an internal note quotes both texts.
2. **Given** a confirmed "Consistent" or "Regulation gives the worker more" pair, **Then** the draft may cite both.
3. **Given** the AI is unavailable, **Then** HR can build the comparison manually (pick the law article and the
   regulation article and set the verdict) or skip it with a reason; drafting is never blocked by the comparison
   (CLAUDE.md §3bb).
4. **Given** a company without an approved regulation, **Then** no comparison step appears.

### User Story 2 - Reviewers see the comparison (Priority: P1)

On the document review/confirmation screen, the committee approval screen and the lawyer's consultation screen, a
"Law vs internal regulation" panel shows the confirmed pairs, verdicts, reasons, both texts, who confirmed and when.

## Requirements *(mandatory)*

- **FR-001**: A comparison record per document draft request: pairs (law unit id, regulation unit id, AI suggestion,
  AI reason, HR verdict, changed-by-HR flag), who confirmed, when, origin (AI / manual / skipped with reason).
  Append-only once confirmed; a new draft request creates a new comparison.
- **FR-002**: Only verified, citable units (`LegalUnit::citableFor`) of the case's jurisdiction and the company's
  approved regulation can appear; texts shown are the library's official texts.
- **FR-003**: The drafting prompt receives the confirmed pairs; regulation units in "Conflict" pairs are removed from
  the citable candidates for that draft; the citation gate rejects them if cited anyway.
- **FR-004**: The AI comparison is a suggestion — no verdict takes effect until HR confirms; all calls logged in
  `ai_generation_logs`; confirmations and changes in the audit log (ids and verdicts, no free personal data).
- **FR-005**: Translated, design-system components, phone layout.

## Success Criteria *(mandatory)*

- **SC-001**: A confirmed conflict's regulation article can never be cited in that draft (automated test).
- **SC-002**: Drafting without a regulation, or with the AI unavailable, works exactly as before or through the
  manual path.

## Assumptions

- Kurdistan/federal jurisdiction rules for law units are already enforced by `citableFor`.
