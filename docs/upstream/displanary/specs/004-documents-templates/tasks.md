# Tasks: Document Templates and PDF Generation

**Tests**: REQUIRED. Pest on MySQL; PDF tests use the real Chrome in the container.
**Execution**: Phase 1 by the orchestrator (done before dispatch); run H = Phases 2–4; run I = Phases 5–6.
Gates: `docker compose exec -T laravel.test php artisan test` · `… ./vendor/bin/pint --test`.

## Phase 1: Setup (orchestrator)
- [x] T001 Sail runtime published; Dockerfile installs Chrome headless shell + fonts; image rebuilt (Chrome 154)
- [x] T002 composer spatie/browsershot; npm puppeteer (download skipped via .npmrc); OFL fonts + licences in resources/fonts
- [x] T003 Smoke test: Arabic + Sorani PDF renders with Noto Naskh Arabic (ADR 0005 Kurdish check)

## Phase 2: Data (run H)
- [x] T004 Migrations per data-model.md (gender, company_letterheads, document_templates, documents.render_snapshot); trigger migration update (render_snapshot protected; letterheads/templates append-only); backfill letterhead `ar` v1 from old company columns
- [x] T005 Models CompanyLetterhead, DocumentTemplate (BelongsToCompany, append-only, factories); Employee gender enum + cast; register both models in the isolation and composite-FK tests

## Phase 3: US1 rendering + PDF (P1) (run H)
- [x] T006 IraqiDate, PlaceholderResolver (incl. gender groups), TemplateValidator, TemplateResolver (company version or code default), BodyFormatter (escaped markdown subset), CitationRenderer (fail closed)
- [x] T007 Default templates ar + en for the three types (wording per docs/document-templates.md, committee name "اللجنة الانضباطية" / "Disciplinary Committee")
- [x] T008 DocumentFacts (MissingDocumentFact) + DocumentRenderer + Blade views (letterhead left logo / right text as in samples, red CONFIDENTIAL footer, A4, embedded fonts, page per language, draft watermark)
- [x] T009 Issuance freezes `rendered_html` + `render_snapshot` (R8 A1); CitationGate at issuance and in the citation-check transition guards (replacing the `citation_check_passed` flag); PdfGenerator (JS off, network blocked) + idempotent GenerateDocumentPdf (temp, verify, no-replace promote, sha, pdf_status) after commit; `documents:retry-pdf`; failure alert; issuance refuses unspecified gender or missing facts
- [x] T010 DocumentPolicy + routes: draft preview (HTML) and issued PDF download (private disk, CaseVisibility)
- [x] T011 [P] Tests: IraqiDateTest, TemplateValidatorTest, RendererTest (every default template resolves for male/female; each missing fact blocks; body escaping; citations fail closed; watermark only in drafts), FontCoverageTest (cmap reader; Arabic + Sorani codepoints), PdfGenerationTest (one PDF per type; language order / dir / letterhead + footer per page / page breaks; long body spans pages; fonts embedded; frozen HTML ignores later data edits; once-only file protocol and sha; failure → failed status → retry → ready; 409 while pending; download authorisation), CitationGateTest (applicable / inapplicable jurisdiction, unapproved regulation, unverified or superseded unit, does_not_support with and without override, bare article number in prose)

## Phase 4: US3 preview (P2) (run H)
- [x] T012 Draft preview with provisional reference + watermark (covered by T010/T011)

## Phase 5: US2 customisation UI (P1) (run I)
- [x] T013 TemplateService / LetterheadService (validate, new version, audit)
- [x] T014 Filament admin: DocumentTemplates page (type + language, block textareas RTL/LTR, placeholder help, live preview, history); CompanySettings letterhead per language + logo/header image upload (png/jpg ≤ 2 MB, private disk); EmployeeResource gender
- [x] T015 [P] TemplateEditingTest: save → new version, previous kept, issued docs unchanged, unknown placeholder refused, hr_staff preview-only, audit entries

## Phase 6: Seeds & docs (run I + orchestrator)
- [x] T016 Seeds: pilot letterhead ar/en (anonymised contacts), generated placeholder logo, employee genders, two anonymised demo cases (warning path, training-only path) issued through the services
- [x] T017 [P] docs: ERD (new tables / columns), ADR 0005 note (Kurdish check passed 2026-10-05 with Noto Naskh Arabic; Chrome headless shell, not snap Chromium), WEIRDNESS (templates hold wording only, never facts; Kurdish templates must come from the company)
- [x] T018 Orchestrator: gates; render the demo cases' PDFs and compare them visually with the samples; PR
