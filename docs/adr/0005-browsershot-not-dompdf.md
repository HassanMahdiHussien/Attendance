# 0005 — PDF generation via Browsershot (headless Chromium), not DomPDF/mPDF

**Status:** Accepted

## Context
All legal documents are generated as Arabic (and sometimes Kurdish) right-to-left PDFs, some bilingual with English. The standard PHP/Laravel PDF libraries (DomPDF, mPDF) have a well-documented history of mishandling Arabic character shaping, RTL ordering (especially in tables/numbers), and lack full Kurdish (Sorani) glyph coverage in most bundled fonts.

## Decision
Use Browsershot (Spatie's Laravel package), which drives a real headless Chromium instance to render an HTML/CSS template to PDF. Every document is built as a Blade template with `dir="rtl"`/`dir="ltr"` per section and an embedded font covering Arabic + Kurdish glyphs (Noto Naskh Arabic / Noto Sans Arabic, or a dedicated Kurdish font if gaps are found in testing).

## Consequences
**Gained:** correct RTL/bidi/shaping rendering "for free," because it's the same rendering engine as an actual browser — no custom text-shaping workarounds needed.
**Lost:** Browsershot requires a working Chromium/Node runtime on the server (heavier dependency than a pure-PHP library); PDF generation is slightly slower per document than DomPDF (acceptable at this scale — case volume is low).
**Must verify before building more templates:** Kurdish Sorani glyph coverage in the chosen font, tested in the first week of development (per CLAUDE.md tech stack notes) — if gaps are found, swap the font, not the rendering approach.
