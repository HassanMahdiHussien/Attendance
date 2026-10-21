# Research: Document Templates and PDF Generation

## R1. Chrome in Docker
- Ubuntu noble ships Chromium only as a snap, which cannot run in containers. The image therefore
  installs **Chrome for Testing headless shell** with `npx @puppeteer/browsers install
  chrome-headless-shell@stable --path /opt/chrome`, symlinked to `/usr/local/bin/chrome-headless-shell`
  (`DISPLANERY_CHROME_PATH`), plus its shared libraries and `fonts-noto-core`, `fonts-hosny-amiri`.
- Browsershot's `bin/browser.cjs` `require('puppeteer')`, so the project gets the `puppeteer` npm
  package with `PUPPETEER_SKIP_DOWNLOAD=true` (`.npmrc` / env), and Browsershot calls
  `->setChromePath(config('displanery.pdf.chrome_path'))`. Launch args: `--no-sandbox` is required in
  the container (non-root sail user, no user namespaces). It is acceptable because only our own
  escaped HTML is rendered, with no remote URLs (`->setOption('args', [...])`, network requests to
  non-data URLs blocked via `blockDomains`/request interception).
- Production VPS (later): the same Dockerfile steps go into the provisioning script.

## R2. Fonts
- OFL files in `resources/fonts` (Amiri Regular/Bold, Noto Naskh Arabic variable, Noto Sans Arabic
  variable, with their OFL licences). They are embedded as base64 `@font-face` in the rendered HTML, so
  PDFs depend on neither the network nor system fonts. English uses Amiri's Latin glyphs (it ships a
  Latin serif) for a consistent look.
- Coverage test: parse the `cmap` of each font file (tiny PHP TTF cmap reader in the test, no package)
  and assert U+0621–U+064A, the Arabic-Indic digits, and the Sorani letters U+0695, U+06B5, U+06C6,
  U+06CE, U+06D5, U+06A4, U+067E, U+0686, U+0698, U+06AF are mapped in Noto Naskh Arabic (the Kurdish
  font). Amiri is used for Arabic, with Noto Naskh as fallback.

## R3. Template model
- `document_templates` rows are immutable versions:
  `(company_id, document_type, language_code, version, blocks json, options json, created_by,
  created_at)`, unique(company, type, language, version). The active template is the max version. With
  none, the code defaults in `app/Documents/Defaults/{type}.{lang}.php` apply (arrays of blocks).
- Blocks per type, documented in `contracts/templates.md`: e.g. defense letter: `title, from_label,
  from_value, to_label, cc_label, cc_value, subject_label, subject_value, date_label, salutation,
  opening, deadline_sentence, employee_block_labels`; recommendations: `title, mandate_sentence,
  lead_in, findings_intro, recommendations_intro, signature_line, role_labels{legal,hr,labor_union},
  chair_label, member_label`; decision letter: `title_warning, title_termination, opening,
  defense_outcome{rejected,partial,accepted}, decision_sentence, gm_title, union_title,
  acknowledgment_lines`.
- Options: `show_reference_number` (default on), `show_footer`, `header_mode` (logo_text | image).
- Placeholder syntax `{name}`; gendered `{m:أنت تعمل|f:أنتِ تعملين}`. The validator parses every block,
  checks names against the allow-list in `contracts/templates.md`, and checks gender groups are
  well-formed and required blocks exist.
- `company_letterheads` rows are versioned the same way (immutable, latest active) per language; the
  logo file path is on `companies` (stored on the private disk; versioned by keeping old files).
- `documents.render_snapshot` json: `{template_versions: {ar: 3, en: 2}, letterhead_versions: {...},
  renderer: 'v1'}`, set at issuance. The PDF is the record; the snapshot explains it.

