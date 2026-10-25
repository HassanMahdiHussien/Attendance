# WEIRDNESS.md

Running log of deliberate, non-obvious decisions — so nobody "fixes" them later thinking they were mistakes. Add one line whenever you make a call that looks odd out of context but was intentional.

---

- **The interface defaults to English and remembers each user's Arabic choice.** Operational mail to the service operator stays English.

- **Shared-schema multi-tenancy (`company_id` column) instead of a separate database per company.** Deliberate simplification for the expected scale (<10 companies in year one). Revisit only if the client count grows past ~15-20, or if a large client contractually requires full data isolation.

- **File storage is local disk on a private VPS, not S3/cloud.** Deliberate choice for data-residency/trust reasons with Iraqi companies handling sensitive HR data — not a missing feature to "upgrade" to cloud storage later without discussion.

- **Tenant children carry denormalised `company_id` and composite foreign keys to tenant parents.** The duplication lets MySQL reject cross-company relationships instead of relying on application checks alone. (2026-10)

- **`users` is outside the fail-closed tenant model scope.** Authentication must find an account before it knows the tenant; company access is enforced through roles and policies. (2026-10)

- **MySQL triggers enforce append-only audit and AI logs.** Both local and production MySQL need `log_bin_trust_function_creators=1` so migrations can create those triggers. (2026-10)

- **Tenant middleware never clears context in `finally`.** Filament persistent middleware is a pre-check for Livewire updates; context is cleared on `RequestHandled`. (2026-10)

- **The shared `/login` admits every active role to its own landing, while Filament panels remain role-limited.** A workspace login does not grant panel access. (2026-10-05)

- **Claude responses use the first text block.** A thinking block can precede the model's text, so the first content block is not necessarily the answer. (2026-10-08)

- **Every Excel text cell is an explicit string.** A name beginning with a formula character remains text to prevent spreadsheet formula injection. (2026-10)

- **Empty optional employee CSV cells never erase stored email or gender.** Imports update known employees while preserving those values unless a replacement is supplied. (2026-10)

- **Employee CSV accepts Windows-1256 as well as UTF-8.** Iraqi Excel exports may use this encoding and semicolon separators; the file is decoded before row validation. (2026-10)

- Outside local and testing, a backup refuses an archive password shorter than 32 characters. The archive entries expose storage path names, but their contents require the password.

- Restore drills use a dedicated scratch database and marker table. The drill checks its account grants in production; tests share the `testing%` database account and skip that grant check.

- The LUKS data volume unlocks with a key stored on the root disk. This protects a detached volume or volume-only snapshot, not a running server or both disks together.

- MySQL uses a bind mount from the encrypted volume to /var/lib/mysql, created before installation, so AppArmor's normal datadir path remains valid.

- Production migrations are expand-then-contract. A failed migration leaves maintenance enabled; deploy never reopens old code against a possibly changed schema.

- Local development runs natively in WSL2 (Ubuntu 26.04, `deploy/dev-setup.sh`) from the Linux filesystem (`~/attendance`), not a Windows drive and no longer through Docker Sail: reading a Windows drive made every file check ~3 ms, and Docker Desktop repeatedly wedged. Dev and production both use Ubuntu 26.04 with PHP 8.5 (decided 2026-10-16).

- The admin and platform panels have no login page of their own: everyone signs in at `/login` (old `/admin/login` and `/platform/login` redirect there). Filament's login refused non-panel roles with the generic "credentials do not match" error, which looked like a wrong password.

- Filament's top bar and navigation are disabled in both panels; the workspace navigation partial renders through the panel body hook so all three surfaces share one header and phone menu. (2026-10-18)

