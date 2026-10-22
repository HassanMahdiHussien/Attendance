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

## R7 Plan review resolutions (Codex review, 2026-10-07) — override R1–R6 where they differ
- **A1 Read boundary**: the only response text any consumer may read is the confirmed one, exposed through one
  accessor `DisciplinaryCase::confirmedResponseText(): ?string` (returns `employee_response_text` only when
  `employee_response_checked_at` is set and no correction is pending). PayloadBuilder, RecordSession,
  ReviewApproval, ReviewConsultation and the case page's committee-facing panels use it; AI attempts are shown only
  inside the HR transcription review panel (HR roles), never elsewhere. Tests at `response_scanned_uploaded` with a
  pending job and with an unconfirmed attempt assert nothing leaks to committee/consultation screens or payloads.
- **A2 Correction**: "تصحيح النص" (reason required) is one transaction: clears `employee_response_text`,
  `employee_response_checked_by/at`, `employee_response_origin`, writes a case event with the reason and the previous
  text's transcription id, and transitions `ai_ingested_response → response_scanned_uploaded`; the next confirm
  writes a new transcription row. The intermediate state therefore has no confirmed text.
- **A3 Signed scan binding**: signature rows of committee presence gain `attachment_id` (the scan they were recorded
  against). "Latest completed scan" = the newest SignedDocumentScan attachment of the document that has presence rows
  for every attendee. `latestChecks()` reads rows of that scan only; `overrideEmpty()` rejects when a newer scan
  exists without presence rows ("سُجّل مسح أحدث لم تُسجّل خاناته بعد"). `recordPresence` requires the attachment to be
  the newest signed scan of the document. `recordSignedScan` stays the atomic one-submit wrapper (006 behaviour and
  tests unchanged).
- **A4 Logs never store scans**: the 008 client gains a log-safe input: content blocks of type image/document are
  replaced in the logged JSON by `{type, media_type, attachment_id, sha256, bytes}`; prompts and attendee lists stay.
  A test asserts no base64 of the scan appears in `ai_generation_logs.input`. 007's page reader already logs only the
  image hash.
- **Product owner decision (2026-10-07)**: before an AI transcription HR must tick "تحققتُ أن الصورة لا تتضمن رقم هوية
  أو راتباً أو رقم هاتف"; if the scan contains such data, HR uses the manual path. The tick is stored with the request
  (case event + audit). The signed recommendations scan is our own document (no IDs/salaries/phones by template
  design), so it needs no tick.
- **B1 Tokens**: both jobs check, under the case lock, token + operation type + stage + attachment id before saving;
  manual confirm / manual presence recording clears a pending request's token (case event `ai_request_superseded`);
  `failed()` only clears its own token. Races tested: manual confirm before job, session recorded during job, new
  upload during job, reconcile (reuse `ai:reconcile-drafting` for the two new types without status changes).
- **B2 Manual path**: the SignedScan screen keeps today's one-submit form (upload + presence) as the default when the
  AI is unavailable or HR chooses "تسجيل يدوي"; "رفع وقراءة بالذكاء الاصطناعي" is the optional two-step path.
- **B3 Provenance**: signature rows gain `presence_suggestion_id` (FK to the suggestion row, same tenant/document/
  scan, validated) and `ai_suggestion`; "accepted / changed" is derived by comparing HR's recorded value with the
  suggestion (unclear always counts as changed). The Signature model guard already blocks updates/deletes; the new
  columns are set only on insert (no trigger exists to extend; add an update-guard test).
- **B4 Signed scan scope**: whole Doc A scan is sent (it is our own document: letterhead, recommendations text,
  printed names/roles, signatures); no cropping in this feature.
- **B5 SDK shapes**: use the PHP SDK array keys (`mediaType`, as 007's reader) for image and PDF sources; a test builds
  the blocks through the real adapter's request-building path (no network) and asserts the shapes.
- **Nits**: wording test checks rendered presence UI and JSON for identity *claims* (positive statements), allowing
  explanatory copy such as "لا يتحقق النظام من هوية الموقّع".
