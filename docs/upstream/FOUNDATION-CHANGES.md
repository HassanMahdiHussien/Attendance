# Foundation changes (to apply to Displanary at merge-back)

Every kept foundation file edited since the spec-500 baseline is listed below. Removals are not listed because Displanary keeps that code. Apply only rows marked **Port**; **Do not apply** rows describe changes needed solely by Attendance.

## Employee classification fields (spec 506)

| File | Change | Why | Displanary |
|---|---|---|---|
| `database/migrations/2026_10_23_050000_add_employee_classification_attendance.php` | Adds nullable `employees.staff_category` and `employees.legal_entity`. | The HC list supplies collar and employing company. | **Port both fields** |
| `app/Models/Employee.php`, `database/factories/EmployeeFactory.php` | Accepts and validates collar and legal entity. | The shared employee record and fixtures carry both fields. | **Port both fields** |
| `app/Services/Employees/EmployeeHistory.php` | Versions both fields and permits rule-sourced pattern changes. | Employee identity and pattern decisions retain their effective dates. | **Port both fields**; apply the rule hook with Attendance |
| `app/Services/Employees/ColumnMapping.php`, `config/employee_import.php`, `app/Services/Employees/MappedEmployeeImport.php` | Maps HC columns K and Q and normalizes collar values. | Imported values reach the versioned employee record. | **Port both fields**; apply pattern assignment with Attendance |
| `app/Services/Employees/CsvReader.php`, `app/Services/Employees/EmployeeImport.php` | Accepts optional collar and legal entity in legacy CSV and runs the pattern rule after commit. | Both employee import paths retain the same classification. | **Port both fields**; apply pattern assignment with Attendance |
| `app/Filament/Admin/Resources/EmployeeResource.php`, `resources/views/livewire/employees/employee-page.blade.php` | Shows collar and legal entity in employee editing and identity. | HR can verify source classification and employing entity. | **Port both fields** |
| `lang/en/messages.php`, `lang/ar/messages.php` | Adds bilingual field, rule source, and review labels. | History, imports, and review issues need readable names. | **Port field labels**; apply rule labels with Attendance |
| `app/Services/Employees/PatternRule.php`, `app/Console/Commands/ApplyEmployeePatternRules.php`, `config/attendance.php` | Assigns an empty pattern from position or collar with a dated history entry and an idempotent one-time command. | Attendance needs a default classification without overriding HR. | **Apply with the attendance module** |


## Attendance overview integration (spec 505)

| File | Change | Why | Displanary |
|---|---|---|---|
| `app/Models/Employee.php` | Syncs a normalized English search name when employee identity changes. | Imported English names need the same lookup as Arabic names and codes. | **Port** |
| `database/migrations/2026_10_23_020000_add_english_employee_search.php` | Adds and backfills indexed `employees.search_name_en`. | English names need a normalized lookup in the shared employee record. | **Port** |
| `app/Services/Search/GlobalSearch.php` | Searches English names and shows current branch and department. | Similar employee names need site and department context. | **Port** |
| `app/Livewire/Dashboard/HrDashboard.php` | Replaced the placeholder with scoped attendance filters, bounded employee and manager search, totals, freshness and export requests. | HR sees current results and requests reports with the same filters without loading every employee into a select. | **Do not apply** |
| `app/Filament/Platform/Widgets/CompaniesOverview.php` | Links each company to its explicitly scoped attendance dashboard. | Platform staff choose a tenant before viewing attendance. | **Do not apply** |
| `resources/views/filament/platform/widgets/companies-overview.blade.php` | Displays the company attendance link. | Gives platform administrators the explicit tenant path. | **Do not apply** |
| `resources/views/livewire/dashboard/hr-dashboard.blade.php` | Displays attendance metrics, searchable employee and manager filters, coverage and export controls in place of the placeholder. | The HR dashboard reads the attendance projection and passes filters to reports. | **Do not apply** |
| `resources/views/livewire/home.blade.php` | Embeds the attendance dashboard. | HR's existing landing shows current results. | **Do not apply** |
| `resources/views/livewire/employees/employee-page.blade.php` | Includes the attendance section in place of the placeholder. | Retains the shared employee identity page and adds attendance side by side. | **Do not apply** |
| `resources/views/livewire/global-search.blade.php` | Isolates employee codes as left-to-right text beside site and department. | Search results remain readable in Arabic. | **Port** |
| `routes/web.php` | Adds scoped dashboard, drill-down and audited export download routes. | HR and platform users need distinct authorised paths and private files. | **Do not apply** |
| `lang/en/messages.php`, `lang/ar/messages.php` | Adds bilingual attendance overview and export labels. | New screens and reports display in both interface languages. | **Do not apply** |
| `app/Providers/AppServiceProvider.php` | Registers the attendance export policy. | Private generated files need explicit authorization. | **Do not apply** |
| `routes/console.php` | Schedules private attendance export file cleanup. | Export files expire after 30 days while audit rows remain. | **Do not apply** |


