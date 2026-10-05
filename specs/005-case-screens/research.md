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
