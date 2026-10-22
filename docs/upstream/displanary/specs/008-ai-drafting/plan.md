# Implementation Plan: AI Drafting with Verified Citations

**Branch**: `008-ai-drafting` | **Date**: 2026-10-07 | **Spec**: [spec.md](./spec.md)

## Summary
AI drafts of the defense letter, recommendations and decision letter from human-entered facts and decisions, in
every enabled language (Arabic authoritative), citing only candidate verified units by marker; deterministic
validation, an independent relevance check with logged overrides, AI side notes, a manual path and recovery when
the AI is unavailable, English law names and clause rendering, and a citation picker for manual drafting.

## Technical Context
PHP 8.4 · Laravel 13 · Livewire 4 · Filament 5 (library tags) · queue (database) · official `anthropic-ai/sdk`
(installed) · model `claude-opus-5-5` (configurable) · Pest with a fake Claude client (no network) · no new
packages.

## Constitution Check
| Principle | Status | How |
|---|---|---|
| I. Tenancy | PASS | case-scoped services; candidate units via `citableFor($case)`; jobs set the tenant context |
| II. AI boundary | PASS | facts/decisions from people only; outcome check; markers only for offered units; notes never printed; human confirmation unchanged |
| III. Immutable | PASS | drafts through existing services; citations of locked documents untouched; overrides and AI logs append-only |
| IV. Tests | PASS | validator, payload minimisation, workflow recovery, relevance gate, overrides, rendering |
| V. Thin UI | PASS | Livewire buttons/polling/modals call `AiDrafting`, `RelevanceCheck`, `CitationSync`, `CitationOverride` |
| VI. Decided | PASS | split 008/009 and email later per product owner; CLAUDE.md §3, §3b, §3bb, §3c, §23 |

## Structure
```text
config/displanery.php (ai.drafting_model, ai.relevance_model, ai.max_candidates)
database/migrations/2026_10_08_* (laws.name_en, citations.override_reason, ai_draft_notes)
app/Ai/{ClaudeClient, AiUnavailable (reuse 007), StructuredCall}.php
app/Ai/Drafting/{PayloadBuilder (per type), CandidateUnits, DraftPrompt, DraftValidator, OutcomeConsistency}.php
app/Services/Ai/{AiDrafting, RelevanceCheck, CitationSync, CitationOverride, CitationRefresh}.php
app/Jobs/{DraftDocumentWithAi, CheckCitationRelevance}.php · console ai:reconcile-drafting (+ schedule)
app/Models/AiDraftNote.php · Law (name_en) · Citation (override_reason)
app/Documents/CitationRenderer.php (English rendering) · app/LegalLibrary/LawManagement (topic tags)
app/Livewire/Cases/{EditDocument, EditRecommendations, EditDecisionLetter, ConfirmDocument} + views; picker modal
app/Filament/Platform/Resources/LawResource (units: tags; law: name_en)
tests/Fakes/FakeClaudeClient.php · tests/Feature/AiDrafting/*
```

## Delegation
Codex plan review → run P (client, payloads, candidates, prompt + validator, drafting service + job + workflow +
reconcile, citations sync, relevance check + overrides + gate integration, English rendering + name_en, notes,
tests) → run Q (editor buttons/polling/banner/notes, citation picker, confirmation screen badges/override/refresh,
library tags + name_en in Filament, screen tests, docs). The orchestrator runs gates, walks the screens with the
fake client bound in a local demo command, and (once the key exists) one real drafting call.
