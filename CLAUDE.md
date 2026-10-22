# CLAUDE.md — Attendance & Leave Reconciliation (Iraq)

This file is the build reference for Claude Code in this repo. Read it fully before writing any code.

- **Requirements:** `docs/attendance/PRD-EN.md`
- **Product-owner decisions, sample-file findings, open questions, spec order:** `docs/attendance/ROADMAP.md`. Where the roadmap and the PRD disagree, **the roadmap wins**.
- **Why this repo exists and how it relates to Displanary:** ADR 0013 (`docs/adr/`).
- **The original Displanary build reference** is kept for context in `docs/upstream/DISPLANARY-CLAUDE.md`. Its foundation rules (tenancy, audit, security, process, design) are restated below and still apply. Its discipline rules do not apply here.

## 1. What this system does

A multi-tenant SaaS, used first by the product owner's own company (1,000+ employees, sites Hilla, Karbala, Erbil, Baghdad) and later sold to other companies. HR uploads attendance-device exports, the company leave report, the employee list, the approved-overtime report and holiday emails. The system keeps history, cleans repeated punches, calculates attendance, lateness, early departure, short hours, missing punches, overtime and rest-day entitlements, reconciles them against leave, rechecks affected cases after every upload, and explains every result on one readable employee profile, a dashboard, reports and an admin chat.

It does **not** replace the company's leave system, calculate pay, or make disciplinary decisions.

## 2. Relationship to Displanary (ADR 0013)

- This repo started as a copy of Displanary (`HassanMahdiHussien/Displanery`) at commit `2d85ddb`, history included, then spec 500 removed the discipline module and kept the shared foundation.
- **Long-term goal: merge attendance back into Displanary as a module**, so one employee search shows attendance and discipline together and one HR dashboard covers both. Everything here must make that merge cheap:
  - Keep Displanary's foundation code (tenancy, users, roles, audit, employees, branches, import pattern, design, i18n, deploy) **structurally identical**. Change it only where attendance needs to, and record every such change in `docs/upstream/FOUNDATION-CHANGES.md` (file, what, why) so it can be applied to Displanary.
  - New attendance code lives only in its own namespaces: `App\Services\Attendance`, `App\Models\Attendance`, `App\Livewire\Attendance`, `App\Filament\Admin\Resources\Attendance`, `database/migrations/*_attendance_*`, `tests/Feature/Attendance`.
  - Spec numbers start at **500** so they never collide with Displanary's specs.
  - At merge time, attendance commits are brought into Displanary **without** the spec-500 cleanup commit.
- Fixes to shared foundation code made in Displanary after the copy may need porting here (and vice versa). Check before changing foundation files.

## 3. Tech stack (fixed — do not deviate without asking)

Same as Displanary: Laravel 13 (PHP ^8.4), MySQL, shared-schema multi-tenancy via `company_id`, Livewire 4 + Tailwind for working screens, Filament 5 for back-office CRUD, Pest, Laravel Pint, `spatie/laravel-permission`, `spatie/laravel-backup`, `openspout/openspout` (Excel read/write), Browsershot for printable statements, Claude API for AI, local-disk storage on a private VPS (no cloud storage), Laravel queues (database driver) + scheduler, native WSL Ubuntu locally (no Docker). **Never add a package without asking.**

## 4. Attendance rules (load-bearing — get these exactly right)

