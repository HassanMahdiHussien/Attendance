# Tasks: Case Screens — Committee to Archive

**Tests**: REQUIRED (Pest + Livewire update endpoint). Gates (as sail):
`docker compose exec -T -u sail laravel.test php artisan test` · `… ./vendor/bin/pint --test`.

## Run L
- [x] T001 RecordSession screen (eligible pool by mandate in effect + branch, conflicts excluded / clearance, chair default, votes, minutes, outcome, defense acceptance, Arabic quorum errors, re-vote); authorised for HR / admin; minutes_typed_by (attending pool member) per research R1
- [x] T002 RecommendationsEditor::create/submitForApproval + EditRecommendations (findings + recommendations per enabled language, preview, submit, resubmit after rejection); DocumentFacts attendee/chair fix (R1 A3) + approvers line
- [x] T003 "اللجنة" PendingApprovals list + ReviewApproval (all languages side by side, approve / reject with comment, read-only text); PoolEligibility (R1 B2) used by service, list and ApprovalNeeded notification; rejection alert to HR (R1 B3)
- [x] T004 ConfirmDocument generalised to recommendations and decision letters (missing-approval categories shown; consultation / leave blockers shown)
- [x] T005 SignaturePresence::recordSignedScan atomic + overrideEmpty (R1 A4) + SignedScan screen; CaseClosure::closeWithoutPenalty with consultation guard; ConsultationService::cancel (R1 A5)
- [x] T006 Tests for T001–T005 incl. Livewire update-endpoint authorisation per role and stale stage

## Run M
- [ ] T007 DocumentDrafts decision letters + submitDecision (R1 A2) + EditDecisionLetter + leave confirmation (termination) + receipt hand-off + optional GM/union signature presence record
- [ ] T008 Consultation request button (any open stage; prominent pending banner with clock) + Reviewer consultation screen (read case, documents, minutes, scans; complete with note) gated by CaseVisibility
- [ ] T009 Appeal screen (log filed + date, outcome → archive); archived cases read-only everywhere
- [ ] T010 Default templates: approved outcome wording (ar / en) and female role labels; DocumentFacts attendee gender; renderer picks labels
- [ ] T011 Tests (incl. service-level end-to-end both paths per R1 B6): role × action matrix (HR, admin, each pool category, non-pool committee member, partnered / unpartnered reviewer, reporter); termination leave gate; consultation gate; appeal flow; "no verification wording" view scan; wording / label rendering
- [ ] T012 docs: WEIRDNESS (session can be recorded by HR or an attending pool member; presence record is manual until the AI touchpoint); orchestrator walkthrough of both paths; PR
