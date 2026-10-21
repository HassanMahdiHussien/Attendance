# Attendance & Leave Module — Roadmap and Decisions

The full requirements are in `docs/attendance/PRD-EN.md`. This file records what the product owner decided on top of the PRD, what the sample files showed, what is still open, and the order of specs. Where this file and the PRD disagree, **this file wins**.

## 1. Where the module lives (ADR 0013)

- Attendance is built in its **own repo** (`HassanMahdiHussien/Attendance`), copied from Displanary's `main` at `2d85ddb` with full history, so Displanary is never at risk while attendance is built.
- Spec 500 removes the discipline module and keeps the shared foundation: tenancy, users, roles, audit, `employees`, branches, import pattern, design, i18n, deploy.
- The end goal is one employee page and one HR dashboard showing **discipline and attendance together**. That happens by **merging attendance back into Displanary** as a module once stable (without the spec-500 cleanup commit). Until then: attendance code stays in its own namespaces, every foundation change is logged in `docs/upstream/FOUNDATION-CHANGES.md`, and specs are numbered from 500.
- Every attendance table keys on `employees.id`; external reports are matched on `employees.employee_code` (stored as text, exact match, leading zeros kept).
- Sites in the reports (Hilla, Karbala, Erbil, Baghdad) are **branches**. Erbil is a `kurdistan_region` branch.
- Leave is **imported** from the company's own leave system. This module never creates or approves leave. A future native leave module (`docs/upstream/displanary/PLATFORM-PRD.md` §10) becomes another leave *source* — so `leave_requests` carries a `source` column from day one.
- The product will be sold to other companies later, so everything is multi-tenant (Constitution I applies in full).
- English is the default interface language; Arabic is the second (ADR 0014).

## 2. Product-owner decisions (2026-10-21)

| Topic | Decision |
|---|---|
| Leave duration | 1 day = 8 hours, 0.5 day = 4 hours. The report gives days only; the half-day position is never assumed. |
| Status `Leave Entry` | A form the employee saved but never submitted. Covers nothing; nobody is reminded. If a later report shows the same form number submitted, update that request. |
| Statuses `Manager Approval`, `HR Approval`, `Department Manager Approval` | Pending. Remind the current approver (`Form Approver` column), never the employee. |
| Rejection | A request is rejected whenever `Leave Form Rejected` has a date, whatever the status column says. |
| Cancellation | Never appears in the report. HR can mark a request cancelled manually, which reverses its allocations (PRD §11.3). A request missing from a later full report raises an HR alert; it is not cancelled automatically. |
| Covered-but-working types | Work From Home, Business Travel and Sick Leave mark the day as covered (not absent). |
| Annual Leave | Covers the day; it comes from the employee's balance in the company system. |
| No punches + no leave on a working day | Absent. |
| Rest day | Friday by default; HR changes it per employee (mainly the security team). See open question Q1 for office staff. |
| Contractors and visitors | Not assessed (no absence/lateness), but their punches are stored and appear in attendance and restaurant reports. |
| Devices | Each device is check-in only, check-out only, or restaurant. Restaurant punches are counted for meals only. |
| Interface language | English default, Arabic second, for both modules. |
| Sample files | Design samples only. All locations will be provided later. |

## 3. What the sample files showed

**Leave report** (`IRAQ_All_Leave_REPORT…xlsx`, 21,249 rows, one sheet, header in row 1):
`MyForm: Form Number`, `Owner Pers. No.`, `MyForm: Owner Name`, `Form Approver`, `Owner Location`, `Owner Department`, `Leave Status`, `Leave Type`, `Leave Type (Other)`, `Leave Starting Date`, `Leave End Date`, `Leave Duration (Work Days)`, `Leave Duration (WorkDays, Approved)`, `MyForm: Created By`, `MyForm: Created Date`, `Direct Manager`, `Leave Form Rejected`.
- Dates are `M/D/YYYY` text. Some start dates are implausible (1970) — isolate and flag them.
- `Leave Duration (WorkDays, Approved)` is empty in every row; use `Leave Duration (Work Days)`.
- All 1,934 rows without a duration are `Leave Entry`.
- Leave type `0200` (6 rows, one of 83 days) is unknown — see Q4.
- **No description column in this export.** A version with descriptions will be supplied; the AI-interpretation spec waits for it.
- Approver and manager are names, not IDs or emails.

