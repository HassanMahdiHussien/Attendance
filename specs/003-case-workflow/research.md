# Research: Case Workflow Engine

## R1. State machine: explicit map, no package
- **Decision**: `App\Workflow\CaseTransitions` holds a constant map `from → [to…]` over `CaseStatus`.
  `App\Services\Cases\CaseWorkflow::transition(case, to, actor, reason, payload)` locks the case row
  (`lockForUpdate`), refuses `to ∉ map[from]` (`IllegalTransition`), runs the guard registered for that
  edge (`TransitionBlocked` with reason), updates the status and writes a `status_changed` case event
  with from / to / reason / payload, all in one transaction.
- Domain services (`CaseOpening`, `CommitteeSessionService`, `DocumentApprovalService`,
  `HandOffService`, `ConsultationService`, `AppealService`) perform the step's data changes and then
  call `transition()`. Nothing else writes `cases.status` (guard test greps for `'status' =>` writes
  on cases outside the workflow).
- **Edges added beyond the linear §7 list** (§3bb manual fallback and cancel):
  - `open → defense_letter_citation_check` (manual draft); `ai_drafting_defense_letter → open` (cancel or
    AI failure).
  - `ai_ingested_response` is optional: `response_scanned_uploaded → committee_session_held` is allowed
    (manual reading); `ai_ingested_response → response_scanned_uploaded` (cancel).
  - `committee_session_held → recommendations_citation_check` (manual); `ai_drafting_recommendations →
    committee_session_held` (cancel).
  - `recommendations_awaiting_approvals → recommendations_citation_check` (HR edit after rejection,
    which invalidates approvals).
  - `signed_recommendations_uploaded → decision_letter_citation_check` (manual Doc B) or
    `→ archived_final` (no penalty); `ai_verify_and_close → signed_recommendations_uploaded` (cancel).
  - `decision_letter_citation_check → awaiting_external_legal_consultation` only when a consultation
    is requested; `→ decision_letter_ready_for_review` otherwise. Consultation can also be requested
    from earlier open stages; that sets the fields and blocks Doc B issuance until completed.
  - Response stages: `awaiting_employee_response → response_received | response_refused_to_sign |
    response_delivery_refused`. Each of these, and `awaiting_employee_response` itself (no response
    after the deadline), can go to `response_scanned_uploaded`, or straight to `committee_session_held`
    when there is nothing to scan.
- Full map in `contracts/transitions.md`.

## R2. Deadline maths
- **Decision**: `App\Services\Cases\Deadlines` (pure). Anchor = `defense_letter_issued_at` converted to
  an Asia/Baghdad date (`toDateString` in the app timezone). Due = anchor + N days (`addDays`, calendar).
  A deadline is "met" if the event happened on or before the due date, "overdue" after the end of the
  due date, and "approaching" within 48 h of the end of the due date. N values are in
  `config/displanery.php`: response 3, procedure 15, appeal 30.
- The procedure deadline is met when the decision is issued, or the case reaches `archived_final` via
  the no-penalty path.
- Corrections (version 2 of the defense letter) do not move the anchor: it is set only when null.

## R3. Alerts sent once
- **Decision**: table `case_alerts (company_id, case_id, alert_type, stage, sent_at)` with
  unique(case_id, alert_type, stage). Command `cases:send-deadline-alerts` runs hourly from the
  scheduler, iterates companies with `runForCompany`, and inserts the alert row first (insertOrIgnore).
  It notifies only when the row was inserted. Notification `CaseDeadlineAlert` goes via mail + database
  to company admins, the assigned HR user and the opener (active users only). It also writes a
  `deadline_alert_sent` case event.
- Alert types: `procedure_approaching`, `procedure_overdue`, `response_overdue`, `consultation_pending`
  (daily stage key = date), `appeal_window_ending` (≤ 3 days).
- `notifications` table via `php artisan make:notifications-table` (framework, no package).

## R4. Committee
- Mandate in effect: `CommitteeMandate::inEffectOn(defense_letter_issued_at)`. The session is refused if
  none is in effect.
