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
- Short hours (< 8 h recorded presence) is an alert only and is never added to the hours requiring reconciliation: its minutes overlap lateness and early departure.
- The first office policy version is effective from 2000-01-01, not the company's creation date: HR imports punches from months before the company joined the system.
- An attendance day is not assessed until confirmed coverage of the employee's site reaches the required leaving time plus 2 hours; until then it is "pending", never absent.
- Surplus leave credit is deducted automatically (oldest unreconciled hours first, oldest credit first, each with a written explanation), overriding PRD §11.2 — product owner, 2026-10-22. An HR reversal of one deduction stops that target from being auto-funded again.
- A leave request is rejected whenever `Leave Form Rejected` has a date, whatever the status column says; an older leave report uploaded after a newer one never changes a request's current status (latest snapshot time wins).
