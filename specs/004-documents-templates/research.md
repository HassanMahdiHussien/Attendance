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