## Guidance and runtime

| File | Change | Why | Displanary |
|---|---|---|---|
| `AGENTS.md` | Replaced Docker/Sail local setup with native WSL instructions. | Both repositories use native Ubuntu development; retain Displanary paths and database names. | **Port WSL text only** |
| `CLAUDE.md` | Replaced the discipline build reference with attendance rules and a link to the archived original. | This is the attendance product's authority. | **Do not apply** |
| `README.md` | Replaced the product introduction and Docker/Sail local setup with attendance wording and native WSL instructions. | Only the environment correction is shared; Displanary keeps its own product description and database names. | **Port WSL text only** |
| `config/backup.php` | Removed legal-library files from backup include paths. | Those source documents moved upstream. | **Do not apply** |
| `config/displanery.php` | Removed discipline permissions, export and workflow settings. | Only shared runtime configuration remains. | **Do not apply** |
| `config/livewire.php` | Removed report-evidence rationale from upload configuration. | Report attachments were removed. | **Do not apply** |
| `e2e.config.ts` | Replaced discipline QA instructions and accounts with attendance and two roles. | Browser setup must match the retained seeded users. | **Do not apply** |
| `phpunit.xml` | Changed the test database to testing_attendance. | Displanary and Attendance suites must not share a MySQL test database. | **Do not apply** |

## Application code