## R4. Rendering pipeline
- `App\Documents\DocumentRenderer::render(Document $doc, RenderMode $mode): string` (HTML). A
  `DocumentFacts` collector builds the fact set from the case (employee, gender, hire date, position,
  department, branch, company, issue date, reference number or provisional reference, response due,
  mandate in effect, session date, attendees with roles and chair, GM, union attendee, penalty type,
  defense acceptance). A missing required fact throws `MissingDocumentFact` naming it. Blade views
  `resources/views/documents/{layout,defense_letter,recommendations,decision_letter}.blade.php` render
  each language page in order (ar, en, then extras), separated by `page-break-before`.
- Body: `Str::markdown($body, ['html_input' => 'escape', 'allow_unsafe_links' => false])`, then a strict
  allow-list post-filter (`p, ul, ol, li, strong, br`). Citation markers are replaced before markdown by
  `CitationRenderer` (legal_units verified only; else exception).
- `IraqiDate::format(Carbon, lang)`: ar months كانون الثاني, شباط, آذار, نيسان, أيار, حزيران, تموز,
  آب, أيلول, تشرين الأول, تشرين الثاني, كانون الأول.
- Draft mode adds a CSS watermark and a provisional reference from `DocumentIssuer::previewReference`.

## R5. PDF job
- `GenerateDocumentPdf` (queued, 3 tries, backoff) runs after the issuance transaction commits
  (`afterCommit`). It renders in Issued mode, Browsershot `->format('A4')->margins(...)
  ->showBackground()->pdf()`, stores to `storage/app/private/documents/{company}/{case}/{type}-v{version}.pdf`,
  and sets `pdf_file_path` (allowed once by the trigger). A failure is logged and alerted to HR; the
  document stays issued and a retry button comes later.
- Download route `documents/{document}/pdf` uses the `DocumentPolicy::view` → `CaseVisibility` check
  and streams from the private disk.

## R6. Customisation UI (Filament, admin panel)
- Page `DocumentTemplates`: select type + language → form with one textarea per block (Arabic fields
  RTL), placeholder cheat-sheet, live HTML preview (iframe srcdoc with sample facts), version history
  list. Save → `TemplateService::saveVersion` (validate, audit).
- `CompanySettings` page gains the letterhead per language section, logo upload (png/jpg ≤ 2 MB; SVG
  not accepted, which avoids sanitising), and optional header image.
- Policies: company_admin and platform_admin (in tenant) edit; hr_staff preview only.

## R7. Seeds
- Pilot company letterhead (ar/en) with anonymised details (no real phone/email), a placeholder logo
  (generated simple PNG, not the real brand), and employee genders. Two anonymised demo cases mirroring
  the sample flows (warning path; training-only path) with bodies paraphrasing the structure, not real
  facts.

## R8. Review resolutions (Codex plan review, 2026-10-05). These win over R1–R7 where they differ

- **Frozen render input (A1)**: inside the issuance transaction, `CaseDocumentIssuance` renders the
  document in Issued mode (final reference number, no watermark) and stores the HTML without embedded
  fonts in `documents.rendered_html` (longtext, set once, protected by the locked-document trigger).
  `render_snapshot` records template / letterhead version ids, the logo sha256 and the renderer version.
  `GenerateDocumentPdf` converts **only** `rendered_html` (fonts injected at conversion), so later changes
  to employee or case data can never reach an issued PDF.
- **Citation gate at issuance (A2)**: `CitationGate::assertIssuable(Document)` runs before rendering. For
  each `[[cite:id]]` in any language body there must be a `citations` row on this document for that
  legal unit. The unit must be `verified_*`, be the current non-superseded version at citation time,
  and belong either to a statute applicable to the case's `jurisdiction_at_opening` (via
  `law_jurisdictions`) or to an *approved* internal regulation of the case's company. Its
  `relevance_check` must be `supports`, or `partial` / `does_not_support` with `overridden = true` and
  an overrider recorded. Any `المادة` / `مادة` / `Article` + number in the prose outside a marker is a
  hard error (regex). The same gate replaces the feature-003 `citation_check_passed` payload flag in the
  `*_citation_check → *_ready_for_review / awaiting_approvals` transitions: the guard calls
  `CitationGate` on the current draft. An override is a recorded citation decision, not a boolean flag.
  `CitationRenderer` then prints "المادة (X) [البند (Y)] من {law name}" / "Article X [clause Y] of {law}"
  from the unit, never from model text.
