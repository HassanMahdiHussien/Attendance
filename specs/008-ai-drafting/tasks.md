# Tasks: AI Drafting with Verified Citations

**Tests**: REQUIRED (Pest + Livewire, fake Claude client, no network). Gates (as sail):
`docker compose exec -T -u sail laravel.test php artisan test` · `… ./vendor/bin/pint --test`.
Never run two test processes at once (shared testing DB and fake disk).

Research R12 (plan review resolutions) overrides earlier research sections.

## Run P — services
- [x] T001 Migrations per data-model.md (laws.name_en + seed values, citations.override_reason + citations trigger update with working down(), ai_draft_notes append-only, legal_unit_topics); models; tenancy test registries
- [x] T002 ClaudeClient (structured calls, logging, exception mapping, refusal/max_tokens handling, AiUnavailable without key) + FakeClaudeClient for tests; config keys
- [x] T003 PayloadBuilder per document type (allow-list, R2) + CandidateUnits (R3) + DraftPrompt (Arabic authoritative, markers only, facts not to be invented, notes) + DraftValidator + OutcomeConsistency (R4, R5)
- [x] T004 AiDrafting::request + DraftDocumentWithAi job (save through existing services, drafted_by ai, notes, citations sync, previous-state return, stale-job guard, retries) + ai:reconcile-drafting command + schedule
- [x] T005 CitationSync (markers ↔ rows, citable check, claim sentences) wired into DocumentDrafts / RecommendationsEditor saves; RelevanceCheck job on submit + clear on edit; CitationOverride; CitationRefresh; CitationGate uses override_reason (override requires a reason)
- [x] T006 CitationRenderer English rendering (name_en, clause numbers/letters, R9); LawManagement::setUnitTopics + topics copied on UnitCorrection
- [x] T007 Tests: payload allow-list (forbidden fields absent), candidates ranking + cap + empty set note, validator (bare article, foreign marker, missing language, placeholders), outcome consistency, success path per document type, unavailable → previous state, stale job saves nothing, reconcile, relevance supports/partial/does_not_support/unavailable, override reason + audit, gate, citations sync add/remove, superseded refresh, English rendering, tenant isolation of notes and topics

## Run Q — screens
- [x] T008 Editors: AI button (policy + preconditions), polling while drafting, unavailable banner, notes panel, citation picker modal inserting markers at the cursor
- [x] T009 ConfirmDocument: relevance badges + reasons, re-check, override form, superseded replacement; Arabic timeline labels for the new events
- [x] T010 Platform library: law name_en field; unit topic tags (discipline + free text)
- [x] T011 Local-only `ai:demo-fake` toggle for walkthroughs (refused outside local)
- [x] T012 Tests: screens per role (HR, admin, hr_staff unassigned, pool member, reviewer, reporter), Livewire update-endpoint authorisation, polling end states, picker only offers citable units, override requires reason
- [x] T013 docs: ERD, WEIRDNESS (AI never sees names/IDs; outcome keyword check is conservative; topics outside the immutable unit row), ADR 0003 note (AI boundary implemented); orchestrator walkthrough; PR