| File | Change | Why | Displanary |
|---|---|---|---|
| `app/Console/Commands/BackupRestoreDrill.php` | Removed law-file checks and retained append-only audit and AI-log checks. | The cleanup has no legal library. | **Do not apply** |
| `app/Console/Commands/DevSessionCookie.php` | Stopped collecting law names for development session checks. | The legal library was removed. | **Do not apply** |
| `app/Console/Commands/HelpScreenshots.php` | Selected the seeded HR user for retained screenshots. | The company-admin demo role was removed. | **Do not apply** |
| `app/Console/Commands/SearchBenchmark.php` | Benchmarked employee search with an HR user. | Global search now returns employees only. | **Do not apply** |
| `app/Enums/SequenceKey.php` | Removed case and document sequence cases. | Only employee numbering remains. | **Do not apply** |
| `app/Enums/UserRole.php` | Reduced roles to PlatformAdmin and HrStaff. | The attendance workspace has two roles. | **Do not apply** |
| `app/Filament/Admin/Pages/CompanySettings.php` | Removed discipline settings and allowed HR tenant administrators. | HR owns company setup without company-admin role. | **Do not apply** |
| `app/Filament/Admin/Resources/EmployeeResource.php` | Removed discipline fields and actions from employee administration. | Employee administration stays in the shared foundation. | **Do not apply** |
| `app/Filament/Admin/Resources/UserResource.php` | Restricted company user options and queries to tenant HR users. | HR manages users in its own company. | **Do not apply** |
| `app/Filament/Admin/Resources/UserResource/Pages/CreateUser.php` | Updated role validation and form errors for HR-only company users. | Removed company-admin and discipline role choices. | **Do not apply** |
| `app/Filament/Admin/Resources/UserResource/Pages/EditUser.php` | Updated role validation and form errors for HR-only company users. | Removed company-admin and discipline role choices. | **Do not apply** |
| `app/Filament/Admin/Widgets/AdminShortcuts.php` | Removed discipline shortcuts and setup items; retained HR company setup. | Only branch, language, user and employee setup remains. | **Do not apply** |
| `app/Filament/Admin/Widgets/SetupChecklist.php` | Removed discipline shortcuts and setup items; retained HR company setup. | Only branch, language, user and employee setup remains. | **Do not apply** |
| `app/Filament/Platform/Resources/CompanyResource.php` | Removed discipline company fields and collected first HR user details. | Onboarding creates the tenant's HR administrator. | **Do not apply** |
| `app/Filament/Platform/Resources/CompanyResource/Pages/CreateCompany.php` | Passed first HR account details into onboarding. | New tenants start with an HR user. | **Do not apply** |
| `app/Filament/Platform/Resources/CompanyResource/Pages/EditCompany.php` | Dropped removed company fields from edit payload. | The trimmed company schema has no discipline settings. | **Do not apply** |
| `app/Filament/Platform/Resources/PlatformUserResource.php` | Limited platform user forms to PlatformAdmin. | Only platform administrators have cross-company accounts. | **Do not apply** |
| `app/Filament/Platform/Resources/PlatformUserResource/Pages/CreatePlatformUser.php` | Limited platform user forms to PlatformAdmin. | Only platform administrators have cross-company accounts. | **Do not apply** |
| `app/Filament/Platform/Resources/PlatformUserResource/Pages/EditPlatformUser.php` | Limited platform user forms to PlatformAdmin. | Only platform administrators have cross-company accounts. | **Do not apply** |
| `app/Filament/Platform/Widgets/CompaniesOverview.php` | Removed discipline metrics from the company overview. | Platform overview shows retained tenant status. | **Do not apply** |
| `app/Http/Middleware/EnforceIdleTimeout.php` | Removed former role branches from session timeout selection. | Only PlatformAdmin and HrStaff remain. | **Do not apply** |
| `app/Livewire/Dashboard/HrDashboard.php` | Replaced discipline data rendering with attendance placeholders. | Discipline screens were removed before attendance screens exist. | **Do not apply** |
| `app/Livewire/Home.php` | Replaced discipline data rendering with attendance placeholders. | Discipline screens were removed before attendance screens exist. | **Do not apply** |
| `app/Models/AiGenerationLog.php` | Removed case and document relationships and fields. | The append-only log remains for attendance AI. | **Do not apply** |
| `app/Models/Branch.php` | Removed case and violation-report relationships. | Branches remain for employee and attendance data. | **Do not apply** |
| `app/Models/Company.php` | Removed discipline fields, partner and document relationships and hooks. | Company remains a tenant and settings record. | **Do not apply** |
| `app/Models/Employee.php` | Removed case and report relationships and the single-general-manager guard. | Employee identity and search remain. | **Do not apply** |
| `app/Models/User.php` | Removed partner fields and discipline relations; mapped HR panel capabilities. | Only two account roles remain. | **Do not apply** |
| `app/Policies/BranchPolicy.php` | Assigned company-panel operations to tenant HR users. | HR inherits company-admin duties inside its company. | **Do not apply** |
| `app/Policies/CompanyPolicy.php` | Allowed tenant HR users to view and update their company. | Company settings remain tenant-scoped. | **Do not apply** |
| `app/Policies/EmployeePolicy.php` | Assigned company-panel operations to tenant HR users. | HR inherits company-admin duties inside its company. | **Do not apply** |
| `app/Policies/EmployeeProfilePolicy.php` | Limited profile access to HR and explicit platform paths. | Employee detail remains isolated by company. | **Do not apply** |
| `app/Policies/LocationPolicy.php` | Assigned company-panel operations to tenant HR users. | HR inherits company-admin duties inside its company. | **Do not apply** |
| `app/Policies/UserPolicy.php` | Assigned company-panel operations to tenant HR users. | HR inherits company-admin duties inside its company. | **Do not apply** |
| `app/Providers/AppServiceProvider.php` | Removed discipline morph, gates and rate limiters; retained foundation providers. | The deleted module no longer registers application hooks. | **Do not apply** |
| `app/Services/Companies/CompanySetup.php` | Reduced checklist to branches and interface languages. | Discipline prerequisites no longer apply. | **Do not apply** |
| `app/Services/CompanyOnboardingService.php` | Created the first HR user instead of a company admin. | Tenant administration moved to HrStaff. | **Do not apply** |
| `app/Services/Employees/EmployeeImport.php` | Removed discipline case checks from employee import. | The shared CSV importer remains. | **Do not apply** |
| `app/Services/Employees/EmployeePicker.php` | Restricted employee picker actions to HR users. | Removed reporting-role access. | **Do not apply** |
| `app/Services/Employees/EmployeeProfile.php` | Removed disciplinary history from employee profile output. | Profile keeps identity pending attendance detail. | **Do not apply** |
| `app/Services/Search/GlobalSearch.php` | Reduced indexed results to scoped employees. | Removed case, report and law search. | **Do not apply** |
| `app/Services/UserAccountService.php` | Removed export side effects and protected the last active HR user. | HR replaces company admin as tenant administrator. | **Do not apply** |
| `app/Support/Access/AdminNav.php` | Removed discipline and partner resource links. | Admin menus show retained foundation resources. | **Do not apply** |
| `app/Support/Access/DashboardNav.php` | Reduced landing and navigation to two roles and retained pages. | No discipline entry point remains. | **Do not apply** |
| `app/Support/Access/Landing.php` | Reduced landing and navigation to two roles and retained pages. | No discipline entry point remains. | **Do not apply** |
| `app/Support/Access/Nav.php` | Reduced landing and navigation to two roles and retained pages. | No discipline entry point remains. | **Do not apply** |
| `app/Support/Access/PlatformNav.php` | Removed discipline and partner resource links. | Admin menus show retained foundation resources. | **Do not apply** |
| `app/Support/Format/Tone.php` | Removed case, document, deadline and report tone helpers. | Only retained UI status formatting remains. | **Do not apply** |
| `app/Support/Format/Ui.php` | Removed case labels and kept date formatting with translated month names. | Shared date display no longer depends on document code. | **Do not apply** |
| `app/Support/Help/HelpLibrary.php` | Limited help sections and role filters to retained guides. | Discipline articles were archived or removed. | **Do not apply** |

