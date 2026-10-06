# Tasks: Case Screens — Committee to Archive

**Tests**: REQUIRED (Pest + Livewire update endpoint). Gates (as sail):
`docker compose exec -T -u sail laravel.test php artisan test` · `… ./vendor/bin/pint --test`.

## Run L
- [ ] T001 RecordSession screen (eligible pool by mandate in effect + branch, conflicts excluded / clearance, chair default, votes, minutes, outcome, defense acceptance, Arabic quorum errors, re-vote); authorised for HR / admin / attending pool members with login
- [ ] T002 EditRecommendations (findings + recommendations per enabled language, preview, submit → citation check → awaiting approvals)
- [ ] T003 "اللجنة" PendingApprovals list + ReviewApproval (all languages side by side, approve / reject with comment, read-only text); ApprovalNeeded notification (mail + database) to eligible pool members with login when awaiting approvals
- [ ] T004 ConfirmDocument generalised to recommendations and decision letters (missing-approval categories shown; consultation / leave blockers shown)
- [ ] T005 SignedScan: upload signed_document_scan → signed_recommendations_uploaded; SignaturePresence (per attendee filled / appears empty, wording never "verified"); CaseClosure for recommendation-only → archived_final
- [ ] T006 Tests for T001–T005 incl. Livewire update-endpoint authorisation per role and stale stage

## Run M
- [ ] T007 EditDecisionLetter (reasons per language, penalty from outcome) + leave confirmation checkbox (termination) via TerminationLeaveConfirmation + receipt hand-off for the decision letter
- [ ] T008 Consultation request button (any open stage; prominent pending banner with clock) + Reviewer consultation screen (read case, documents, minutes, scans; complete with note) gated by CaseVisibility
- [ ] T009 Appeal screen (log filed + date, outcome → archive); archived cases read-only everywhere
- [ ] T010 Default templates: approved outcome wording (ar / en) and female role labels; DocumentFacts attendee gender; renderer picks labels
- [ ] T011 Tests: role × action matrix (HR, admin, each pool category, non-pool committee member, partnered / unpartnered reviewer, reporter); termination leave gate; consultation gate; appeal flow; "no verification wording" view scan; wording / label rendering
- [ ] T012 docs: WEIRDNESS (session can be recorded by HR or an attending pool member; presence record is manual until the AI touchpoint); orchestrator walkthrough of both paths; PR
