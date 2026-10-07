# Tasks: Legal Library Import

**Tests**: REQUIRED (Pest, MySQL; fake AI reader; fixture PDFs generated in tests or committed under
`tests/fixtures/legal/` — never the real gazettes). Gates (as sail):
`docker compose exec -T -u sail laravel.test php artisan test` · `… ./vendor/bin/pint --test`.

## Setup (orchestrator)
- [x] T001 Dockerfile: poppler-utils, tesseract-ocr, tesseract-ocr-ara; image rebuilt; tool check
- [x] T002 composer `anthropic-ai/sdk` ^0.55.0 (approved, installed); `ANTHROPIC_API_KEY` stays empty in .env.example

## Run N — pipeline
- [ ] T003 Migrations per data-model.md (runs, pages, approvals, laws columns, unit statuses/import_run_id, trigger recreation with down()); models LegalImportRun, LegalImportPage, LegalUnitApproval (company scope / append-only); LegalUnitStatus cases; register new tables in the tenancy isolation + composite-FK tests; legal_units unique (company_id, id); per research R12 A1–B7; LawsSeeder import_mode
- [ ] T004 TextNormalizer, ArticleSplitter (ordinal words, digits, clauses two levels, headings), ReadingComparator, WordDiff, TextLayerProbe (R1, R3, R4)
- [ ] T005 PdfPages wrappers (page count, pdftotext per page, pdftoppm 250 dpi, tesseract ara; argument arrays via Symfony Process, no shell strings; timeouts) and DocxText (R9)
- [ ] T006 PageReader interface, ClaudePageReader (SDK, config model, image downscale to 1568 px, structured output, retries, AiUnavailable without key, ai_generation_logs entry), FakePageReader bound in tests
- [ ] T007 LawImportService (start / retryAi / limits / one active run / audit) + jobs RenderPages, ReadPageText, ReadPageWithAi, BuildUnits + ImportProgress::evaluate + scheduled `legal-library:reconcile` (R12 B4; tenant context set in jobs)
- [ ] T008 UnitBuilder (match, compare, versioning, re-import rules R5) + MismatchResolver + UnitCorrection + LawManagement + RegulationUpload + RegulationApprovals; LawPolicy; `LegalUnit::scopeCitableFor($case)` (R12 A2); CitationGate + Citation guard use it; superseded-unit message (R12 B5)
- [ ] T009 Tests: normaliser / splitter (Law-37-style "المادة -1-", ordinal words, أولاً/أ- clauses, one-reading-only), comparator; pipeline end to end with fixture PDFs (text-layer PDF, image-only PDF → OCR) and fake reader (match → verified_auto, letter drop → mismatch, AI unavailable → no verified, retry AI); one active run; page range; re-import keeps verified / versions changed; correction keeps citations; immutability triggers incl. verified_text; regulation docx → verified_text not citable until approved; cross-tenant isolation of regulation units, runs, pages, approvals; citability matrix (repealed, jurisdiction removed, superseded, unapproved)

## Run O — screens
- [ ] T010 Platform panel LawResource (list with status counts, create/edit, jurisdictions, repeal, supersedes, import action with file / page range (first/last page preview + confirmation, R12 B8) / mode, runs list with progress and retry AI) + units relation manager (browse, page link, history, correct)
- [ ] T011 Platform MismatchQueue page (reading A / B with diff marks, page image, pick A / pick B / edit / exclude)
- [ ] T012 Admin panel InternalRegulation page (upload txt/docx/pdf, progress, units, mismatch resolution, approve / approve all verified) — company admin uploads, reviewer resolves/approves; page image route + policy
- [ ] T013 Tests: panel access per role (platform admin, company admin, hr_staff, reviewer add-on, reporter, other company), Livewire actions authorised server-side, page image route 404s
- [ ] T014 docs: ERD (new tables/columns), WEIRDNESS (AI transcribes only, splitter is ours; text/docx regulations are verified_text but need approval), ADR note if needed; orchestrator real import of Law 71/1987 + walkthrough; PR
