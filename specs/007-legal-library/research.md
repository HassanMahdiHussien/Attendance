# Research: Legal Library Import

## R1 PDF tools and the first reading
- **Decision**: poppler-utils in the image: `pdftotext -f N -l N -layout -enc UTF-8` per page for the text
  layer, `pdftoppm -r 250 -png -f N -l N` for page images. Tesseract (`tesseract-ocr`,
  `tesseract-ocr-ara`), `tesseract page.png - -l ara --psm 6`, when the text layer is not usable.
  Both packages are installed by the Dockerfile (product owner approved) and later by the provisioning
  script.
- **Text-layer usability**: per page, after NFKC: usable when ≥ 150 Arabic letters and Arabic letters are
  ≥ 60% of all letters and the replacement character (U+FFFD) and private-use code points are < 1%.
  The import has a mode `auto | text | ocr` (default `auto`); Law 37/2015 is seeded with `ocr`.
- **Alternatives**: PHP PDF parsers (no reliable Arabic shaping, new dependency); pymupdf (Python not in
  the image).

## R2 Second reading: AI vision
- **Decision**: official Anthropic PHP SDK (`anthropic-ai/sdk`, needs install approval), model from config
  `displanery.ai.legal_reading_model`, default `claude-opus-5-5`. One request per page: the page image
  (PNG, long edge downscaled to 1568 px, base64) + an instruction to transcribe the page **verbatim**
  (no corrections, no added numbering, keep line order, mark illegible words with `[؟]`), returned via
  structured output `{ "text": string, "illegible": boolean }`. The model does **not** split articles —
  the same deterministic splitter (R3) runs on both readings, so structure never comes from the AI.
- Retries: SDK retries (2) plus job-level retries with backoff (3 tries: 30 s, 2 min, 10 min) for
  429/5xx/connection errors; 400s fail the page immediately. No key configured → page marked
  `ai_unavailable` without a call.
- Every call is written to `ai_generation_logs` (touchpoint `legal_library_reading`, company null for
  statutes / the company for a regulation, input = law id + page + image sha256, never the image bytes;
  output = the transcription; tokens; duration).
- **Alternatives**: raw HTTP (skill guidance: use the official SDK); asking the AI for structured
  articles (makes segmentation depend on the model and weakens independence).

## R3 Splitting into units
- Pages of a run are concatenated in order with page markers; units are cut at article headings:
  `المادة` / `مادة` followed by digits (Western or Arabic-Indic, optionally in `-`/`(`/`)`) or an ordinal word
  (الأولى … العشرون, with a word map 1–100 incl. "الحادية عشرة"…). The article unit keeps the page where
  the heading appears; `tags.continues_on` lists later pages it spans.
- Clauses: line-start markers `أولاً … عشرون` (ordinal adverbs, with or without tanween/hyphen/colon)
  become level-1 clauses; `أ- ب- ج- د- هـ- و- ز- ح- ط- ي- ك- ل- م-` (also `أ)`, `(أ)`) inside a level-1
  clause become level-2 (`43/ثانياً/ز`). Deeper levels stay inside their parent's text.
- Chapter/part headings (`الباب`, `الفصل`) are not units; the current heading is stored in
  `tags.heading`.
- The split runs on each reading separately; units are matched by `(article_number, clause_path)`.
  Present in one reading only → `mismatch` with `tags.mismatch_reason = one_reading_only`.

## R4 Comparison
- Normalise: NFKC; Arabic-Indic and Eastern digits → Western; remove tatweel (U+0640), harakat
  (U+064B–U+065F, U+0670); unify Arabic punctuation `، ؛ ؟` → `, ; ?`; remove all whitespace. Letters
  (alef forms, ya/alef maqsura, ta marbuta) are NOT unified — they are textual differences a human must
  see. Equal → `verified_auto`; otherwise `mismatch`.
- Official text stored for a `verified_auto` unit: reading (a) with whitespace collapsed (it equals (b)
  after normalisation).
- Queue diff: word-level diff of the two readings (LCS on whitespace tokens) rendered with marks.

## R5 Statuses, versions, immutability
- Statuses: `unverified`, `mismatch`, `verified_auto`, `verified_human`, `verified_text` (new),
  `excluded` (new). Immutable once in any `verified_*` state (model guard + trigger updated to include
  `verified_text`); `superseded_by_id` may be set once.
- Resolving a `mismatch` updates that row in place (it was never citable). Correcting a verified unit
  creates version `n+1` (`previous_version_id`), sets the old row's `superseded_by_id`, audit entry with
  both texts. Existing citations keep pointing at the old row (citations are append-only already).
