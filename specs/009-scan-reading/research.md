# Research: AI Reading of Scans

## R1 Model calls
- Reuse `App\Ai\ClaudeClient` (008) — extend the interface with content blocks: messages may include
  `['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png|image/jpeg', 'data' => …]]` or
  `['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => …]]`
  (check the SDK array keys in vendor/anthropic-ai/sdk/src, as 007 did for `mediaType`). Read the first TextBlock.
- Model `displanery.ai.reading_model` (default `claude-opus-5-5`), effort `high` for transcription, `medium` for
  presence. Structured output JSON schema. Touchpoints `transcribe_response`, `signature_presence`.
- Limits: attachments are already ≤ 10 MB; PDFs ≤ 20 pages (pdfinfo from 007's PdfPages); images downscaled to a
  2000 px long edge (handwriting needs more detail than 007's 1568 px law pages — the API downsizes larger images).
- Missing key → PermanentAiFailure (no retries). Retryable errors: 2 retries with backoff. Job $timeout 300.

## R2 Transcription workflow
- `ResponseReading::request(case, actor)`: allowed at `response_scanned_uploaded` (latest EmployeeResponseScan),
  token on the case like 008 (`ai_request_*`, document type `response_transcription`) so one request at a time; the
  case status does not change while the job runs (no AI state needed: nothing is saved into the case).
- Job `TranscribeResponseScan`: calls the model, stores a `response_transcriptions` row (origin ai), clears the token;
  on failure clears the token and logs `response_reading_failed`.
- `ResponseReading::confirm(case, text, origin, actor)`: in one transaction, case locked, at
  `response_scanned_uploaded`: insert a `response_transcriptions` row for the final text (origin `ai_checked` or
  `human`), set `employee_response_text`, `employee_response_checked_by/at`, transition to `ai_ingested_response`,
  case event `response_transcription_confirmed`, audit. Editing after confirmation is not offered (the committee may
  already rely on it); a correction is a new confirm from `ai_ingested_response` → back edge exists
  (`ai_ingested_response → response_scanned_uploaded`) used by "تصحيح النص" with a reason, then confirm again.
- Prompt: transcribe the handwritten response verbatim in its own language; keep line breaks; mark unreadable words
  [؟]; do not correct, summarise or translate; `illegible=true` if large parts are unreadable.

## R3 Using the response in 008
- `PayloadBuilder` (recommendations): add `employee_response` = minimized `employee_response_text` only when
  `employee_response_checked_at` is set. DraftPrompt: the response is the employee's own account; summarise it only
  in findings when relevant; never quote numbers not in the input (the number guard already applies).

## R4 Signature presence
- Split 006's atomic `SignaturePresence::recordSignedScan` into `uploadSignedScan(document, file, hr)` (stores the
  SignedDocumentScan attachment, case event, dispatches `SuggestSignaturePresence`) and `recordPresence(document,
  attachment, presence, hr, ?suggestionId)` (the existing validation and signature rows; the attachment must be the
  latest signed scan of that document). `recordSignedScan` stays as upload + record for callers/tests.
- Job input: the scan + attendee list (attendance id, printed name, role label as printed on Doc A). Output per
  attendance id: `appears_filled | appears_empty | unclear` + reason ≤ 120 chars. Unknown ids are ignored; missing
  ids → unclear.
- Stored in `signature_presence_suggestions` (append-only). Signature rows gain `ai_suggestion` (nullable enum) so the
  record shows whether HR followed it.
- Wording: one helper `PresenceWording` for every label; a test scans the views, notifications and JSON responses for
  forbidden words (موثّق/تم التحقق/أصلي/verified/authentic/genuine/identity).

## R5 Screens
- Case page (response stage): "قراءة الرد بالذكاء الاصطناعي" (policy), polling while the token is set, review panel:
  scan preview (image inline; PDF via the attachment download link / embedded object), editable transcription
  textarea pre-filled with the latest AI attempt (or empty), "تأكيد النص بعد مراجعته مقابل الصورة" + manual entry;
  after confirmation, the text with the transcription label and checker.
- Committee screens (RecordSession, ReviewApproval, ReviewConsultation): show the confirmed text with the label.
- SignedScan screen: step 1 upload, step 2 presence form pre-filled from the latest suggestion (polling while it
  runs), AI suggestion and reason next to each attendee, HR's choice required for unclear rows.

## R6 Tests
- Fake client returning transcription / presence outputs and failures; request/confirm/correct flow; token
  concurrency; manual path; payload of 008 includes the response only after confirmation; presence pre-fill,
  unclear requires a choice, signature rows record ai_suggestion; wording scan; authorisation per role; PDF/image
  block construction (no network).
