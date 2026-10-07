# Implementation Plan: Legal Library Import

**Branch**: `007-legal-library` | **Date**: 2026-10-07 | **Spec**: [spec.md](./spec.md)

## Summary
Import the built-in laws and company regulations into verified, citable legal units. Two independent
readings per page (text layer or Tesseract OCR; AI vision transcription), one deterministic splitter for
both, normalised comparison, a mismatch queue with the page image, versioned corrections, Platform
Admin statute management, and company-lawyer approval for regulations. All long work runs as queued jobs.

## Technical Context
PHP 8.4 · Laravel 13 · Filament 5 (platform + admin panels) · queue (database) · MySQL 8.4 triggers ·
new image packages poppler-utils, tesseract-ocr, tesseract-ocr-ara (approved) · composer package
`anthropic-ai/sdk` ^0.55 (approved, installed) · `ANTHROPIC_API_KEY` optional (absent → AI reading unavailable) ·
Pest with a fake AI reader and fixture PDFs/images (no network).

## Constitution Check
| Principle | Status | How |
|---|---|---|
| I. Tenancy | PASS | regulation units company-scoped (LegalLibraryScope + composite FKs on new tables); page route policy; statutes Platform-Admin-only through services + policies |
| II. AI boundary | PASS | AI only transcribes a page image; never splits, never decides; nothing is citable from one reading; every call logged |
| III. Immutable | PASS | verified units immutable (trigger extended to `verified_text`); corrections = new versions; approvals and audit append-only |
| IV. Tests | PASS | splitter/normaliser units, pipeline with fake reader, tenancy matrix, immutability, citability rules in CitationGate |
| V. Thin UI | PASS | Filament pages call `LegalLibraryService`, `LawImportService`, `MismatchResolver`, `RegulationApprovals` |
| VI. Decided | PASS | follows CLAUDE.md §23; OCR approved; key added later |

## Structure
```text
docker/8.4/Dockerfile (+ poppler-utils tesseract-ocr tesseract-ocr-ara)
config/displanery.php (legal_library: max_pages, max_file_mb, dpi; ai.legal_reading_model)
database/migrations/2026_10_07_* (import runs/pages, unit approvals, laws columns, statuses, trigger update)
app/LegalLibrary/{TextNormalizer, ArticleSplitter, ReadingComparator, WordDiff, TextLayerProbe,
  PdfPages (pdftotext/pdftoppm/tesseract wrappers), DocxText}.php
app/LegalLibrary/Ai/{PageReader (interface), ClaudePageReader, FakePageReader}.php
app/Services/LegalLibrary/{LawImportService, UnitBuilder, MismatchResolver, UnitCorrection,
  LawManagement, RegulationUpload, RegulationApprovals}.php
app/Jobs/LegalLibrary/{RenderPages, ReadPageText, ReadPageWithAi, BuildUnits}.php
app/Models/{LegalImportRun, LegalImportPage, LegalUnitApproval}.php · LegalUnit/Law updates · LawPolicy
app/Filament/Platform/Resources/LawResource (+ units relation manager) · Pages/MismatchQueue
app/Filament/Admin/Pages/InternalRegulation · routes: page image
app/Documents/CitationGate.php + app/Models/Citation.php (verified_text, unit approvals)
tests/Feature/LegalLibrary/* · tests/fixtures/legal/* (small generated PDFs: text layer, image-only)
```

## Delegation
Codex plan review → run N (Docker packages, migrations/models, normaliser/splitter/comparator, PDF
tools wrappers, AI reader + fake, jobs and import service, unit builder, citability rules, tests) → run O
(Filament: law management, import action + progress, units browse/correct, mismatch queue, company
regulation page with approvals; page image route; tests; docs). The orchestrator installs packages
after approval, rebuilds the image, runs the gates, imports Law 71/1987 for real (text layer + OCR
only until the key exists) and walks the screens.
