# Tasks
- [x] T1 Storage: assistant-session files (attachments linked to the session, re-linked to the report on send), extracted-text column, limits (types, 50 MB video / 10 MB others, max 10)
- [x] T2 `DocumentText` service: eml (reuse email intake parsing), msg (hfig/mapi), docx (ZipArchive), pdf (pdftotext → AI pages when empty), image (AI: text or none), video (none); queued job before the assistant turn; AI calls logged
- [x] T3 ReportAssistant: extracted text as masked source material; traceability against typed + extracted text; first reply after files shows server-rendered found facts + the one missing question
- [x] T4 Reporter UI: attach on the start step and paperclip in the composer (names, size, remove; no processing labels); files-only start; review lists files
- [x] T5 HR new case: "Attach an email or document" drop zone, AI-proposed field values, names left to match, files saved to the draft case
- [x] T6 Translations, help guides, behaviour inventory, tests (FR-007), Pint, full suite, browser check
