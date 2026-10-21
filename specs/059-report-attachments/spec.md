# Feature Specification: Attachments the assistant understands

**Branch**: `059-report-attachments` (stacked on `058-readability-pass`) | **Created**: 2026-10-19 | **Status**: Approved

**Input**: Product owner: the reporter can chat but cannot attach files in the chat; reporters often already wrote the case as an email, so they should attach it instead of typing again, and the AI analyses it; several files at once; the same for HR when opening a case. Images and videos are stored as reference; emails and documents are read. Mockup approved (v2, professional and quiet: no per-file "the AI will read this" labels, no "I read your email" narration): https://claude.ai/artifact/H3CRoskWiAx41pNp9LvfbC
Decisions (2026-10-19): readable files = saved email (.eml, .msg), PDF, Word (.docx), and images that are mostly text (screenshots of a message, email or written note — the AI decides; photos of a scene stay reference only); videos up to 50 MB per file, other files 10 MB; several files per report; `hfig/mapi` (MIT) approved and installed for .msg.

## Product principles this feature sets (apply to every future intake surface)
1. Take facts from whatever the person already has (typing, email, document, screenshot); never ask anyone to type a case twice.
2. Understand first, then ask only what is truly missing; show the person what was understood so they can correct it.
3. The AI proposes, a human confirms; facts stay traceable to their source.
4. A failure never blocks: an unreadable file is still kept as an attachment and the flow continues.

## Requirements
- **FR-001 Reporter attach (start)**: the "What happened?" step gets an "Attach files" control (multiple). Allowed: eml, msg, pdf, docx, jpg/jpeg, png, webp, mp4, mov; videos ≤ 50 MB, others ≤ 10 MB; at most 10 files per report. Each chosen file shows name, size and a remove button only — no processing labels. The reporter may start with files only (empty text) or text only.
- **FR-002 Reporter attach (during chat)**: a paperclip button in the chat composer adds files at any time; added files are analysed before the next assistant turn. Files live with the assistant session until the report is sent, then become the report's evidence attachments (same storage, scoping and download rules as today's report attachments). The review screen lists every file (already shows attachments).
- **FR-003 Reading**: a queued step extracts text per file before the assistant turn:
  - .eml → sender, date, subject, plain-text body (reuse the existing email intake parsing/sanitising code where possible);
  - .msg → same fields via `hfig/mapi`;
  - .docx → document text (ZipArchive + word/document.xml, no new package);
  - .pdf → text layer (pdftotext) and, when it is empty, the AI reads the pages;
  - images → the AI transcribes the text only if the image is mostly text; otherwise records "no text" (reference only);
  - videos → never read (reference only).
  Extracted text is stored per file (immutable after the report is sent) and every AI reading call is logged in `ai_generation_logs`. A file that cannot be read stays attached and the flow continues (FR principle 4).
- **FR-004 Assistant uses the text**: extracted text joins the reporter's own words as source material for the assistant turn — masked by `PayloadBuilder` exactly like typed text — and facts taken from it pass the same traceability checks (a fact must be traceable to the reporter's typed words or to extracted file text). The assistant's first reply after files were added shows the facts found (rendered by the server from the validated facts: people, what, when, place — not AI prose about reading) followed by the one question that is still missing, or goes straight to the review when nothing is missing. No message says "I read your email".
- **FR-005 HR new case**: the blank "New case" form gets a quiet "Attach an email or document" drop zone (multiple). Readable files are analysed and the AI proposes values for the form fields (description, place, date/time when stated, violation type suggestion from the company list, names of the people concerned shown as names to match through the employee picker — never auto-selected). HR reviews every field before saving; the files are saved as the draft case's attachments. A file that cannot be read is kept and the form stays manual. The open-from-report screen already shows report attachments; no change there beyond listing the new file types.
- **FR-006 Rules (CLAUDE.md §3/§3b/§20)**: AI never invents facts or decides anything; minimal payload (masking of names/IDs/phones in extracted text); images/PDF pages go to the vision call only for transcription (as response scans do today); tenant and role scoping unchanged; files stay on the private disk; no AI call in tests with a real key.
- **FR-007** Translations (en + ar), Departures design (no new amber points), RTL, phone-friendly, behaviour inventory, help guides ("Submit a report", "Open a case") mention attaching files; tests with `FakeClaudeClient`: allowed types/sizes/count enforced (video 50 MB, others 10 MB, max 10), files-only start, .eml/.docx/.msg/pdf text extraction (fixtures), image classified as text vs scene, video never read, extracted text masked in the payload and used for facts, facts from a file pass traceability while invented facts are dropped, unreadable file kept and flow continues, files re-attached to the report on send and visible only to HR inbox scope, HR new case prefill from an email with names left to match, cross-tenant access refused.