- Eligible pool: that mandate's members with `branch_id IS NULL OR branch_id = case.branch_id`.
- Conflict set: eligible members whose `employee_id` ∈ {case employee, alleged responsible employee,
  case witnesses' employee_ids}. Name-only pool members are matched by exact normalised name against
  witness names (flag only, no refusal) → reported as "possible conflict".
- Quorum: count ≥ 3, any attendee with role `labor_union`, no conflicted attendee.
- Votes: strict majority, `approve > count / 2`. Abstain counts as present, not approve.
- `recordSession(case, attendees[{pool_member_id, vote}], chairId?, minutes, outcome, defenseAcceptance)`
  upserts attendance (re-vote replaces the votes while the case is in `committee_session_held`). The
  transition to drafting is blocked without a majority.

## R5. Doc A approvals
- `DocumentApprovalService::approve(document, user)` / `reject(document, user, comment)`. The user must be
  linked (`committee_pool_members.user_id`) to an eligible pool member of the case's mandate (R4 set);
  the category is that member's `role_type`. If the user is linked to members of two categories, they
  pick the category explicitly, and the one user cannot fill two categories. An existing valid approval
  in that category means "already approved". `languages_present` = current content language codes.
- Invalidation: a `DocumentContent` saving hook on recommendations drafts sets `invalidated_at` on all
  valid approvals of that document and logs `approvals_invalidated`.
- When the third category arrives: transition `recommendations_awaiting_approvals →
  recommendations_confirmed`.

## R6. Hand-offs
- `HandOffService::record(case, purpose, status, data)` creates a `signatures` row. Validation:
  `refused_to_sign` ⇒ 2 distinct witness names; `delivery_refused` ⇒ attempted_at, attempt_location,
  attempted_by_name, 2 witnesses; `signed` ⇒ signed_at. For `response_submission` it also transitions
  the response stage.

## R7. Consultation, leave confirmation, appeal
- Columns on `cases`: `legal_review_completed_at`, `legal_review_completed_by`, `legal_review_note`.
  Request: sets requested_by / at and is_sensitive, case event. Complete: an external reviewer (via
  `CaseVisibility`) records the note. `DocumentIssuer` refuses a decision letter while
  `legal_review_requested_at` is set and `legal_review_completed_at` is null.
- Columns on `documents`: `leave_confirmed_by`, `leave_confirmed_at`. `DocumentIssuer` refuses
  `decision_letter` with `penalty_type = termination` when they are null. Set via
  `CaseWorkflow`-adjacent `TerminationLeaveConfirmation::confirm(document, hrUser)`.
- Appeal: on `decision_issued`, an `appeal_trackings` row with window start/end is created and the case
  moves to `awaiting_appeal_window`. `AppealService::logFiled / logOutcome`. The scheduler archives at
  the window end.

## R8. Opening and laws
- `laws.category` (`labor` | `social_security` | `regulation`), backfilled: 37/2015 and 71/1987 →
  labor, 18/2023 → social_security, internal regulations → regulation. `law_version_id` = the labour
  statute applicable to the jurisdiction, approved, `effective_from ≤ today`, not repealed. Opening is
  refused if none exists.

## R9. Duplicates, priors, patterns
- Duplicate candidates: reports and cases of the same company with the same employee and
  `|occurred_at − x| ≤ 48 h`, excluding self. Banner/pattern queries live in `CaseInsights` (read-only).

## R10. Review resolutions (Codex plan review, 2026-10-05)

- **Doc A edits (A1)**: content changes to a recommendations draft go only through
  `RecommendationsEditor::update(document, contents, hrUser)` (insert / update / delete language rows).
  In one transaction with the document row locked, it writes the contents, sets `invalidated_at` on all
  valid approvals, logs `approvals_invalidated`, and, if the case is in
  `recommendations_awaiting_approvals` or `recommendations_confirmed`, transitions it back to
  `recommendations_citation_check`. A `DocumentContent` deleting/saving hook on a recommendations draft
  outside this service throws. New edge: `recommendations_confirmed → recommendations_citation_check`.
- **Issuance vs stage (A2)**: `CaseDocumentIssuance::issue(document, actor, manualRef)` wraps
  `DocumentIssuer`. A **first** issuance (version 1) of each type is allowed only from its stage
  (defense_letter_confirmed, recommendations_confirmed, decision_letter_ready_for_review) and performs
  that edge's transition. A **corrected** version (version > 1, created by `DocumentCorrector`) can be
  issued at any non-draft case stage, including archived. It never transitions the case and never moves
  `defense_letter_issued_at` / `decision_issued_at`, and it logs `document_corrected_issued` with the
  reason. Direct `DocumentIssuer` calls are reserved to this wrapper (a guard test greps for other
  callers).
- **Status write boundary (A3)**: MySQL `BEFORE UPDATE` trigger on `cases`: if `NEW.status <> OLD.status`
  and `@displanery_workflow_transition` is not `1`, then `SIGNAL 'IMMUTABLE: case status changes only
  through the workflow'`. `CaseWorkflow::transition()` sets the session variable to 1 inside its
  transaction and resets it in `finally`. The model also refuses a dirty `status` outside the workflow
  (clear error before the query). Tests cover Eloquent `update` / `forceFill` / `saveQuietly`, query
  builder and raw SQL bypass attempts, plus a stale concurrent transition (second caller sees the new
  status and gets `IllegalTransition`).
- **Alert outbox (A4)**: `case_alerts` gets `status` (pending | sent | failed), `attempts`, `last_error`,
  `sent_at`, and is no longer append-only (case events remain the immutable record). The command claims
  with insertOrIgnore(pending). It then sends to each recipient. On success it marks the row sent and
  writes a case event; on exception it marks it failed with the error. Later runs retry `failed` rows
  and `pending` rows older than 15 minutes, up to 5 attempts. Unique(case_id, alert_type, stage)
  still guarantees one claim under concurrent runs. Tests: two concurrent claims → one send; a failed
  mail → retried next run → sent once.
- **Consultation timing (B1)**: the request is recorded at any open stage (who / when, `is_sensitive`).
  The case becomes visible to the firm only once the case status is `signed_recommendations_uploaded` or
  later. `CaseVisibility` for external reviewers adds that status condition (CLAUDE.md §3: the external
  review happens after the committee signed Doc A). Doc B issuance stays blocked until completion.
- **Conflicts (B2, orchestrator decision)**: an employee-linked pool member matching the case employee,
  the alleged responsible employee or a witness employee is a hard exclusion. A name-only pool member
  whose normalised name matches any party name (employee, responsible person free text, witness name)
  is excluded by default. HR may clear that warning after checking identity, via
  `ConflictClearance::clear(case, poolMember, reason, hrUser)`, which records who / when / why
  (`case_conflict_clearances` table) and logs a case event.
- **Approval eligibility (B3)**: eligibility is judged and snapshotted at approval time.
  `document_approvals.committee_pool_member_id` records which membership authorised it. `approve`,
  `reject`, `RecommendationsEditor::update` and issuance all lock the document row first, so the last
  approval and an edit cannot interleave.
- **Deadline boundaries (B4)**: "overdue" starts at 00:00 Baghdad on the day after the due date.
  Auto-archive runs only when today (Baghdad) > appeal end date. Response clock: met if the response
  hand-off is recorded on or before the due date; otherwise "missed". A missed response does not block
  the session (the committee may proceed after the deadline).
- **Guard matrix (B5)**: tests name each guard: quorum (2 / 3-without-union / 3-with-union / 4), majority
  (2-1, 1-1-1, 1-2, re-vote), mandate boundary (issued the day before / on / after the new mandate's
  effective date), conflict types (employee-linked × 3 party types, name-only cleared / uncleared), and
  approval cases (non-pool user, second same-category approval, one user linked to two categories, edit
  after confirmation, deletion of a language row).
- Alert mail locale: notifications render in Arabic (`locale('ar')`). A test asserts an Arabic subject.