## Database

| File | Change | Why | Displanary |
|---|---|---|---|
| `database/factories/BranchUserFactory.php` | Created tenant HR users for branch membership. | Removed company-admin and other role factory states. | **Do not apply** |
| `database/factories/CompanySequenceFactory.php` | Defaulted sequence fixtures to employee codes. | Case and document sequences were removed. | **Do not apply** |
| `database/migrations/2026_10_04_200000_create_platform_tables.php` | Removed partner and discipline company/user columns and changed default role to HR. | Base schema must match retained tenants and roles. | **Do not apply** |
| `database/migrations/2026_10_04_201000_create_domain_tables.php` | Removed discipline tables and foreign keys while keeping employee, branch and location schema. | Fresh installs create only foundation domain tables. | **Do not apply** |
| `database/migrations/2026_10_04_202000_create_audit_tables.php` | Removed discipline audit tables and kept audit and AI generation logs. | The remaining logs stay append-only. | **Do not apply** |
| `database/migrations/2026_10_04_203000_create_immutability_triggers.php` | Removed discipline triggers and retained audit and AI-log triggers. | Fresh installs protect kept append-only tables. | **Do not apply** |
| `database/migrations/2026_10_05_010000_add_case_workflow_data.php` | Removed discipline schema operations from retained migration files. | Original migration order still supports retained foundation columns. | **Do not apply** |
| `database/migrations/2026_10_05_020000_add_document_templates.php` | Removed discipline schema operations from retained migration files. | Original migration order still supports retained foundation columns. | **Do not apply** |
| `database/migrations/2026_10_05_030000_add_english_rendering_fields.php` | Removed discipline schema operations from retained migration files. | Original migration order still supports retained foundation columns. | **Do not apply** |
| `database/migrations/2026_10_06_000000_add_committee_decision_data.php` | Removed discipline schema operations from retained migration files. | Original migration order still supports retained foundation columns. | **Do not apply** |
| `database/migrations/2026_10_08_000001_add_ai_drafting_data.php` | Removed discipline schema operations from retained migration files. | Original migration order still supports retained foundation columns. | **Do not apply** |
| `database/migrations/2026_10_16_000000_add_locale_to_users_and_report_exports.php` | Kept user locale and removed report-export alterations. | Locale remains; report exports do not. | **Do not apply** |
| `database/migrations/2026_10_19_000000_add_search_columns_and_preferences.php` | Kept employee search and user preferences; removed discipline indexes. | Search and preferences remain foundation features. | **Do not apply** |
| `database/seeders/DatabaseSeeder.php` | Removed legal and document seed calls. | Fresh seed contains the pilot foundation only. | **Do not apply** |
| `database/seeders/LocalUsersSeeder.php` | Seeded one platform administrator and one HR user. | The two-role local login set matches the product. | **Do not apply** |
| `database/seeders/PilotCompanySeeder.php` | Seeded anonymised pilot company, branches, locations and employees. | Pilot data no longer contains discipline records. | **Do not apply** |