**Attendance transactions** (`Transaction_…xlsx`, 4,828 rows, title in row 1, header in row 2):
`Employee ID`, `First Name`, `Department`, `Date` (`YYYY-MM-DD`), `Time` (`HH:MM`, no seconds), `Punch State` (`Check In` / `Check Out` / `255`), `Serial Number`, `Device Name`, `Data Sources`.
- Punch state always matches the device: `*_In_Face` → Check In, `*_Out_Face` → Check Out, `*_RESTAURANT_Face` / `*_RESTARRANT_Face` → 255. Device roles are an HR-confirmed setting, keyed by serial number.
- Times have minute precision only, so exact duplicate rows are common (907 in the sample).
- Employees have 8-digit IDs; visitors and contractors have 2–6 digit IDs and departments such as `Guest` and `3rd Party …`.

**Do not commit the sample files** — they hold real names. Keep them outside the repo and build test fixtures from small anonymised extracts.

## 4. Open questions (answer before the spec that needs them)

| # | Question | Needed by |
|---|---|---|
| Q1 | Office staff rest days: Friday + Saturday (PRD §6) or Friday only? | 503 |
| Q2 | `Compensation Leave` vs `Overtime Equivelant Leave`: which consumes a rest-day entitlement and which consumes hour credit/overtime? | 504 / later |
| Q3 | Annual leave: just accept approved leave as cover (recommended), or also check the balance? | 504 |
| Q4 | Administrative, Paternity, Funeral, Bereavement, Marriage, Court, Unpaid (`Other`) and `0200`: does approved = covered for all? | 504 |
| Q5 | After merge-back: should an attendance case offer an HR-clicked "open disciplinary report" action, or only show both side by side? | 505 |
| Q6 | Employee/email/work-pattern file: actual column names (sample pending). | 501 commit mapping |
| Q7 | Leave export with descriptions; overtime report; holiday email sample. | Later specs |
| Q8 | PRD §26 items (office departure after 09:00 arrival, overtime thresholds, credit allocation mechanism, …). | 503 onward |

## 5. Spec sequence

Each spec is one branch and one PR, built with the spec-kit flow (`/speckit-plan` → `/speckit-tasks` → `/speckit-implement`). Numbers start at 500 so they never collide with Displanary's.

| Spec | Scope | Depends on |
|---|---|---|
| **500 foundation-cleanup** | Remove the discipline module, keep and test the shared foundation, amend the constitution | — |
| **501 employee-record-for-attendance** | Shared employee fields, dated history, full-population Excel/CSV upload with impact preview, missing-employee exit review | 500 |
| 502 attendance-import | Import batches, raw transactions, device roles, duplicate protection, punch cleanup, restaurant counts, contractors/visitors | 501 |
| 503 office-attendance | Work-pattern policies (versioned), daily sessions, two time fields, office flexibility, lateness, early departure, short hours, missing punches + HR correction, pending/incomplete days | 502, Q1 |
| 504 leave-import-and-matching | Leave requests with source + status history, coverage rules, hour allocation, surplus credit, manual cancellation and reversal | 503, Q2–Q4 |
| 505 employee-page-and-dashboard | Attendance section on the employee page, attendance dashboard, search, Excel export and printable statement | 504, Q5 |
| later | Shift/security inference; holidays (AI extraction + HR approval); overtime & compensatory entitlements; notifications; description AI; admin chat; **merge back into Displanary** | — |
