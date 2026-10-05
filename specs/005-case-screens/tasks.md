# Tasks: Case Screens — Intake to Response

**Tests**: REQUIRED. Pest + Livewire on MySQL; the orchestrator walks the screens in the browser.
Gates: `docker compose exec -T laravel.test php artisan test` · `… ./vendor/bin/pint --test`.

## Run J
- [x] T001 LoginAttempts service (per-email + per-IP buckets shared with the panel login); Livewire `/login`, `/logout`; `Landing::for`; guest redirect and non-panel forced-logout redirect to /login
- [x] T002 Workspace layout (mockup top bar, role nav, company name, initials avatar, sign-out, setup link for admins), ArabicDigits, StageIndicator
- [x] T003 ReportService + MyReports / SubmitReport (branch scope, locations, responsible person search or free text, witnesses, attachments via AttachmentService) + Inbox (ViolationReportVisibility::inbox; dismiss with reason; convert to a new case or link to an existing case per R8 B4)
- [x] T004 Policy abilities (R8 A3) enforced in new and existing services; CaseDrafts + CreateCase (direct or from report; employee search; location; witnesses; assigned HR; duplicate check step; open via CaseOpening); CaseAssignment
- [x] T005 Board (CaseVisibility, urgency sort, red banner, card anatomy, 5 dots, pills, filters, pagination); MyCases (read-only list); Home placeholder
- [x] T006 Tests: role landing + route matrix (contracts/screens.md), shared throttle, report scope / status privacy, convert / dismiss, duplicate step, board sort / pill / visibility

## Run K
- [ ] T007 ShowCase (header, banners: priors / responsible person / conflicts / deadline, deadlines panel, timeline in Arabic, documents + versions + PDF status, attachments, stage actions per contract)
- [ ] T008 DocumentDrafts (+ documents.last_edited_by; clears second review on change) + EditDocument (ar / en bodies, live preview via DocumentRenderer draft mode, save draft, submit for review → workflow transitions incl. CitationGate; back to edit)
- [ ] T009 ConfirmDocument per mockup (Amiri preview card, warning, provisional reference with dirty flag, SecondReview, DefenseLetterFlow::confirmAndIssue, send back); nested-binding 404s; PDF download states; correction with reason
- [ ] T010 AttachmentService (R8 B3) + attachment download route (R8 B2); ResponseService (recordHandOff, attachScan atomic, markNoResponse without transition); receipt hand-off form
- [ ] T011 Tests: every case-page action (auth, validation, state, case event) incl. service-level refusals per role; Livewire update-endpoint tests (reassignment, deactivated user, stale stage message); nested 404s; upload rules; second review stale after edit; provisional reference concurrency; correction flow
- [ ] T012 docs: WEIRDNESS (shared sign-in admits all roles to their landing while panels stay role-limited; 404 for invisible cases); orchestrator walkthrough + PR
