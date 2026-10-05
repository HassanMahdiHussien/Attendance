# Tasks: Case Workflow Engine

**Input**: `/specs/003-case-workflow/` (spec, plan, research R1–R10, data-model, contracts/transitions, quickstart)
**Tests**: REQUIRED (Constitution IV). Pest on MySQL, explicit tenant context, `travel()` for clocks.
**Execution**: Codex run F = Phases 1–3 + US2 + US5; Codex run G = US3 + US4 + US6 + end-to-end + docs.
The orchestrator runs `docker compose exec -T laravel.test php artisan test` and `… ./vendor/bin/pint --test`
after each run.

## Phase 1: Setup (run F)
- [x] T001 Migrations per data-model.md: laws.category (+ backfill), cases consultation/majority columns, documents leave-confirmation columns, document_approvals.committee_pool_member_id, case_alerts outbox, case_conflict_clearances, notifications table
- [x] T002 Trigger migration: recreate the locked-documents trigger with the new protected columns; add the cases status-boundary trigger (R10); reversible `down()`
- [x] T003 `config/displanery.php` deadlines block; update LawsSeeder with categories

## Phase 2: Foundational (run F)
- [x] T004 `app/Workflow/CaseTransitions.php` (exact map from contracts/transitions.md); exceptions IllegalTransition, TransitionBlocked
- [x] T005 `CaseWorkflow::transition()` (row lock, map check, guard hook, session variable for the trigger, `status_changed` event); model guard refusing dirty status outside the workflow
- [x] T006 `CaseDocumentIssuance` wrapper around DocumentIssuer (first-issuance stage rules + transitions; corrected versions without transition, anchors set only when null) (R10)

## Phase 3: US1 state machine (P1) (run F)
- [x] T007 [US1] `CaseOpening` (draft → open: facts, case number, jurisdiction, law_version_id via laws.category; prior links; conflicts; insights) (R8, R9)
- [x] T008 [P] [US1] `tests/Feature/Workflow/TransitionMatrixTest.php`: every (from, to) pair: allowed pairs pass when guards hold, all others refused
- [x] T009 [P] [US1] `tests/Feature/Workflow/StatusWriteGuardTest.php`: Eloquent / forceFill / saveQuietly / builder / raw SQL bypasses refused; stale concurrent transition refused; DocumentIssuer called only from the wrapper (grep)

## Phase 4: US2 deadlines & alerts (P1) (run F)
- [x] T010 [US2] `Deadlines` service (R2, R10 boundaries)
- [x] T011 [US2] `case_alerts` outbox + `cases:send-deadline-alerts` + `CaseDeadlineAlert` (mail + database, Arabic) + `cases:archive-expired-appeals`; scheduled hourly in routes/console.php
- [x] T012 [P] [US2] `DeadlinesTest.php`: ≥ 20-row date table (month ends, leap day, year end, 23:30 Baghdad, 18 Jun → 21 Jun / 3 Jul, 10 Aug → 9 Sep), status boundaries
- [x] T013 [P] [US2] `AlertsTest.php`: send once on repeat runs; concurrent claim; failed delivery retried then sent once; recipients; Arabic subject; auto-archive only after the end date

## Phase 5: US5 intake checks (P2) (run F)
- [x] T014 [US5] `CaseInsights` (duplicate candidates, prior-cases banner, responsible-person pattern), `ConflictClearance`
- [x] T015 [P] [US5] `IntakeChecksTest.php`

**Checkpoint run F**: gates green → review → commit.

## Phase 6: US3 committee (P1) (run G)
- [ ] T016 [US3] `CommitteeSessionService` (R4 + R10 conflicts; re-vote; majority flag; minutes, outcome, defense acceptance)
- [ ] T017 [P] [US3] `CommitteeSessionTest.php`: full guard matrix from R10

## Phase 7: US4 approvals (P1) (run G)
- [ ] T018 [US4] `DocumentApprovalService` (approve / reject, eligibility snapshot, document lock) + `RecommendationsEditor` (atomic edit + invalidation + return to citation check; hooks refuse other content writes on recommendations drafts)
- [ ] T019 [P] [US4] `ApprovalGateTest.php`: R10 approval cases

## Phase 8: US6 hand-offs, consultation, appeal, leave (P2) (run G)
- [ ] T020 [US6] `HandOffService`, `ConsultationService` (request any open stage; reviewer access only from signed Doc A; completion), `AppealService`, `TerminationLeaveConfirmation`; DocumentIssuer gates for consultation and leave; `CaseVisibility` external-reviewer status condition
- [ ] T021 [P] [US6] `HandOffTest.php`, `ConsultationAppealLeaveTest.php`

## Phase 9: End-to-end & docs (run G + orchestrator)
- [ ] T022 `EndToEndTest.php`: warning path (manual drafting) and no-penalty path, asserting the full case-history sequence and deadlines (SC-005)
- [ ] T023 [P] WEIRDNESS: status trigger boundary; corrections never move anchors or stage; consultation visible only after signed Doc A; name-only conflict clearance; alert outbox. docs/ERD.md: new tables / columns
- [ ] T024 Orchestrator: gates, quickstart V3–V5, PR

## Dependencies
Phase 1 → 2 → US1 → (US2, US5) → US3 → US4 → US6 → E2E.