- Re-import of the same law: match by `(article_number, clause_path)` against the current (not superseded)
  version. Unchanged normalised text of a verified unit → keep. Changed → new version row in
  `mismatch`/`verified_auto` per the new readings; the old verified version stays citable until the new
  one is verified, then it is superseded. Unverified / mismatch / excluded rows of the law that are not
  matched by the new run are deleted (never cited).
- A **new law version** (amendment, re-issue) is a new `laws` row with `supersedes_law_id`; the old law
  keeps its units for old cases (`law_version_id` on cases already exists).

## R6 Regulation approval
- New append-only `legal_unit_approvals` (company_id, legal_unit_id, approved_by, created_at; unique
  unit). Regulation units are citable only when verified and approved. The law-level
  `approval_status` check for regulations is replaced by the unit-level approval in `CitationGate` and
  the `Citation` model guard. Statutes keep `approval_status = approved` (Platform Admin owned).
- Reviewer = company user with the `review_internal_regulation` add-on; company admin may upload.

## R7 Page images and access
- Stored on the private disk: `legal-library/{law_id}/pages/{page}.png` (250 dpi) — one image per source
  page, shared by every run of that law file (content sha recorded). Route
  `GET /legal-library/laws/{law}/pages/{page}` streams it after `LawPolicy::viewPage`: statutes → any
  active signed-in user with a company or platform role except reporters; regulation → that company's
  admin/HR/reviewer and the Platform Admin.

## R8 Background processing
- `legal_import_runs` (state: queued, reading, ai_pending, splitting, completed, failed, cancelled) and
  `legal_import_pages` (per page: text_status, text_method text|ocr, text; ai_status, ai_text; errors;
  attempts). Start → one `RenderPages` job (images + page rows) → per-page `ReadPageText` and
  `ReadPageWithAi` jobs → when no page is pending, `BuildUnits` (dispatched by the last finishing page
  job inside a row lock on the run) splits, compares and writes units in one transaction.
- One active run per law (unique partial-style check under `lockForUpdate` on the law row).
- "Retry AI reading" re-dispatches `ReadPageWithAi` for pages whose ai_status is failed/unavailable, then
  `BuildUnits` again. Limits: `displanery.legal_library.max_pages` (300) and `max_file_mb` (50).

## R9 Regulation formats
- `.txt` (UTF-8, BOM stripped) and `.docx` (ZipArchive, `word/document.xml`, paragraphs from `w:p`,
  text from `w:t`, `w:del` skipped, `w:ins` kept — no new package) → split directly, status
  `verified_text`, no page images (page = null). PDF → R1–R4 pipeline.

## R10 Screens
- Platform panel (Filament): `LawResource` (list with counts per status, create, edit meta, jurisdictions,
  repeal, supersedes), "Import" action (file, page range, mode), run progress widget, units relation
  manager (browse, version history, page link, correct → new version), `MismatchQueue` page (side by
  side: reading A, reading B with diff marks, page image; pick A / pick B / edit / exclude).
- Admin panel (company): `InternalRegulation` page (upload, run progress, units, mismatch resolution,
  approve unit / approve all verified) visible to company admin (upload) and reviewers (resolve/approve).
- Uses Filament because CLAUDE.md §2 lists legal-reference management as Filament screens.

## R11 Seeds / existing data
- `LawsSeeder` adds `import_mode` (`ocr` for Law 37, `auto` otherwise) and, for Law 18, the default page
  range is left empty — the Platform Admin enters it at import (the gazette's page range must be
  checked by eye). Seeds create no units; demo/test units come from factories.

## R12 Plan review resolutions (Codex review, 2026-10-07)
- **A1 Evidence link**: source files are stored content-addressed and never overwritten
  (`legal-library/sources/{sha256}.{ext}`); page images per run (`legal-library/runs/{run_id}/pages/{n}.png`).
  Every unit has `import_run_id`; its page link resolves through that run, so an old unit always shows
  the image it was read from. (Supersedes the shared path in R7.)
- **A2 Citability vs the case's pinned law**: `LegalUnit::scopeCitableFor(DisciplinaryCase $case)`.
  Statute units: law applies to `cases.jurisdiction_at_opening`; for the pinned category (labor) the law
  must be `cases.law_version_id`; other statutes must be in effect on the case's opening date
  (effective_from ≤ opened, not repealed on or before it). Regulation units: same company + approval.
  Unit level: verified set and not superseded. A **unit correction** fixes our transcription, not the law,
  so the corrected version is the citable one for every case (no product question needed); a **new law
  version** (new `laws` row) never replaces the pinned law of an existing case (CLAUDE.md §23.5).
- **A3 Page coverage**: a unit may become `verified_auto` only when every page it spans has both readings
  `done`; units touching a permanently failed page are `mismatch` with `tags.mismatch_reason =
  page_failed`. The build runs only when every page is terminal for both readings (`done`/`failed`); any
  `unavailable` AI page keeps the run in `ai_pending` and builds nothing.