## Languages and help

| File | Change | Why | Displanary |
|---|---|---|---|
| `lang/ar.json` | Removed discipline UI labels and added retained attendance and HR wording. | Arabic catalogue must match surviving screens. | **Do not apply** |
| `lang/ar/messages.php` | Removed discipline messages and added attendance and HR strings. | Structured catalogues must agree for both languages. | **Do not apply** |
| `lang/en/messages.php` | Removed discipline messages and added attendance and HR strings. | Structured catalogues must agree for both languages. | **Do not apply** |
| `resources/help/README.md` | Updated help scope to retained attendance foundation guides. | Discipline help content was archived. | **Do not apply** |
| `resources/help/ar/getting-started/finding-your-way.md` | Updated navigation guidance for surviving pages. | Help must match the reduced workspace. | **Do not apply** |
| `resources/help/ar/getting-started/sign-in-and-language.md` | Updated sign-in and language guidance for two roles. | Help must match shared login and language behavior. | **Do not apply** |
| `resources/help/ar/getting-started/welcome.md` | Reframed introduction for attendance and leave reconciliation. | The help landing no longer describes discipline. | **Do not apply** |
| `resources/help/ar/hr/employees-and-history.md` | Trimmed to employee list, profile and import. | Discipline history is gone while employee help remains. | **Do not apply** |
| `resources/help/en/getting-started/finding-your-way.md` | Updated navigation guidance for surviving pages. | Help must match the reduced workspace. | **Do not apply** |
| `resources/help/en/getting-started/sign-in-and-language.md` | Updated sign-in and language guidance for two roles. | Help must match shared login and language behavior. | **Do not apply** |
| `resources/help/en/getting-started/welcome.md` | Reframed introduction for attendance and leave reconciliation. | The help landing no longer describes discipline. | **Do not apply** |
| `resources/help/en/hr/employees-and-history.md` | Trimmed to employee list, profile and import. | Discipline history is gone while employee help remains. | **Do not apply** |
| `resources/help/screenshots.php` | Limited screenshot routes and account to retained HR pages. | Deleted screens have no screenshot targets. | **Do not apply** |

