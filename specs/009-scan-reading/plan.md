# Implementation Plan: AI Reading of Scans

**Branch**: `009-scan-reading` | **Date**: 2026-10-07 | **Spec**: [spec.md](./spec.md)

## Summary
AI draft transcription of the handwritten response (checked and confirmed by HR before use) and AI suggestions of
signature-box presence on the signed recommendations (HR records). Both reuse 008's Claude client, logging, tokens
and fake client; manual paths unchanged.

## Technical Context
PHP 8.4 · Laravel 13 · Livewire 4 · queue · official `anthropic-ai/sdk` · 007 PdfPages (page count) · Pest with the
fake client · no new packages.

## Constitution Check
| Principle | Status | How |
|---|---|---|
| I. Tenancy | PASS | case-scoped services and tables (composite FKs), jobs set the tenant context |
| II. AI boundary | PASS | suggestions only; HR confirms text and records presence; presence never identity |
| III. Immutable | PASS | transcription attempts and suggestions append-only; scans stay the record |
| IV. Tests | PASS | flows, tokens, manual paths, wording scan, payload |
| V. Thin UI | PASS | Livewire → ResponseReading / SignaturePresence services |
| VI. Decided | PASS | CLAUDE.md §3, §3b, §20; split per product owner |

## Structure
```text
database/migrations/2026_10_09_* (response_transcriptions, signature_presence_suggestions, signatures.ai_suggestion, cases.employee_response_origin)
app/Ai/ClaudeClient (content blocks) · app/Ai/ScanBlocks.php (image/PDF → blocks, downscale, page limit)
app/Services/Cases/ResponseReading.php · SignaturePresence (upload / recordPresence split) · PresenceWording
app/Jobs/{TranscribeResponseScan, SuggestSignaturePresence}.php
app/Ai/PayloadBuilder (employee_response) · DraftPrompt note
app/Livewire/Cases/{ShowCase (response panel), SignedScan, RecordSession, ReviewApproval, ReviewConsultation} + views
tests/Feature/ScanReading/*
```

## Delegation
Codex plan review → run R (all of tasks.md) → orchestrator gates, diff check for mangled escapes and silent behaviour
changes, browser walkthrough with a one-off fake-client script, PR.
