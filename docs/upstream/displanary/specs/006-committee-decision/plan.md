# Implementation Plan: Case Screens — Committee to Archive

**Branch**: `006-committee-decision` | **Date**: 2026-10-06 | **Spec**: [spec.md](./spec.md)

## Summary
New Livewire screens on the existing services: committee session, recommendations editor, the pool
members' approval screen and "اللجنة" list, a generalised confirmation screen, signed scan + presence
record, the decision letter (with leave confirmation), consultation request and reviewer completion,
and appeal logging. Default templates gain the approved outcome wording and feminine role labels.

## Technical Context
PHP 8.4 · Laravel 13 · Livewire 4 · existing services (feature 003) · notifications (database + mail) ·
Pest + Livewire (update-endpoint tests) · no new packages.

## Constitution Check
| Principle | Status | How |
|---|---|---|
| I. Tenancy | PASS | CaseVisibility binding (pool approval stage, firm-wide reviewer after signed Doc A); services authorise |
| II. AI boundary | PASS | human minutes / drafting; presence check wording only; no AI decisions |
| III. Immutable | PASS | issuance / corrections / approvals through existing services; signatures and attachments append-only |
| IV. Tests | PASS | role × action matrix, quorum / approval / leave / consultation gates through the UI, wording check |
| V. Thin UI | PASS | Livewire → services only; new thin services: SessionScreenData (read model), SignaturePresence, CaseClosure |
| VI. Decided | PASS | wording and labels per product owner; presence ≠ verification; request-only consultation |

## Structure
```text
app/Livewire/Cases/{RecordSession,EditRecommendations,ReviewApproval,ConfirmDocument (generalised),
  SignedScan,EditDecisionLetter,Appeal}.php · app/Livewire/Committee/PendingApprovals.php ·
  app/Livewire/Reviewer/Consultation.php
app/Services/Cases/{SignaturePresence,CaseClosure}.php · app/Notifications/ApprovalNeeded.php
app/Documents/Defaults/{recommendations,decision_letter}.{ar,en}.php (wording, female labels) · DocumentFacts (attendee gender)
tests/Feature/Workspace006/*
```

## Delegation
Codex plan review → run L (session, recommendations, approvals + notifications, generalised
confirmation, signed scan + presence, no-penalty close) → run M (decision letter + leave confirmation,
consultation request / reviewer screen, appeal, archived read-only, template wording / labels, role
matrix). The orchestrator runs the gates and drives both paths in the browser.