## Views and routes

| File | Change | Why | Displanary |
|---|---|---|---|
| `resources/views/errors/403.blade.php` | Directed access requests to HR rather than company admin. | HR now administers company access. | **Do not apply** |
| `resources/views/filament/admin/pages/company-settings.blade.php` | Removed discipline settings from the company page. | Only retained settings remain. | **Do not apply** |
| `resources/views/filament/platform/widgets/companies-overview.blade.php` | Removed discipline metrics and links from platform overview. | Overview reflects retained tenant data. | **Do not apply** |
| `resources/views/layouts/workspace.blade.php` | Removed the deleted file-viewer component. | Attachment viewing belonged to the removed module. | **Do not apply** |
| `resources/views/livewire/dashboard/hr-dashboard.blade.php` | Replaced discipline widgets with attendance placeholders. | The old workflows have no screen. | **Do not apply** |
| `resources/views/livewire/employees/employee-page.blade.php` | Removed case history and left identity plus an attendance placeholder. | Employee page remains a foundation route. | **Do not apply** |
| `resources/views/livewire/global-search.blade.php` | Reduced results and labels to employees. | Search no longer indexes discipline records. | **Do not apply** |
| `resources/views/livewire/home.blade.php` | Replaced discipline widgets with attendance placeholders. | The old workflows have no screen. | **Do not apply** |
| `resources/views/welcome.blade.php` | Changed the landing sentence to attendance. | Public wording must identify this product. | **Do not apply** |
| `routes/console.php` | Removed discipline jobs from the schedule. | Deleted jobs must not be invoked. | **Do not apply** |
| `routes/web.php` | Removed discipline endpoints while retaining auth, help, employee and dashboard routes. | The reduced app must have no old entry points. | **Do not apply** |

## Shared test fixes to port

These test fixes are called out separately from the foundation-file inventory above.

