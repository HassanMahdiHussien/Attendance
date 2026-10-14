# Feature Specification: Low-Cost Legal Library Import

**Feature Branch**: `030-low-cost-legal-import`

**Created**: 2026-10-14

**Status**: Draft

**Input**: Product owner (2026-10-14): importing the three built-in laws with Claude Opus 5.5 reading every page costs about $7–10; make it close to $0, once. Approved plan: cheaper page reading (Sonnet 5.5, low effort, Batch API), readings supplied from outside the API (a transcription made in a Claude Code session, or a trusted typed copy of the law), and an export/import of the verified library so production never pays again. OCR-first for company regulations is the separate feature 031.

## Clarifications

### Session 2026-10-14

- Q: Does the two-reading integrity rule (CLAUDE.md §23.2) change? → A: No. Every unit still needs two independent readings compared after normalisation; identical → `verified_auto`, different → mismatch queue → a human checks the official page image → `verified_human`. Only *where* a reading comes from changes.
- Q: Which sources may supply a reading? → A: (1) the PDF text layer or Tesseract OCR (already built), (2) the Claude API (now cheaper: configurable model and effort, Batch API), (3) an **external page transcription** uploaded by the Platform Admin (e.g. made in a Claude Code session) keyed by page number, (4) an **external secondary text** of the whole law (e.g. the typed copy of Law 37/2015 from cku.atu.edu.iq) split into articles and compared article by article. Every reading records its source and who supplied it.
- Q: Is the typed copy authoritative? → A: No. It is only a comparison reading. The official gazette PDF and its page images stay the source of truth; page numbers of units come from the gazette.
- Q: The typed copy's text extraction swaps some joined letter pairs («املادة» for «المادة», «ألحكام» for «لأحكام»). → A: A deterministic cleanup step for that source corrects the known swaps before comparison; anything it misses lands in the mismatch queue like any other difference.
- Q: Which model reads pages by default? → A: `claude-sonnet-5-5` at effort `low`, configurable by env (`LEGAL_READING_MODEL`, `LEGAL_READING_EFFORT`). Drafting models are unchanged (Opus 5.5).
- Q: Batch API? → A: A run can be read "now" (per-page queued calls, as today) or "in batch" (one Message Batch for all pages of the run, ~50% cheaper, results within hours); the Platform Admin picks per run; batch is the default for built-in laws.
- Q: Library export/import? → A: `php artisan legal:export` writes the verified built-in laws (laws, versions, jurisdictions, units with verification status, page references, source PDF hash) to versioned JSON files under `database/legal-library/`; `php artisan legal:import` (also called by the laws seeder and the deploy) loads them idempotently with no AI calls. Company internal regulations are never exported.

## User Scenarios & Testing *(mandatory)*

1. **Given** Law 37's gazette PDF (broken text layer), **When** the Platform Admin uploads a page transcription file for the run and attaches the typed copy as the secondary text, **Then** units are built from the transcription (gazette page numbers kept), compared article by article against the cleaned typed copy, matches become `verified_auto`, differences enter the mismatch queue, and no API call is made.
2. **Given** Law 71 (working text layer), **When** the run is read in batch with Sonnet at low effort, **Then** one Message Batch is created, results are applied page by page as they arrive, failures fall back to the existing retry/unavailable path, and every call is logged in `ai_generation_logs` with model, effort and batch id.
3. **Given** a verified library, **When** `legal:export` then `legal:import` run on a fresh database, **Then** the same laws, versions, units, statuses and page references exist, nothing is re-read, and running import twice changes nothing.
4. **Given** an external reading, **Then** the import page and the unit's provenance show its source and uploader; the audit log records the upload.

## Requirements *(mandatory)*

- **FR-001**: Page reading model and effort configurable (`displanery.ai.legal_reading_model` default `claude-sonnet-5-5`; new `legal_reading_effort` default `low`), used by `ClaudePageReader`.
- **FR-002**: Batch reading mode: submit all pending AI pages of a run as one Message Batch (custom_id per page), poll from the scheduler, apply results by custom_id, map errors/expiry to the existing failed/unavailable states, log each result in `ai_generation_logs`; cancel cancels the batch.
- **FR-003**: External page transcription: Platform Admin uploads a JSON (or text with page separators) of `{page_number: text}` for a run; it fills one reading slot with provenance (`source = transcription`, uploader, uploaded at, optional note such as "Claude Code session"); validation: page numbers within the run's range, non-empty text.
- **FR-004**: External secondary text: Platform Admin attaches a PDF/text of the whole law as the other reading; it is extracted (text layer), cleaned (FR-005), split into articles with the existing splitter, and compared article-level against the page-based reading; units keep page numbers from the page-based reading.
- **FR-005**: Cleanup for the known swapped-letter artifact of Word-made PDFs, applied only to secondary sources, unit-tested on real sample lines.
- **FR-006**: Every reading records its source (`text_layer`, `ocr`, `api`, `api_batch`, `transcription`, `secondary_text`); the mismatch screen and unit provenance show it.
- **FR-007**: `legal:export` / `legal:import` commands and versioned JSON under `database/legal-library/`; built-in (platform) laws only; import idempotent and verifies file integrity (hash); LawsSeeder uses import when files exist.
- **FR-008**: No change to citability: only `verified_*` units are citable; a law imported from the library keeps the verification status recorded at export.

## Success Criteria

- Built-in law import costs ≤ $1 in API spend for all three laws (or $0 with transcription + secondary text).
- A fresh environment gets the full verified library with zero AI calls.
