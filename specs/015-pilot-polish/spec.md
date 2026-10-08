# Feature Specification: Pilot Polish and Witnessed-Refusal Evidence

**Feature Branch**: `015-pilot-polish`

**Created**: 2026-10-08

**Status**: Draft

**Input**: Pilot-readiness audit (2026-10-08, Codex read-only audit verified by the orchestrator) and the product
owner's choices: (1) demo polish; (2) a refused hand-off requires a scan of the paper the two witnesses signed;
(3) committee invite links are not built now; (4) the 48-hour duplicate check stays at HR intake.

## Clarifications

### Session 2026-10-08

- Q: Refused hand-offs — names only or signed evidence? → A: Keep the names, **and** require an uploaded scan of the
  paper both witnesses signed (CLAUDE.md §3 "2 witness signatures"). Applies to both refusal kinds (received but
  refused to sign; delivery refused) at all three hand-off points.
- Q: Committee invite links? → A: Not now. Committee members use accounts. Recorded in WEIRDNESS.
- Q: Duplicate check at report submission? → A: No — it stays when HR opens the case, because telling a reporter that
  a case exists would leak case information a reporter must never see (CLAUDE.md §8). Recorded in WEIRDNESS.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Arabic everywhere a user can see (Priority: P1)

An HR user who triggers any blocked action (missing witnesses, missing language content, quorum not met, …) reads
the reason in Arabic. No English sentence reaches an Arabic screen.

**Acceptance Scenarios**:
1. **Given** any user-facing blocking message raised by a service (`TransitionBlocked`, `IssuanceBlocked` and the
   other `*Blocked` exceptions) or a model validation message shown on a screen, **Then** its text is Arabic.
2. **Given** the automated check, **Then** it fails if a new English user-facing blocking message is added.

### User Story 2 - A proper entry page (Priority: P1)

Visiting `/` shows the product name and a sign-in button (and sends a signed-in user to their workspace) instead of
"المنصة قيد الإعداد".

### User Story 3 - Refusals carry the witnesses' signed paper (Priority: P1)

When HR records "refused to sign" or "delivery refused" at any of the three hand-offs, the form also requires
uploading the scan of the paper on which both witnesses signed (PDF/JPEG/PNG, existing limits). The scan is stored as
delivery proof on the case, linked to that hand-off record, listed on the case page next to the witness names, and
kept like every other scan (no deletion).

**Acceptance Scenarios**:
1. **Given** a refusal without a scan, **Then** it is refused with an Arabic reason and nothing is recorded.
2. **Given** a refusal with names and a scan, **Then** the hand-off record links the scan, the case moves as before,
   and the case events log records it (ids only).
3. **Given** a signed hand-off (employee signed), **Then** no witness scan is required (unchanged).

## Requirements *(mandatory)*

- **FR-001**: Every user-facing blocking/validation message is Arabic; technical/log-only exceptions may stay English.
- **FR-002**: A test scans the service layer and fails on English user-facing blocking messages.
- **FR-003**: `/` is an entry page with sign-in; signed-in users are redirected to their home.
- **FR-004**: Refused hand-offs require a witness-signed scan stored as `delivery_proof` and linked to the signature
  record; shown on the case page.
- **FR-005**: WEIRDNESS records: duplicate check placement; invite links deferred.

## Success Criteria *(mandatory)*

- **SC-001**: No English sentence appears on any Arabic workflow screen during the end-to-end walkthrough.
- **SC-002**: A refusal can never be recorded without a witness scan (automated test).

## Assumptions

- Existing refusal records (dev/demo data) without a scan stay as they are; the rule applies to new records.