- **One employee record.** Extend `employees`; never create a second employee table. External reports match on `employees.employee_code` as exact text (leading zeros kept, never cast to a number). Sites in the reports are `branches`.
- **Effective-dated history.** Attendance reads employee values through `EmployeeHistory::asOf()`. A change never silently alters past results; it triggers recalculation of the affected days and shows the differences.
- **Import is idempotent.** Re-uploading the same or an overlapping file never duplicates transactions, cases, balances, allocations or notifications. Keep the original file, uploader, time, source rows, declared coverage and errors for every batch.
- **Originals are never altered.** Cleaned punches, manual corrections and exclusions are separate records that point to the original row. A manual time never looks like a device punch.
- **Incomplete is not absent.** An ongoing or incompletely covered day stays pending; never confirm absence or a missing punch early. A device outage becomes one grouped data issue, not absence for everyone.
- **Leave is imported, never created or approved here.** `leave_requests.source` exists so a future native leave module is another source. Status rules: ROADMAP §2.
- **Hours:** 1 day = 8 h, 0.5 day = 4 h. Never assume where a half-day sits without evidence.
- **No double counting.** Lateness and early departure stay separate in detail, but the hours requiring reconciliation contain non-overlapping minutes only. Every allocation links one source to one target; the same hour can never be spent twice; reversing a source reverses everything that depended on it.
- **Surplus credit** (e.g. 4 h leave covering 3 h) is kept as employee credit and **deducted automatically** from the employee's other unreconciled hours (absence, early departure, lateness), oldest first, each deduction with a stored explanation — product-owner override of PRD §11.2 (2026-10-22, spec 504). Cancelling the source reverses every deduction it funded.
- **Calculations are deterministic service code with versioned policies.** Formulas never live in AI prompts. AI only proposes (leave descriptions, holiday emails, chat intent); HR confirms; AI output never changes a balance by itself. Employee text is data, never an instruction.
- **Chat uses the same services and permissions as the screens.** It executes only clear, authorised, reversible changes, shows before/after and effective date, and logs everything.
- **Never open a disciplinary case or send an employee a formal request automatically.**
- **Interface language:** English default, Arabic second, RTL for Arabic (ADR 0014). Every string ships in `en` and `ar`. Never machine-translate names or source descriptions.

## 5. Foundation rules carried over from Displanary

- **Tenant isolation is non-negotiable.** Every tenant table carries `company_id` (or inherits it through a composite foreign key), every read goes through model-layer scoping and policies, and every new tenant model or route gets an isolation test.
- **Audit is append-only.** Every import, calculation run, correction, allocation, decision, notification and chat change writes an audit row with actor, time, before/after and source. Audit and history tables are protected by MySQL triggers; no update or delete path exists.
- **Security baseline:** HTTPS everywhere, encrypted backups to a different provider, rate-limited login, session timeouts, never log secrets or employee personal data, never disable CSRF/auth/tenant scoping. Don't email medical details.
- **Business logic in services; Livewire and Filament stay thin.**
- **Slow work runs as queued jobs** (imports, recalculation, AI calls, exports, emails), never inside a web request; retries are safe because jobs are idempotent.
- **Design:** `DESIGN.md` is the authority (midnight shell, signal amber only for the one primary action, Readex Pro + JetBrains Mono for numbers and times).
- **Hosting:** plain VPS set up by script (`deploy/`), as in Displanary.

## 6. Working with Claude Code — daily process

- One spec per branch and PR, through spec-kit: `/speckit-specify` → `/speckit-clarify` → `/speckit-plan` → `/speckit-tasks` → `/speckit-implement`.
- Read similar existing files before writing; match their style. If patterns disagree, ask.
- Simple Laravel first; no new abstraction until a pattern appears 3+ times; no speculative future-proofing.
- A change touching more than ~5 files outside the planned scope: stop and ask.
- Never install packages, change project-wide config, or touch production without asking.
- Comments explain why. Deliberate non-obvious choices get one line in `WEIRDNESS.md`; hard-to-reverse ones get an ADR.
- `main` stays deployable; feature branches merged by PR; one-line present-tense commit messages; Pint before every commit.
- Ambiguous → ask. Two reasonable approaches → present both.
- End every task with: summary, affected files, new dependencies/env vars/migrations, manual steps.
- **Never commit the real sample reports** (they contain real names). Build fixtures from small anonymised extracts.

## 7. Testing priorities

Write Pest tests first for the paths where a bug costs real money, trust or data:
1. Tenant isolation for every attendance table and route.
2. Import idempotency: re-uploads and overlapping files create no duplicates.
3. Punch cleanup: repeated check-ins/outs collapse; genuine out→in intervals survive; restaurant punches never count as attendance.
4. Time math: office flexibility (08:00–09:00 window), shift lateness, cross-midnight assignment, the two time fields, no double counting.
5. Leave matching: coverage rules, 4 h covering 3 h leaves 1 h credit, rejection rule, reversal on cancellation.
6. Rest-day entitlements and overtime matching (when built).
7. Pending/incomplete days never become confirmed absence.
8. History: back-dated changes recalculate only affected days.
9. Agreement: dashboard, profile, reports and chat return the same figures for the same filters.

The full suite and Pint must pass after every implementation step and before every merge.
