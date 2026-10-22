# Implementation Plan: Case Screens — Intake to Response

**Branch**: `005-case-screens` | **Date**: 2026-10-05 | **Spec**: [spec.md](./spec.md)

## Summary
A custom Livewire 4 workspace that follows the mockup: shared hardened sign-in with role landing,
reports intake, the case board, case creation with intake checks, the case page, defense-letter
drafting, the mockup confirmation / issuance screen, PDF printing, and hand-off / response recording.
It sits on thin new services; all rules stay in the feature 003 / 004 services.

## Technical Context
PHP 8.4 · Laravel 13 · Livewire 4 · Tailwind v4 (Vite) · existing services · Pest + Livewire tests ·
no new packages.

## Constitution Check
| Principle | Status | How |
|---|---|---|
| I. Tenancy | PASS | case binding through CaseVisibility (404); policies in mount + every action; reporter branch scope |
| II. AI boundary | PASS | manual drafting only; facts from the DB; citation gate on submit |
| III. Immutable | PASS | issuance / correction via existing services; attachments append-only |
| IV. Tests | PASS | role × route matrix, every case-page action, board mapping |
| V. Thin UI | PASS | Livewire calls services only (ReportService, CaseDrafts, DocumentDrafts, AttachmentService, SecondReview + existing) |
| VI. Decided | PASS | custom app per mockup (product owner), Filament stays for setup |

## Structure
```text
app/Livewire/{Auth/Login, Cases/{Board,CreateCase,ShowCase,EditDocument,ConfirmDocument},
  Reports/{MyReports,SubmitReport,Inbox}, MyCases, Home}.php
app/Services/{Auth/LoginAttempts, Reports/ReportService, Cases/CaseDrafts, Documents/DocumentDrafts,
  Attachments/AttachmentService, Documents/SecondReview}.php
app/Support/Access/Landing.php · app/Support/Format/ArabicDigits.php · app/Support/Cases/StageIndicator.php
resources/views/layouts/workspace.blade.php · resources/views/livewire/**
routes/web.php · tests/Feature/Workspace/*
```

## Delegation
Codex plan review (read-only) → run J (sign-in, shell, landing, reports, board, case creation) → run K
(case page, drafting, confirmation / issuance, PDF, corrections, hand-offs, response upload). The
orchestrator runs the gates after each run and walks the screens in the browser.