- **B1 Trigger**: recreated update/delete triggers protect `verified_text` too and list `import_run_id`
  among protected columns; successor insert + old `superseded_by_id` set in one transaction; tests use
  raw SQL as well as Eloquent.
- **B2 Matching and removal**: nothing is deleted. Unmatched unverified/mismatch rows of earlier runs get
  status `excluded` with `tags.excluded_reason = replaced_by_run:{id}` (readings kept). Duplicate
  `(article, clause)` keys in one run → all of them `mismatch` with reason `duplicate_heading`. An
  excluded unit matched again with unchanged text stays excluded; changed text → new row in review.
  Approvals belong to one unit version; a new version needs a new approval.
- **B3 Stricter auto-verification**: normalisation collapses whitespace to one space (does not remove it);
  `verified_auto` additionally requires identical clause structure for the article in both readings, no
  `[؟]` marker and `illegible = false`; otherwise `mismatch` (`structure_differs` / `illegible`). Splitter
  tests use representative fragments of each law's layout (typed, not copied from the gazettes beyond a
  few short lines).
- **B4 Job state machine**: each page job ends with `ImportProgress::evaluate($run)`: in a transaction with
  the run row locked, if state = reading and the readiness predicate holds (every page text ∈ {done,
  failed} and ai ∈ {done, failed}) → state `splitting`, dispatch `BuildUnits` after commit; if any ai =
  `unavailable` and nothing pending → `ai_pending`. `BuildUnits` is idempotent (acts only in `splitting`,
  one transaction, ends `completed`). Scheduled `legal-library:reconcile` (every 10 min) re-evaluates runs
  in `reading`/`splitting` untouched for 30 min and re-dispatches missing page jobs. `retryAi` resets
  failed/unavailable AI pages to pending, state → reading. Jobs set the tenant context explicitly
  (`runForAllCompanies` for statutes, `runForCompany` for a regulation) and restore it.
- **B5 Citations**: the `Citation` model guard (creation) and `CitationGate` (issuance) both call
  `scopeCitableFor($case)` and keep the relevance/override checks. Issued documents are never
  revalidated (gate runs only at issuance). An unissued draft citing a superseded unit is blocked at
  issuance with "النص القانوني صُحح — حدّث الاستشهاد"; refreshing citations is feature 008. Existing
  citation tests are updated for unit-level regulation approval.
- **B6 Data-layer tenancy**: `legal_units` gains unique `(company_id, id)` for composite FKs; runs take
  `company_id` from the law and pages from the run (model guards, mismatch = TenantMismatch);
  `legal_unit_approvals` composite FK `(company_id, legal_unit_id)` and approver must belong to that
  company and hold `review_internal_regulation` (service + model guard). Tests cover the Platform Admin
  all-company path and a company context never seeing another company's regulation.
- **B7 Source boundary**: uploads on the private disk only, retained as evidence; limits: 50 MB, 300 pages,
  page render 250 dpi max 4000 px long edge, per-page process timeout 120 s, docx uncompressed size
  ≤ 20 MB (zip-bomb guard); malformed files fail the run with a readable message. AI logs for a
  regulation carry the company id (company-scoped); transcriptions of statutes/regulations contain no
  personal data. Model id and failures are logged.
- **B8 Gazette range**: the import form renders the first and last page of the chosen range and requires
  ticking "تحققت من بداية القانون في الصفحة الأولى ونهايته في الأخيرة" before starting; invalid ranges are
  rejected; a wrong range is fixed by re-importing (replaced units become excluded).
- **Nits**: SDK approved and installed (^0.55.0); FR-006 limited to PDF units, text/Word units show the
  source text instead; Filament CRUD + one custom queue page kept.

## R13 Facts from a real OCR sample (Law 37/2015, page 5, Tesseract ara, 250 dpi)
- OCR quality is good on body text. Clause markers appear in masculine ordinal form with a hyphen:
  `ثامن عشر-`, `عشرون -`, `حادي وعشرون`, `خامس وعشرون -` (the splitter must accept masculine and feminine
  ordinals 1–50, compound forms with `و`, with or without tanween, hyphen, colon or nothing).
- Bidi control characters (U+200E, U+200F, U+202A–U+202E, U+2066–U+2069) appear inside numbers
  (`‎)١5(‏`): the normaliser removes them; mixed digits (`١5`) are unified.
- OCR misreads the Arabic comma `،` as `»`: this is a real reading difference → mismatch (correct
  behaviour, the human fixes it). The normaliser must NOT map `»` to a comma.
- Running header/footer noise (gazette title, issue number, page number) appears as short lines at the
  top/bottom: lines in the first/last 3 lines of a page with fewer than 4 Arabic letters, or matching the
  gazette title (`الوقائع العراقية`), are dropped before splitting, in both readings.