| File | Change | Why | Displanary |
|---|---|---|---|
| `tests/Feature/Access/ScopeBypassGuardTest.php` | Collect bypassing files and assert the list is empty. | The guard otherwise becomes risky when no file bypasses the scope. | **Port** |
| `tests/Feature/Tenancy/RouteSweepTest.php` | Map the local-disk `storage/{path}` route to a Company B marker and restore sweep rationale. | The route must be checked for cross-tenant reads. | **Port if Displanary lacks the mapping** |
| 2026-10-22 | 500 | Brand views, Filament panel providers, mail views, `TestMail`, `BackupRestoreDrill`, `config/backup.php` mail-from default, `.env.example`, `public/favicon.svg`, `lang/ar.json` | Visible product name "Displanery" → "Attendance" (Arabic "نظام الحضور"), logo mark "D" → "A". Internal names (backup archive, SFTP root, mail theme, `config/displanery.php`) unchanged | Product owner wants the standalone app to read as Attendance. **Do not apply**; revisit the brand at merge-back. |
| 2026-10-22 | 501 | `employees` (new columns, `position`/`department` nullable), `employee_versions`, `employee_import_batches`, `employee_import_mappings`, `Employee` model, `EmployeeHistory`, `ExitReview`, `EmployeeImport`, `ImportPreview`, `SpreadsheetReader`, `ColumnMapping`, `MappedEmployeeImport`, `config/employee_import.php`, employee Filament resource and import page, employee page, `employees:apply-effective-changes` | Dated employee history (sparse versions), xlsx/csv import with saved positional mapping, full/partial modes, exit review instead of direct deactivation | **Port**: Displanary needs dated employee history and the safer import too. Note: the Deactivate action is replaced by exit review. |
| 2026-10-22 | 502 | `app/Providers/AppServiceProvider.php`, `app/Support/Access/AdminNav.php`, `resources/views/layouts/partials/workspace-navigation.blade.php`, `routes/web.php`, `lang/{en,ar}/messages.php` | Register attendance policies/listener, "Attendance" nav group and the original-file download route | Attendance wiring. **Apply with the attendance module** at merge-back. |
| 2026-10-22 | 502 | `config/livewire.php` | Temporary upload limit 50 MB → 100 MB | Monthly punch exports are large. **Port** if Displanary keeps large uploads. |
| 2026-10-22 | 502 | `tests/Feature/Tenancy/CompositeForeignKeyTest.php` | Copied rows get fresh values for their own unique columns before the cross-company FK check | Otherwise a unique violation (1062) hides the FK check (1452). **Port**. |
| 2026-10-22 | 503 | `app/Services/CompanyOnboardingService.php` | New companies get the default office attendance policy | **Apply with the attendance module**. |
| 2026-10-22 | 503 | `app/Services/Employees/ExitReview.php` | Confirming or dismissing an exit dispatches `EmployeeExitChanged` | Attendance reassessment after exits. **Apply with the attendance module**. |
| 2026-10-22 | 503 | `app/Providers/AppServiceProvider.php`, `routes/console.php`, `app/Support/Access/AdminNav.php`, `lang/{en,ar}/messages.php` | Assessment listeners, hourly `attendance:assess-pending`, office attendance pages in the nav | **Apply with the attendance module**. |
| 2026-10-22 | 504 | `app/Services/Attendance/DayAssessor.php`, `app/Services/Attendance/DayDetail` page + view, `app/Providers/AppServiceProvider.php`, `app/Support/Access/AdminNav.php`, workspace navigation view, `routes/web.php`, `lang/{en,ar}/messages.php` | DayAssessor emits `DaysAssessed`; leave listener, `manageLeave` gate, Leave nav group, leave original-file download route; day detail shows leave reconciliation | **Apply with the attendance module**. |
| 2026-10-23 | 505 | `lang/en/messages.php`, `lang/ar/messages.php` | Added matching validation messages for attendance report filters and periods. | **Apply with the attendance module**. |
| 2026-10-24 | 506 | `app/Filament/Admin/Resources/EmployeeResource.php`, `app/Services/Employees/EmployeeProfile.php`, `app/Services/Employees/PatternRule.php`, `resources/views/livewire/employees/employee-page.blade.php`, `lang/{en,ar}/messages.php` | Show the source of a rule-set work pattern and link its open review issue; label shift review and policy screens. | **Apply with the attendance module**. |
| 2026-10-24 | 507 | `app/Support/Access/AdminNav.php`, `routes/web.php`, `lang/{en,ar}/messages.php`, `resources/views/livewire/attendance/employee-attendance.blade.php`, `resources/views/livewire/dashboard/hr-dashboard.blade.php` | Add the holiday navigation entry, serve audited private holiday originals and source parts, label holiday review, and show linked holiday names and work hours. | **Apply with the attendance module**. |
| 2026-10-24 | 508 | `app/Services/Employees/EmployeeProfile.php`, `resources/views/livewire/employees/employee-page.blade.php`, `resources/views/filament/admin/resources/employee-resource/pages/import-employees.blade.php`, `app/Providers/AppServiceProvider.php`, `routes/web.php`, `lang/{en,ar}/messages.php` | Show dated annual leave balance history, link the separate upload screen from HC import, and register the private audited download route and policy. | **Apply with the leave balance module**. |
| 2026-10-24 | fix | `app/Services/Employees/MappedEmployeeImport.php`, `app/Services/Employees/EmployeeImport.php` | A new employee's first imported values (legal entity, department, manager, …) count from the hire date instead of the import's effective date, so back-dated history and holidays see them. Later imports keep their effective date. | **Apply to Displanary** (product owner, 2026-10-24). |
| 2026-10-24 | 509 | `app/Livewire/Dashboard/HrDashboard.php`, `resources/views/livewire/dashboard/hr-dashboard.blade.php`, `lang/{en,ar}/messages.php` | Add overtime and compensatory-day tiles with drill-down, and their labels. | **Apply with the attendance module**. |
