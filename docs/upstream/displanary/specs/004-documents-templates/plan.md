# Implementation Plan: Document Templates and PDF Generation

**Branch**: `004-documents-templates` | **Date**: 2026-10-05 | **Spec**: [spec.md](./spec.md)

## Summary
A single renderer turns letterhead + versioned template blocks + case facts + the per-language body into
bilingual RTL/LTR HTML. A queued Browsershot job stores the issued PDF once. Companies edit letterhead
and templates per language through validated, versioned Filament screens with live preview. Employees
gain gender, used for the Arabic grammar.

## Technical Context
**Stack**: PHP 8.4, Laravel 13, Filament 5, spatie/browsershot 5.x (installed), puppeteer (npm, browser
download skipped), Chrome for Testing headless shell 154 in the image, OFL fonts in resources/fonts ·
**Testing**: Pest on MySQL; PDF tests run real Chrome in the container (tagged `pdf`) ·
**Constraints**: escaped body, fail-closed facts and citations, immutable template versions, private
disk only.

## Constitution Check
| Principle | Status | How |
|---|---|---|
| I. Tenancy | PASS | letterheads and templates are tenant tables with composite FKs and isolation tests; PDF download via CaseVisibility |
| II. AI boundary | PASS | templates hold fixed wording only; facts come from the DB; citations are rendered from the library, never typed |
| III. Immutable | PASS | template and letterhead versions append-only; render_snapshot and PDF set once (trigger) |
| IV. Tests | PASS | placeholder validator, every default template resolves, missing-fact blocking, gender forms, Iraqi dates, font coverage, a real PDF render per type |
| V. Simple | PASS | Blade views + one renderer service; no template engine package |
| VI. Decided | PASS | customisation level, dates, Browsershot as decided; committee name unified per PRD §25.1 |

## Structure
```text
app/Documents/{DocumentRenderer,DocumentFacts,TemplateResolver,TemplateValidator,PlaceholderResolver,
  IraqiDate,CitationRenderer,BodyFormatter,PdfGenerator}.php, Defaults/{defense_letter,recommendations,decision_letter}.{ar,en}.php
app/Jobs/GenerateDocumentPdf.php · app/Services/Documents/{TemplateService,LetterheadService}.php
app/Models/{CompanyLetterhead,DocumentTemplate}.php · app/Policies/DocumentPolicy.php · routes/web.php (pdf download, draft preview)
app/Filament/Admin/Pages/DocumentTemplates.php (+ CompanySettings letterhead section) · EmployeeResource gender
resources/views/documents/*.blade.php · config/displanery.php pdf block
database/migrations/… (employees.gender, company_letterheads, document_templates, documents.render_snapshot + trigger update)
tests/Feature/Documents/{RendererTest,TemplateValidatorTest,TemplateEditingTest,PdfGenerationTest,FontCoverageTest,IraqiDateTest}.php
```

## Delegation
Codex plan review (read-only) → run H (data, renderer, defaults, PDF job, tests) → run I (Filament
screens, seeds, docs). The orchestrator runs gates and checks the PDF output visually against the
samples.

## Complexity Tracking
None.
