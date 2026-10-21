# Research: Case Screens — Intake to Response

## R1. Shell and routing
- Full-page Livewire 4 components under `app/Livewire/` with the layout
  `resources/views/layouts/workspace.blade.php` (mockup top bar). Routes in `routes/web.php` under the
  `auth` middleware (the global web middleware already sets tenant context, active-account and idle
  checks): `/cases`, `/cases/new`, `/cases/{case}`, `/cases/{case}/documents/{document}/edit`,
  `/cases/{case}/documents/{document}/confirm`, `/reports`, `/reports/new`, `/reports/inbox`,
  `/my-cases`, `/home` (role landing).
- Route-model binding for `{case}` resolves through `CaseVisibility::for(auth()->user())`, so a case
  outside the user's visibility is a 404, never a 403 that would reveal it exists.
- Each component authorises in `mount()` through policies, and again in every action method (Livewire
  actions are public endpoints).

## R2. Shared sign-in
- Extract the hardening from `App\Filament\Auth\Login` into `App\Services\Auth\LoginAttempts`
  (normalise email, per-email limiter check / hit / clear, `auth.throttled` audit, generic failure).
  Both the Filament page and the new `App\Livewire\Auth\Login` use it. Laravel's `Auth::attempt` plus
  `canSignIn()` (active user, active company, role allowed somewhere) run inside a timebox like
  Filament's. On success the session is regenerated, and the role landing comes from
  `App\Support\Access\Landing::for(User)`: platform_admin → /platform, company_admin / hr_staff →
  /cases, reporter / dept_manager → /reports, committee_member / external_legal_reviewer → /my-cases,
  hr_partner_staff → /home.
- The `auth` middleware redirect goes to `/login` (`redirectGuestsTo`). The panel logins stay.
- Feature 002 refused sign-in to roles without screens; `canAccessPanel` stays role-limited for the
  panels, while `/login` now admits every active role to its landing.

## R3. New thin services (logic stays out of Livewire)
- `ReportService::submit(array, User)` validates the reporter's branch scope (`branch_user`) and the
  location's branch, stores witnesses and attachments, status received. `dismiss(report, reason, hr)`
  and `convert(report, hr)` produce a draft case via `CaseDrafts`, set the report status and link it.
- `CaseDrafts::create(array, User)` / `update(case, array, User)` creates and edits a case in `draft`
  (facts, witnesses, assigned HR), inside a transaction (case number). Opening calls the existing
  `CaseOpening::open`.
- `DocumentDrafts::createOrUpdate(case, type, ['ar' => body, 'en' => body…], User)` handles
  non-recommendation drafts (recommendations keep `RecommendationsEditor`). It refuses locked documents
  and enforces the enabled languages.
- `AttachmentService::store(attachable, kind, UploadedFile, User)`: mime sniffing (pdf / jpeg / png),
  ≤ 10 MB, content-addressed private path, append-only row.
- `SecondReview::mark(document, User)` sets `second_reviewed_by/_at` when the user ≠ the drafter and is
  HR / admin. It was not exposed before.

## R4. Board
- Query: `CaseVisibility::for($user)` with eager loads. Urgency key: overdue procedure first, then
  approaching, then active by procedure due date, then closed. Pagination 25. Filters as query string.
- 5-stage indicator mapping (contracts/screens.md): 1 opened (draft / open / defense drafting stages),
  2 defense letter issued (defense_letter_issued … awaiting_employee_response), 3 response (response_*,
  ai_ingested_response), 4 committee (committee_session_held … awaiting_external_legal_consultation,
  decision_letter_ready_for_review), 5 decision (decision_issued, appeal stages, archived_final).
  Archived = all 5 green.
- Pill: overdue / ≤ 2 days to the procedure deadline → red "باقي N يوم" or "متأخرة"; awaiting
  signatures / response → amber; archived → green "مغلقة"; else indigo stage name.

## R5. Confirmation screen
- Renders the document with `DocumentRenderer` in draft mode inside the gray card (Amiri). The
  reference input is pre-filled with `DocumentIssuer::previewReference`; an empty field = generated,
  edited = manual. The warning text comes from the mockup. Buttons "✓ تأكيد وإصدار" (primary indigo,
  rounded-2xl, shadow-indigo-200) and "تعديل". A second-review notice and button appear when required.

## R6. Digits and dates in the UI
- `App\Support\Format\ArabicDigits::convert()` for UI numbers (case number, remaining days), and
  `IraqiDate` for dates. Documents keep Western digits.

## R7. Testing
- Livewire component tests for every action (authorisation, validation, service calls, resulting
  state and case event). HTTP tests for role landing and direct-URL refusal per role (404 for
  invisible cases). The board sort and pill mapping are unit-tested. Browser walkthrough by the
  orchestrator.

## R8. Review resolutions (Codex plan review, 2026-10-05). These win over R1–R7

