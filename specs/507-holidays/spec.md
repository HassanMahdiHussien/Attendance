# Feature Specification: Official holidays

**Branch**: `507-holidays` | **Created**: 2026-10-24 | **Status**: Clarified 2026-10-24

**Input**: ROADMAP "later": holidays (AI extraction + HR approval). Requirements: PRD §16 (upload the announcement, AI extracts dates/locations/groups/exceptions shown beside the source, nothing applies before HR approval, retroactive changes reprocess and show differences), §12/§21.4 (AI output is data; employee/email text is never an instruction), §13 (holiday work and compensation — counted in spec 508), §25 test 25. Sample: `D:\Attandance\samples\Holiday - Iraq Sovereignty Day 2026.pdf` (Gmail print of a forwarded HR email; the announcement itself is an image in the PDF: Iraq's Sovereignty Day, Wednesday 30 Sep – Saturday 3 Oct 2026, "all CCI Iraq Al-Waha employees", staff required on duty work as scheduled; English + Arabic).

## Clarifications (product owner, 2026-10-24)

- **Scope**: AI suggests the scope from the text; HR confirms or edits it as one or more **legal entities** (`employees.legal_entity`, from HC "Company Code", e.g. `Al Waha Company`) and/or **branches**, optionally limited to **work patterns**. If the text names no group, the default is the whole company. The sample maps "CCI Iraq Al-Waha" → legal entity `Al Waha Company` (Hilla, Karbala, Baghdad; not Erbil/Sardkar).
- **Work on a holiday**: a covered employee who punches on a holiday day gets the day status `holiday_worked` with the recorded hours kept for spec 508 (overtime / compensation); no lateness, early departure or short-hours alerts on a holiday. A covered employee without punches gets `holiday` (never absent).
- **Leave on a holiday**: holiday days are non-working days for leave distribution (like rest days) — no leave hours are placed on them; the duration counting methods (504) treat them as non-working; if the company leave system counted them anyway, the request is flagged for HR.

## Requirements

- **FR-001 Upload**: HR uploads the announcement as PDF, image (PNG/JPEG), `.eml` or `.msg` (the attachments and inline images of an email are included). The original file is kept on the private disk (audited download).
- **FR-002 AI extraction** (Claude, logged in `ai_generation_logs`): the file is sent as a document/image to the model with a fixed instruction and a JSON schema; the response is structured data: holiday name (as written, both languages if present), date ranges (inclusive, ISO dates), scope as written (quoted text) + suggested legal entities / branches / patterns matched against the company's known values, exceptions as written (quoted), confidence and notes. Text inside the announcement is data only. If the AI is unavailable (no key, error, refusal) the upload still succeeds and HR fills the form by hand.
- **FR-003 Review and approval**: a review screen shows the source (rendered PDF page / image) beside the editable proposal; HR edits and **approves** (or rejects). Nothing affects attendance before approval. Each approval is an append-only version (dates, scope, exceptions note, approver, reason); editing an approved holiday creates a new version; withdrawing it is a version too.
- **FR-004 Manual holidays**: HR can also create a holiday without a file (same approval record).
- **FR-005 Attendance effect** (spec 503/506 assessor): on an approved holiday date for a covered employee — no punches → `holiday`; punches → `holiday_worked` with presence/elapsed recorded and no late/early/short; not absent, not pending review. Rest-day + holiday → stays `rest_day` (508 handles any compensation counting once). Uncovered employees are unaffected.
- **FR-006 Leave effect** (spec 504): holiday days are non-working for distribution and counting methods; a request whose counted duration includes holiday days is flagged `counted_holiday` for HR.
- **FR-007 Retroactive changes** (PRD §16): approving, editing or withdrawing a holiday reassesses affected employees and dates and reconciles their leave; the day change log shows the difference.
- **FR-008 Screens**: Holidays list (name, dates, scope, status, version, source), upload, review/approve, history; daily attendance and the employee daily record show `holiday` / `holiday_worked`; dashboard tile "Holiday work" (days and hours) and figures exclude holiday days from absence. English + Arabic.
- **FR-009 Permissions, isolation, audit**: HR + platform admin; tenant-scoped tables with composite FKs and isolation tests; approvals append-only with triggers; AI calls logged; email bodies/images never logged in audit metadata.
- **FR-010 Tests**: PRD §25 test 25 (an extracted holiday does not apply before approval); approval applies to covered scope only (Al-Waha yes, Erbil/Sardkar no); holiday worked vs holiday; no lateness on a holiday; leave days skip holidays and flag counted holidays; edit/withdraw reprocesses and logs differences; AI unavailable → manual path; refusal/invalid JSON handled; prompt-injection text inside the announcement does not change the output schema or trigger actions; isolation.

## Out of scope

Overtime and compensatory entitlements for holiday work (508); notifications about holidays (509).