- Arabic pages reset letter-spacing on every element (`:lang(ar) *` in app.css and the Filament theme): the board's tracked capitals break Arabic joining («ج د يد»), so tracking is an English-only style. The mono font stack falls back to Readex Pro so Arabic month names in dates never use a system font. (2026-10-18)
- Attendance punches are deduplicated by (person code, minute, punch state, device serial): two genuine punches in the same minute on the same device count once, exactly like an export duplicate (PRD §8).
- A device's role and site take effect at local midnight of the setting's date; a pending device's punches are stored but ignored until HR approves it.
- Repeated-punch groups never span more than 16 hours (`attendance.cleanup.max_group_gap_hours`) so two shifts are never merged.
- People are contractors/visitors by their department label (`3rd Party …`, `Guest`, `General`), not by ID length: the real export has 8-digit contractor IDs.
- Office arrival after 09:00 makes 18:00 the required leaving time (not arrival + 9 h), and arriving before 08:00 never allows leaving before 17:00 (product owner, 2026-10-22).
- Short hours is an alert only: office presence below 9 h and shift/security presence below 8 h. Office absence remains 8 h for leave; short hours is never added to the reconciliation balance because it overlaps lateness and early departure.
- A shift day owns its midnight boundary punches once, even when the checkout timestamp falls on the next calendar date; reassessment widens date scopes on both sides to rebuild neighbouring ownership.
- The first office policy version is effective from 2000-01-01, not the company's creation date: HR imports punches from months before the company joined the system.
- An attendance day is not assessed until confirmed coverage of the employee's site reaches the required leaving time plus 2 hours; until then it is "pending", never absent.
- Surplus leave credit is deducted automatically (oldest unreconciled hours first, oldest credit first, each with a written explanation), overriding PRD §11.2 — product owner, 2026-10-22. An HR reversal of one deduction stops that target from being auto-funded again.
- A leave request is rejected whenever `Leave Form Rejected` has a date, whatever the status column says; an older leave report uploaded after a newer one never changes a request's current status (latest snapshot time wins).
- Leave duration accepts the first matching count from employee working days, the company leave calendar (Friday off by default), then calendar days; minutes still land only on employee working dates. This mirrors the real leave export's Saturday and calendar-day counting without fabricating office leave on rest days (product owner, 2026-10-22).
- Arabic attendance spreadsheets set the first sheet's OOXML right-to-left flag after OpenSpout closes the file; the cell values remain typed strings so employee codes keep their leading zeros.
- Empty work patterns get one dated rule choice: a Security title outranks blue or white collar, and HR changes are never overwritten by later imports. Information Security and Security Manager titles need a separate HR review issue (product owner, 2026-10-23).
- Shift days have no grace (08:01 is 1 minute late) and the shift is inferred only when one shift fits the first check-in and last check-out within ±2 h; otherwise HR picks it and no lateness is confirmed until then (product owner, 2026-10-23).
- Office short hours alert below 9 h of recorded presence, while an office absence still counts as 8 h for leave (1 leave day = 8 h); the two are separate policy values (`min_presence_minutes`, `absence_minutes`).
- Default work patterns come from a rule (position "Security" → security, blue collar → shift, white collar → office) applied only to employees without a pattern and dated from the hire date, so imported history is assessed; HR edits always win.
- A rest day with punches is `rest_day_worked` (hours kept, no lateness or short hours) instead of being dropped, so spec 508 can count rest-day work; a holiday that falls on a rest day keeps the rest-day status and records the holiday ids (spec 507).
- Annual leave balances are dated snapshots from a separate company report; the newest balance date wins, while absence from the latest completed upload is shown separately, and the first valid duplicate code in one file wins (spec 508).
- A covered holiday day is `holiday` even where the site has no device coverage: the holiday is known from the approved announcement, not from punches. A night shift belongs wholly to its start date, so it is holiday work only when it starts on the holiday.
- Holiday scope matches the employee's legal entity, branch and pattern as of each date. A new employee's first imported values count from the hire date (product owner, 2026-10-24); later imports change them from their effective date.
- The annual leave balance report has two label rows under its header (month names, then PLAN/ACT); the month of an ACT column comes from the PLAN column to its left, and both label rows are skipped as data (spec 508).
- Overtime is calculated only from punches until the approved-overtime report import (509b) exists, so screens never call it "matched"; early arrival is a marker and never counts as overtime; a compensatory day never expires, it only raises an overdue alert from the second Sunday after the work week (spec 509).
- Notification reminders count company leave-calendar working days, but do not skip approved holidays; the reminder interval measures scheduled follow-up days rather than attendance days (spec 510).
- A follow-up case is one mutable row per employee-day with an append-only event log (not run/supersede versions), so reminder counts and delivery history survive recalculation; a missing punch never routes to a leave approver, and a manager without an address is one grouped data issue for all of their employees (spec 510).
- A leave change reassesses only days that already exist; a leave date without a day has no attendance coverage yet, and the coverage-driven assessment reads the leave when the attendance file arrives. Employees with the same changed range share one assessment job (this cut a real leave import from about 15k runs and 62 minutes to 255 runs and 7 minutes with identical results).
- DatabaseSeeder includes the attendance demo during ordinary seeding, while tests opt in with `attendance.seed_demo_in_tests`; the existing pilot foundation test intentionally checks its original twelve employees alone.