- **Once-only PDF files (A3, B6)**: new `documents` columns `pdf_status` (pending | ready | failed),
  `pdf_error`, `pdf_sha256`. The job is idempotent: if `pdf_status = ready` it exits. It writes to a
  unique temp file, verifies it is non-empty and starts with `%PDF`, and computes sha256. It then
  promotes the file to the final path with an exclusive create (`fopen(path, 'x')`, then copy and fsync),
  never replacing an existing file. If the final file already exists with the same sha it is adopted,
  and a different sha is an integrity failure (alert, no overwrite). Finally it sets path + sha +
  status ready in one update (trigger allows null→value once and pending/failed→ready). On failure
  after 3 tries: `pdf_status = failed` with the error, an HR alert (case event + notification), and
  `php artisan documents:retry-pdf {id}` (later a button). Download: `ready` streams the file and checks
  the sha; `pending` / `failed` answer 409 with an Arabic message. A superseded previous version stays
  downloadable, and the case page shows both.
- **Sections the samples need (B1)**: `document_contents` gains `secondary_body` (text, nullable,
  protected when locked). For recommendations, `body` = findings and `secondary_body` = the committee's
  recommendations (both required); other types use `body` only. Prior record: the defense letter
  prints a `prior_record` block followed by the list from the DB only when `include_prior_record` is
  ticked; recommendations always print `prior_record` + list when prior cases exist. Lines are
  formatted from the DB (date, type, outcome); templates hold only the intro wording.
- **Mandatory official fields (B2)**: reference number and issue date are part of the fixed layout
  (no option to hide). The `show_reference_number` option is removed. The validator also enforces
  required placeholders inside blocks (salutation ⊇ `{employee_first_name}`; opening ⊇ `{hire_date}`
  and `{position}`; deadline_sentence ⊇ `{response_deadline}`; mandate_sentence ⊇ `{mandate_number}`,
  `{mandate_date}`, `{session_date}`; decision_sentence ⊇ `{penalty}`; gm/union titles render with
  names from the DB). After resolution, the renderer asserts no `{` placeholder remains.
- **Trust boundary (B3)**: all template blocks, letterhead fields, placeholder values and citation text
  are escaped (Blade `{{ }}`; placeholder values escaped before substitution into escaped block text,
  with line breaks converted after escaping). Only `BodyFormatter` output is printed raw, and it is
  allow-list filtered. The preview iframe uses `sandbox` (no scripts, no same-origin) with `srcdoc`.
  Chrome runs with JavaScript disabled, every non-`data:` request blocked (request interception /
  `blockUrls(['*'])` equivalent), no `--allow-file-access-from-files`, and with `--no-sandbox` only
  inside the container.
- **Letterheads (B4)**: the backfill creates `ar` **and** `en` version 1 from the existing company
  columns (en name = company name; flagged `needs_review = true`, shown as a warning in settings).
  Logo and header image belong to the letterhead **version**: files are stored content-addressed
  (`letterheads/{company}/{sha256}.{ext}`), and each version row stores path + sha256. `companies.letterhead_logo_path`
  is deprecated.
- **PDF tests (B5)**: short fixtures assert language order (ar first), `dir` per page, letterhead and
  footer on each page, and the page break between languages. A long-body fixture asserts more than one
  page per language with the footer repeated. PDF tests call the real container Chrome and **fail**
  (not skip) when it is unavailable. A Chrome-failure test (bad chrome path), followed by
  `documents:retry-pdf`, covers recovery.
- Logo uploads: PNG / JPG only (no SVG). Draft preview wording: "what will print, except the
  provisional reference number and the DRAFT watermark".
