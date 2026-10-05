# Tasks: Document Templates and PDF Generation

**Tests**: REQUIRED. Pest on MySQL; PDF tests use the real Chrome in the container.
**Execution**: Phase 1 by the orchestrator (done before dispatch); run H = Phases 2–4; run I = Phases 5–6.
Gates: `docker compose exec -T laravel.test php artisan test` · `… ./vendor/bin/pint --test`.

## Phase 1: Setup (orchestrator)
- [x] T001 Sail runtime published; Dockerfile installs Chrome headless shell + fonts; image rebuilt (Chrome 154)
- [x] T002 composer spatie/browsershot; npm puppeteer (download skipped via .npmrc); OFL fonts + licences in resources/fonts
- [x] T003 Smoke test: Arabic + Sorani PDF renders with Noto Naskh Arabic (ADR 0005 Kurdish check)

## Phase 2: Data (run H)
- [ ] T004 Migrations per data-model.md (gender, company_letterheads, document_templates, documents.render_snapshot); trigger migration update (render_snapshot protected; letterheads/templates append-only); backfill letterhead `ar` v1 from old company columns
- [ ] T005 Models CompanyLetterhead, DocumentTemplate (BelongsToCompany, append-only, factories); Employee gender enum + cast; register both models in the isolation and composite-FK tests

## Phase 3: US1 rendering + PDF (P1) (run H)
- [ ] T006 IraqiDate, PlaceholderResolver (incl. gender groups), TemplateValidator, TemplateResolver (company version or code default), BodyFormatter (escaped markdown subset), CitationRenderer (fail closed)
- [ ] T007 Default templates ar + en for the three types (wording per docs/document-templates.md, committee name "اللجنة الانضباطية" / "Disciplinary Committee")
- [ ] T008 DocumentFacts (MissingDocumentFact) + DocumentRenderer + Blade views (letterhead left logo / right text as in samples, red CONFIDENTIAL footer, A4, embedded fonts, page per language, draft watermark)
- [ ] T009 PdfGenerator (Browsershot config from `displanery.pdf`) + GenerateDocumentPdf job dispatched after commit by CaseDocumentIssuance; render_snapshot set at issuance; issuance refuses unspecified gender or missing facts
- [ ] T010 DocumentPolicy + routes: draft preview (HTML) and issued PDF download (private disk, CaseVisibility)
- [ ] T011 [P] Tests: IraqiDateTest, TemplateValidatorTest, RendererTest (every default template resolves for male/female; each missing fact blocks; body escaping; citations fail closed; watermark only in drafts), FontCoverageTest (cmap reader; Arabic + Sorani codepoints), PdfGenerationTest (one PDF per type, page count = languages, fonts embedded, pdf_file_path set once, download authorisation)

## Phase 4: US3 preview (P2) (run H)
- [ ] T012 Draft preview with provisional reference + watermark (covered by T010/T011)

## Phase 5: US2 customisation UI (P1) (run I)
- [ ] T013 TemplateService / LetterheadService (validate, new version, audit)
- [ ] T014 Filament admin: DocumentTemplates page (type + language, block textareas RTL/LTR, placeholder help, live preview, history); CompanySettings letterhead per language + logo/header image upload (png/jpg ≤ 2 MB, private disk); EmployeeResource gender
- [ ] T015 [P] TemplateEditingTest: save → new version, previous kept, issued docs unchanged, unknown placeholder refused, hr_staff preview-only, audit entries

## Phase 6: Seeds & docs (run I + orchestrator)
- [ ] T016 Seeds: pilot letterhead ar/en (anonymised contacts), generated placeholder logo, employee genders, two anonymised demo cases (warning path, training-only path) issued through the services
- [ ] T017 [P] docs: ERD (new tables / columns), ADR 0005 note (Kurdish check passed 2026-10-05 with Noto Naskh Arabic; Chrome headless shell, not snap Chromium), WEIRDNESS (templates hold wording only, never facts; Kurdish templates must come from the company)
- [ ] T018 Orchestrator: gates; render the demo cases' PDFs and compare them visually with the samples; PR
