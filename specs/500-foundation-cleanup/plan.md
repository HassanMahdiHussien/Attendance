# Implementation Plan: Foundation cleanup — remove the discipline module

**Branch**: `500-foundation-cleanup` | **Date**: 2026-10-21 | **Spec**: [spec.md](spec.md)

## Summary

Delete everything in this copy of Displanary (`2d85ddb`) that only serves discipline, keep the shared foundation working and tested, and leave placeholders where attendance screens will go. Removal happens leaf-first in small steps (AI and legal library → documents → cases and reports → schema → placeholders → docs), with the full suite and Pint green after each step. This PR is the one commit Displanary never receives at merge-back (ADR 0013).

## Technical Context

- **Language/Version**: PHP 8.5 (composer `^8.4`), Laravel 13, Livewire 4, Filament 5, Tailwind v4.
- **Storage**: MySQL 8.4. Dev DB `attendance`, test DB `testing_attendance` (separate from Displanary's `laravel` / `testing` on the same WSL MySQL).
- **Testing**: Pest against MySQL; Pint.
- **Target platform / dev env**: native WSL Ubuntu 26.04, project at `~/attendance`. **No Docker** (product owner, 2026-10-21; Displanary moved off Docker on 2026-10-16). AGENTS.md, README and the constitution still describe Sail and are corrected in this spec.
- **Gates**: `php artisan test --compact` and `./vendor/bin/pint --test`, run from `~/attendance`.
- **Scale/Scope**: ~470 app files, 42 migrations, 30+ test folders; most of the discipline code goes.

## Constitution Check

The current constitution (v1.1.0) is Displanary's. This spec amends it (step 7), so the check is against the principles that survive:

| Principle | How this spec complies |
|---|---|
| I Tenant isolation | Tenancy code and isolation tests are kept unchanged; tests for removed models go with them; `CompositeForeignKeyTest` model map is trimmed, not weakened. |
| III Immutable, audited records | `audit_logs` append-only triggers and `AuditLogger` kept; triggers for removed tables removed with their tables. |
| IV Tests | Full suite + Pint after every step; no kept test is deleted unless it only exercises removed code. |
| V Simple Laravel | Remove, don't rewrite: kept files change only where removed code forces it. |
| VI Deliberate decisions | `WEIRDNESS.md` lines about discipline move to `docs/upstream/displanary/`; foundation lines stay. |

No violations to justify.

## Keep / remove inventory

**Keep (foundation)**: `Support/Tenancy/{CompanyScope,TenantContext}`, `Models/Concerns` tenancy traits, `Company`, `Branch`, `BranchUser`, `Location`, `CompanySequence` + `SequenceService`, `CompanyOnboardingService`, `CompanyStatusService`, `Companies/CompanySetup` (discipline checks removed), `User`, `UserAccountService`, `UserPreferences`, `Auth/LoginAttempts`, `Employee`, `Employees/{CsvReader,EmployeeImport,ImportPreview,EmployeePicker,EmployeeProfile}`, `EmployeeResource` + import page, `Livewire/Employees/*`, `GlobalSearch` (employees only), `Search/*`, `AuditLog` + `AuditLogger` + `Audited`/`AppendOnly`, `Attachment` + `AttachmentService` + download controller, `ReportExport` + `GenerateReportExport` + download controller + `Statistics/{ExcelReport,PdfReport}` (generic writers), `Help/*` shell, `Heartbeat*`, `CheckFailures`, `BackupRestoreDrill`, `TestMail`, `CreatePlatformAdmin`, `DevSessionCookie`, `HelpScreenshots`, `SearchBenchmark`, middleware, `LocaleController`, `Filament` Admin/Platform panels with Branch/Location/User/Employee/Company/PlatformUser resources, `AdminShortcuts`, `SetupChecklist` (trimmed), `CompaniesOverview`, `UI` components, design tokens, `deploy/`.

**Remove (discipline only)**: `app/Ai`, `app/Documents`, `app/LegalLibrary`, `app/Workflow`, `Services/{Cases,Documents,EmailIntake,Intake,Reports,Dashboards,Home,Statistics}`, `CaseEventLogger`, `CommitteeMandateService`, `PartnershipService`, all jobs except `HeartbeatJob` and `GenerateReportExport`, `Livewire/{Cases,Reports,Dashboard,Statistics}` and their views, discipline Filament pages/resources (`DocumentTemplates`, `InternalRegulation`, `CommitteeMandateResource`, `ViolationTypeResource`, `MismatchQueue`, `HrPartnerResource`, `LawFirmPartnerResource`, `LawResource`, `ReviewsLegalMismatch`), `DocumentOutputController`, `LegalPageImageController`, `ImportLegalLibrary`/`ExportLegalLibrary` commands, all `Notifications/*`, discipline enums, policies, `Support/Access/{CaseReturnLink,CaseVisibility,CommitteeNav,EmailDraftVisibility,ViolationReportVisibility}`, `Support/Format/{CaseDueEvents,CaseTimeline,LegalLibraryLabels,OutcomeLabel,StageIndicator,Violation}`, `Support/Tenancy/LegalLibraryScope`, `Support/Reports/UnknownAnswer`, and the models for every removed table.

**Tables removed** (by editing the original migrations; no production DB exists): cases, case_events, case_links, case_link_annotations, case_witnesses, case_alerts, case_conflict_clearances, case_file_intakes, appeal_trackings, citations, committee_mandates, committee_pool_members, committee_session_attendances, documents, document_contents, document_approvals, document_templates, company_letterheads, signatures, signature_presence_suggestions, response_transcriptions, laws, law_jurisdictions, legal_units, legal_unit_approvals, legal_unit_topics, legal_import_runs, legal_import_pages, ai_draft_notes, regulation_comparisons, regulation_comparison_pairs, violation_reports, violation_report_employees, report_witnesses, report_assistant_sessions, violation_types, incidents, incident_sessions, intake_questions, intake_checks, email_drafts, email_intake_messages, email_intake_rejections, attachment_texts, company_hr_partner, company_law_firm_partner (+ `hr_partners`/`law_firm_partners` if separate), and `notifications` if only discipline uses it. Discipline columns on kept tables (`companies`, `users`, `employees`) are removed the same way; each is listed in the step-5 commit.

**Kept but trimmed**: `ai_generation_logs` (D2), `notifications` (D2b), and the kept files listed in step 3. Attachments and report exports are removed (D0), so the "Keep" list above no longer includes `Attachment`, `AttachmentService`, `ReportExport`, `GenerateReportExport`, `app/Statistics` or their download controllers.

## Decisions (product owner, 2026-10-21)

- **D0 Attachments and report exports are removed** (overrides spec FR-001). Both are built only around discipline owners and discipline statistics. Displanary keeps them; attendance stores its own uploaded files with its import batches (501/502) and builds its Excel exports in 505. Removed: `attachments`, `attachment_texts`, `report_exports`, `Attachment`, `AttachmentService`, `AttachmentKind`, `Support/Files/FileItem`, both download controllers, `GenerateReportExport`, `app/Statistics`, and the attachment-delete trigger.
- **D1 Roles: only `platform_admin` and `hr_staff`.** "Platform admin and HR can join this system." `company_admin`, `dept_manager`, `reporter`, `committee_member`, `external_legal_reviewer`, `hr_partner_staff` and every discipline add-on permission are removed. HR staff take over what company admins did in the company (Filament admin) panel: users, branches, locations, company settings, employees and employee import. The PRD's "authorized management" role is not built now. Seeders keep one platform admin and one HR user.
- **D2 `ai_generation_logs`.** Keep the table, model and append-only trigger; drop its `case_id` / `document_id` columns and foreign keys; remove `app/Ai`. Later attendance AI writes to it.
- **D2b `notifications`.** Keep Laravel's database `notifications` table (User stays `Notifiable`; attendance notifications come later). Its `Schema::create` stays in the trimmed migration that creates it today.
- **D3 Test database.** `phpunit.xml` uses `testing_attendance` so this suite and Displanary's never share a database on the same MySQL. Logged in `FOUNDATION-CHANGES.md` as "not to apply" (Displanary keeps `testing`).
- **D4 Dev docs.** AGENTS.md, README "Running locally" and the constitution's technology section describe native WSL instead of Sail/Docker. Displanary has the same stale text; logged in `FOUNDATION-CHANGES.md` as worth porting.
- **D5 Constitution 2.0.0** (approved by the product owner 2026-10-21):
  - I Tenant isolation — unchanged, minus mentions of External Legal Reviewer / HR Partner / internal regulations.
  - II **AI proposes, deterministic rules calculate, humans confirm** — calculations, balances and allocations are service code with versioned policies; AI only proposes (description meaning, holiday extraction); nothing AI proposes takes effect without HR confirmation; employee text is data, never an instruction; every AI call logged in `ai_generation_logs`; only needed data sent.
  - III **Source records and audit are immutable** — original files, raw transactions and imported request versions are never edited or deleted; corrections and reversals are new rows with actor and reason; `audit_logs` / `ai_generation_logs` append-only.
  - IV Tests required for: tenant isolation, re-upload idempotency, punch cleanup, cross-midnight assignment, lateness/early-departure/short-hours math without double counting, leave coverage and allocation (incl. surplus credit and reversal), rest-day entitlements, the 31 PRD §25 acceptance cases as they are built.
  - V, VI unchanged. Technology section: drop document/legal rules, native WSL dev, English default UI.

## Implementation steps (each = one Codex run, then my review + full gates + one commit)

Order is **top-down**: remove callers before what they call, so every step leaves a working app and a green suite (Codex plan review, finding 7). Each step changes the kept callers and their tests in the same step.

0. **Dev env** (done, `139ef5d`): `phpunit.xml` test DB, AGENTS.md / README / CLAUDE.md describe native WSL.
1. **Docs move**: `docs/{PRD,PRD-EN,ERD,LAUNCH-CHECKLIST,document-templates}.md`, `docs/laws/`, `docs/Admin_Order_*.pdf`, discipline ADRs (0002–0005, 0011) and `specs/001`–`063` → `docs/upstream/displanary/`. Fix links in kept docs. No code.
2. **Screens, routes and entry points**: remove every discipline Livewire component, Filament page/resource/widget, controller, route, scheduled command, artisan command, nav entry, help article and view. In the same step: home and HR dashboard become the "Attendance dashboard — coming in spec 505" placeholder; the employee page keeps identity fields + an empty Attendance section; `GlobalSearch` searches employees only; `Landing`, `Nav`, `AdminNav`, `PlatformNav`, `CompanySettings`, onboarding and `SetupChecklist` drop discipline items; `HelpScreenshots` / `SearchBenchmark` / `BehaviourInventoryTest` + `ui-inventory.json` / help screenshots updated. Services and models stay (now unused), so service tests still pass.
3. **Domain code**: remove the now-unused services, workflow, AI, documents, legal library, email intake, statistics, exports, attachments (D0), jobs, notifications, policies, enums, support classes, exceptions and their tests. Trim kept files that reference them: `Employee`, `Company` (letterhead hook), `User`, `UserAccountService` (export on deactivation), `EmployeeImport` (open-case warning), `EmployeeProfile`, `EmployeeProfilePolicy`, `ReportEmployeeQuickAdd`, `Support/Access/EmployeeActions`, `AppServiceProvider` (morph map, gates, rate limiters), `config/displanery.php`.
4. **Schema, roles and seed data**: edit original migrations column by column (see research.md map), trigger migrations (keep `audit_logs` / `ai_generation_logs` append-only triggers; drop triggers of removed tables, including the attachment-delete trigger in `allow_removing_unsent_draft_files`), `down()` paths, models' casts/fillable, factories, seeders, roles per D1, `CompositeForeignKeyTest` map, migration/audit/setup tests. Check: `migrate:fresh` and `migrate:rollback` round-trip on the test DB, trigger tests pass.
5. **Docs and governance**: constitution 2.0.0 (D5), README/PRODUCT/DESIGN wording, `WEIRDNESS.md` split (discipline lines → `docs/upstream/displanary/`), `lang/*` keys left unused after steps 2–4 removed, `FOUNDATION-CHANGES.md` complete.
6. **Verification** (me): `migrate:fresh --seed` on the dev DB, grep for every removed class/table/route/translation name, browser smoke test (platform admin creates company, branch and an HR user; HR logs in, switches language, imports CSV, opens employee page, searches).

A step may be re-split if a run touches too much; any kept file that depends deeply on removed code stops the run and comes back as a question. The column-by-column schema map and the kept-file edit list live in [research.md](research.md).

## Risks

- Hidden coupling (e.g. `Employee` relations to cases, `GlobalSearch` over cases, `CompanySetup` gate) — Codex is told to stop and report, not refactor.
- Editing original migrations can break trigger migrations that reference dropped tables — step 5 runs `migrate:fresh` on the test DB before committing.
- Codex has been seen mangling `\r\n` escapes and Blade `@php` blocks in Displanary — every diff is checked for both.
