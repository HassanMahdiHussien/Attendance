# Tasks: Case Screens — Intake to Response

**Tests**: REQUIRED. Pest + Livewire on MySQL; the orchestrator walks the screens in the browser.
Gates: `docker compose exec -T laravel.test php artisan test` · `… ./vendor/bin/pint --test`.

## Run J
- [ ] T001 LoginAttempts service extracted from the Filament Login (both use it); Livewire `/login`, `/logout`; `Landing::for`; guest redirect to /login
- [ ] T002 Workspace layout (mockup top bar, role nav, company name, initials avatar, sign-out, setup link for admins), ArabicDigits, StageIndicator
- [ ] T003 ReportService + MyReports / SubmitReport (branch scope, locations, responsible person search or free text, witnesses, attachments) + Inbox (dismiss with reason, convert)
- [ ] T004 CaseDrafts + CreateCase (direct or from report; employee search; location; witnesses; assigned HR; duplicate check step; open via CaseOpening)
- [ ] T005 Board (CaseVisibility, urgency sort, red banner, card anatomy, 5 dots, pills, filters, pagination); MyCases (read-only list); Home placeholder
- [ ] T006 Tests: role landing + route matrix (contracts/screens.md), shared throttle, report scope / status privacy, convert / dismiss, duplicate step, board sort / pill / visibility

## Run K
- [ ] T007 ShowCase (header, banners: priors / responsible person / conflicts / deadline, deadlines panel, timeline in Arabic, documents + versions + PDF status, attachments, stage actions per contract)
- [ ] T008 DocumentDrafts + EditDocument (ar / en bodies, live preview via DocumentRenderer draft mode, save draft, submit for review → workflow transitions incl. CitationGate)
- [ ] T009 ConfirmDocument per mockup (Amiri preview card, warning, editable provisional reference, SecondReview, issue via CaseDocumentIssuance, send back); PDF download states; correction with reason
- [ ] T010 AttachmentService; hand-off forms (receipt; response received / refused / delivery refused with witnesses / place / attempter); response scan upload; "no response" after the deadline
- [ ] T011 Tests: every case-page action (auth, validation, state, case event), concurrency message, 404 for invisible cases, upload rules, second-review flow, correction flow
- [ ] T012 docs: WEIRDNESS (shared sign-in admits all roles to their landing while panels stay role-limited; 404 for invisible cases); orchestrator walkthrough + PR
