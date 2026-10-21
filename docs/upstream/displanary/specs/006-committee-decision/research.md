# Research: Case Screens — Committee to Archive

## R1. Review resolutions (Codex plan review, 2026-10-06). These win over spec / plan text where they differ

- **Session recording (A1, orchestrator decision)**: only company_admin / hr_staff (case visible) call
  `CommitteeSessionService::recordSession`. The minutes form has a required "كتبها" select with the
  attending pool member who typed the minutes. It is stored as `cases.minutes_typed_by_pool_member_id`
  (new, composite FK) and printed nowhere but shown in the timeline and case page. This keeps CLAUDE.md
  §3b (the minutes are a committee member's notes) without giving committee accounts state-changing
  power before attendance exists. The spec clarification is updated accordingly.
- **Document paths (A2)**:
  - `RecommendationsEditor::create(case, contents, hr)` creates the first Doc A draft only in
    `committee_session_held` with a majority. `submitForApproval(document, hr)` runs
    committee_session_held | recommendations_citation_check → recommendations_citation_check →
    recommendations_awaiting_approvals (CitationGate) and notifies the eligible pool.
  - After a rejection (the case is in citation_check), HR edits through `update` and calls
    `submitForApproval` again; fresh approvals are needed.
  - `DocumentDrafts` gains decision letters (penalty_type from `cases.outcome`; refused for
    recommendation_only). `submitDecision(document, hr)` runs signed_recommendations_uploaded →
    decision_letter_citation_check → (consultation pending ? awaiting_external_legal_consultation :
    decision_letter_ready_for_review). `ConsultationService::complete` moves awaiting_external… →
    ready_for_review.
- **Chair and attendees vs renderer (A3)**: CLAUDE.md §4 requires ≥ 3 attendees with a labour-union
  member. A Legal attendee is not required, and the chair defaults to the Legal attendee when present,
  otherwise HR picks one. `DocumentFacts` is fixed to print the actual attendees with their own role
  label and the chair label for the `is_chair` attendee, whatever the role. It no longer requires one
  attendee per category or a Legal chair. Doc A's three approvals still come from the three pools
  (approvers need not be attendees). Test: union + 2 HR attendees with an HR chair renders and issues.
- **Signed Doc A (A4, B4)**: `SignaturePresence::recordSignedScan(document, file, presence[attendance_id
  => filled|empty], hr)` runs in one transaction. It stores the attachment (signed_document_scan on the
  issued Doc A), writes one `signatures` row per actual attendee (purpose committee_signature,
  committee_pool_member_id, presence_check, presence_checked_at, checked_by), and transitions
  awaiting_committee_signatures → signed_recommendations_uploaded only when every box is `filled`.
  With any `empty`, the case stays put and the case page shows "خانة التوقيع تبدو فارغة" per attendee.
  Resolution is a new scan (new rows, old ones kept) or `overrideEmpty(document, reason, company_admin)`
  (audited, then transition). For Doc B, an optional record of the GM / union paper signatures via the
  same service (purposes general_manager_signature / union_signature) is allowed after issuance; it is
  not a gate.
- **Consultation vs no-penalty close (A5, orchestrator decision)**: `CaseClosure::closeWithoutPenalty`
  and the CaseWorkflow guard for signed_recommendations_uploaded → archived_final refuse while a
  consultation is requested and not completed. `ConsultationService::cancel(case, reason,
  company_admin)` records the cancellation (case event + audit + `legal_review_cancelled_at/by/reason`,
  new columns), after which closure is allowed. Completion after archive is refused.
- **Approver names on Doc A (product question 2, orchestrator decision)**: signature lines = actual
  attendees (as in the samples). Below them, one line: "اعتُمدت في النظام من: {legal approver}،
  {hr approver}، {union approver}" ("Approved in the system by: …"), taken from the valid approvals
  at issuance (CLAUDE.md §3 injection rule).
- **Rejection loop (B1)**: tests cover a rejection after 1 and 2 approvals, edit, resubmit, the
  re-notification and three fresh approvals before issuance.
- **One eligibility rule (B2)**: `PoolEligibility::forDocument(document)` returns the eligible
  (pool member, user, category) tuples: mandate in effect, branch or company-wide, linked active user,
  not conflicted (or cleared), category not yet satisfied. `DocumentApprovalService`, the "اللجنة"
  list, and the ApprovalNeeded recipients all use it. Multi-category accounts appear once, with a
  category choice.
- **Who is alerted (B3)**: on rejection, the opener, the assigned HR and company admins (mail +
  database, alert-only wording). When a requested consultation becomes visible (signed Doc A), active
  reviewers of the partnered firm get a database + mail notification (alert-only, no case content in
  the email body).
- **Template defaults vs company versions (B5)**: the default PHP blocks gain the approved outcome
  wording and `chair_label_female` / `member_label_female` (ar: رئيسةً / عضوةً; en: Chairwoman /
  Member). The validator and editor accept the new optional keys. A company with its own version
  keeps its wording; when the female keys are missing from its version, the renderer falls back to the
  default female labels, otherwise the masculine. Issued PDFs stay frozen.
- **Real paths in tests (B6)**: a service-level end-to-end test for each outcome path uses only the
  public services (no direct status writes). It covers re-vote, rejection and resubmission, an empty
  signature then rescan, pending consultation then completion, decision receipt refused with
  witnesses, an appeal filed with outcome, and the no-appeal scheduler closure.
- `SessionScreenData` is dropped (not needed).