- **Reports inbox (A1)**: company_admin and hr_staff see every `received` / `under_review` report of
  their company (intake is HR's daily job; not limited by "opened / assigned").
  `ViolationReportVisibility::inbox(User)` is tested with an HR user without the add-on. Converted and
  dismissed reports follow the existing rule.
- **Explicit transitions per action (A2)**:
  - Confirm and issue: `DefenseLetterFlow::confirmAndIssue(document, actor, ?manualRef)` runs, in one
    transaction, `CaseWorkflow` ready_for_review → confirmed and then `CaseDocumentIssuance::issue`.
    "Send back" is ready_for_review → open.
  - Submit for review: open → defense_letter_citation_check → defense_letter_ready_for_review (the
    second edge runs the CitationGate guard). A gate failure leaves the case in citation_check with the
    reason shown, and "back to edit" returns it to open.
  - Response scan: `ResponseService::attachScan(case, file, actor)` stores the attachment
    (`employee_response_scan`) and transitions to `response_scanned_uploaded` atomically. A failure
    rolls back both.
  - Response hand-off: `ResponseService::recordHandOff(...)` = `HandOffService` (purpose
    response_submission) + the matching response transition.
  - No response: `ResponseService::markNoResponse(case, actor)` is allowed only after the response due
    date. It writes a `response_not_received` case event and does **not** transition. Feature 006's
    session recording is already allowed from awaiting_employee_response after the deadline. No fake
    session.
- **Abilities enforced in services (A3)**: `DisciplinaryCasePolicy` gains `update`, `manageDocuments`,
  `confirm`, `secondReview`, `issue`, `correct`, `assign`, `recordHandOff`, `uploadAttachment`. All are
  true only for company_admin / hr_staff who can see the case via `CaseVisibility`; `viewAny` is no
  longer unconditional. New services and the existing `CaseDocumentIssuance`, `DocumentCorrector`,
  `HandOffService` and `CaseDrafts` call `Gate::forUser($actor)->authorize(...)`, and Livewire actions
  authorise too. Tests call the services directly with a committee member / reporter / other-company
  HR and expect refusal.
- **Second review freshness (A4)**: any content change through `DocumentDrafts` (or a correction)
  clears `second_reviewed_by/_at`. The second reviewer must differ from the user issuing and from the
  last content editor (`documents.last_edited_by`, new nullable column set by DocumentDrafts). Test:
  edit after review → issuance refused until reviewed again.
- **Shared sign-in (B1)**: `LoginAttempts` covers both buckets, per-email and per-IP (5/min each, the
  same keys used by the panel page), so attempts across `/login` and the panel logins share limits. A
  forced logout outside a panel redirects to `/login`; inside a panel it still goes to the panel login.
  Test: panel sign-in, then workspace navigation in the same session; mixed-page throttling.
- **Nested binding and downloads (B2)**: components resolve `{case}` via CaseVisibility, then
  `{document}` must belong to that case; otherwise 404. Attachment download
  `/attachments/{attachment}`: case attachments → CaseVisibility of the case; report attachments → HR
  inbox users of that company, or the case's viewers once converted; reporters cannot download (they
  see status only). Wrong-parent or invisible → 404.
- **Uploads (B3)**: `AttachmentService::store(parent, kind, file, actor)` authorises the parent, checks
  the kind is allowed for the parent type (report: report_evidence; case: employee_response_scan,
  delivery_proof, signed_document_scan), sniffs the real MIME (finfo) to be pdf / jpeg / png, enforces
  ≤ 10 MB, and writes content-addressed under the private disk. The row is created only after the file
  is stored; a failure deletes the file. Tests: spoofed extension, oversize, wrong tenant / parent, kind
  not allowed.
- **Duplicate path (B4, orchestrator decision)**: when HR converts a report and picks "link to existing
  case", the report becomes `converted_to_case` with `case_id` = the existing case. No new case number
  is created. Case event `duplicate_report_linked` and an audit entry are written. For case-to-case
  duplicates, `CaseInsights::linkDuplicate` is used.
- **Assignment (B5)**: `CaseAssignment::assign(case, hrUser|null, actor)`. Only company_admin, or the
  currently assigned / opening HR user, may reassign. The assignee must be active hr_staff /
  company_admin of the same company. It writes the case event and audit. Tests: old assignee loses
  visibility (without the add-on), new gains it, and alerts go to the new one.
- **Provisional reference (B6)**: the confirmation component keeps the prefilled value and a dirty
  flag. Unchanged → `manualRef = null` (generated at issuance); edited → manual. After issuance the
  screen shows the actual number with a note when it differs from the preview. Test: two HR users
  issue concurrently from previews showing the same number → distinct numbers, and the second sees
  the note.
- **Livewire boundary tests (B7)**: action tests go through the real Livewire update endpoint (pattern
  in tests/Feature/Auth/SessionTest.php) for: reauthorisation after reassignment, a deactivated user,
  and a stale stage (the second HR action after the first changed the stage → "تغيرت الحالة، أعد
  التحميل" message, no 500).
- **Nits**: phones show a compact stage label instead of the 5 dots; documents keep Western digits (the
  real pilot letters are the authority for documents; the mockup's digits apply to UI chrome only);
  MyCases / Home have clear empty states and no action buttons in 005.
