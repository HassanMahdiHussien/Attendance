# WEIRDNESS.md

Running log of deliberate, non-obvious decisions — so nobody "fixes" them later thinking they were mistakes. Add one line whenever you make a call that looks odd out of context but was intentional.

---

- **Shared-schema multi-tenancy (`company_id` column) instead of a separate database per company.** Deliberate simplification for the expected scale (<10 companies in year one). Revisit only if the client count grows past ~15-20, or if a large client contractually requires full data isolation — see PRD Section 13, technical architecture note.

- **Committee membership is a many-to-many "pool" (with alternates per role), not a fixed single person per role.** This matches the structure of a real Administrative Order the company provided — HR/Legal/Labor-Union roles each list multiple alternate names, and attendance is recorded per session. Don't simplify this back to one-person-per-role; it would break real committee sessions where a different alternate attends. See CLAUDE.md Section 4.

- **The AI drafts full legal documents but never supplies facts or the decision.** This was a deliberate design split after discussion (originally the AI was scoped as "reference/alert only," then expanded to full drafting — see PRD Section 26). Don't be tempted to let the AI infer missing facts or suggest an outcome "to save the committee time" — that would cross the line the whole legal-defensibility story depends on.

- **Signature "verification" is a presence-check only (is the box filled or empty), never identity authentication.** Don't upgrade this to claim verified authenticity without an explicit legal-team sign-off first — the UI copy must stay honest about what was actually checked.

- **File storage is local disk on a private VPS, not S3/cloud.** Deliberate choice for data-residency/trust reasons with Iraqi companies handling sensitive HR data — not a missing feature to "upgrade" to cloud storage later without discussion.

- **The 15-day procedure deadline is anchored to `defense_letter_issued_at`, not case-opened or committee-notified date.** Confirmed from the literal text of a real Administrative Order. This is easy to get subtly wrong if someone "cleans up" the date logic later without re-reading the source document.

- **The external legal reviewer is not a committee member and reviews only after the committee has already signed Doc A**, for sensitive cases only. Don't merge this role with the internal "Legal" committee-pool member — they're deliberately separate people with separate purposes (PRD Section 25.2).
- **There is deliberately no `employee_violations` table.** An employee's disciplinary history is just their prior rows in `cases`. An earlier draft of the design referenced a separate violations table; it was removed because it would duplicate `cases` and drift out of sync. If you find yourself wanting one, you probably want a query, not a table. (2026-09)
- **A document's official reference number (العدد) is assigned at issuance, not at draft time.** A draft that never gets issued must not consume a number — otherwise the company's correspondence sequence ends up with unexplained gaps, which is exactly the kind of thing that gets questioned in a dispute. (2026-09)
- **Only the recommendations document has an approval gate.** The defense letter is issued by HR directly, and the decision letter is derived from already-approved recommendations — neither gets its own committee sign-off in the system. This is deliberate and matches the pilot company's real process; don't "fix" it by adding gates for consistency. (2026-09)
- **The General Manager signs on paper but has no user account.** `employees.is_general_manager` exists only so the system knows whose name to print. Don't add a login role for them. (2026-09)
- **Committee approvers cannot edit document text — only approve or reject with a comment.** HR does all editing. Any edit resets prior approvals, because the approved text is no longer the text on the page. (2026-09)
- **Arabic is the authoritative language; other languages are renderings.** If the Arabic and English versions of a document ever disagree, Arabic governs — it's the language of the governing law. Kurdish stays off by default until a Kurdish-reading lawyer has reviewed real output. (2026-09)
- **Every deadline is calendar days with no pausing — on purpose.** Weekends, public holidays and employee leave all count. There is deliberately no working-day logic and no holiday calendar in this system; if you find yourself building one, stop and check, because it was explicitly ruled out. Corroborated by the pilot company's real letter: issued 18 June, response due 21 June. (2026-09)
- **External legal consultation is request-only — nothing routes there automatically, not even termination.** Someone on the case must press the button. Don't add automatic triggers. (2026-10)
- **Two different day-counting rules exist on purpose.** Discipline deadlines count plain calendar days (holidays included). Leave deduction (platform phase) skips public holidays that fall inside a leave — so a holiday calendar will exist, but only the leave module may use it. (2026-09)
- **Employees having no login is Phase-1 scope, not a permanent rule.** The platform gives every employee an app account later; official discipline delivery stays paper-based even then. (2026-09)
- **The AI never types the text of a legal article.** It returns `[[cite:<id>]]` markers; the system prints the official text from `legal_units`. Any article number in the AI's prose outside a marker is a hard error. Don't "simplify" by letting the model quote articles. (2026-10)
- **Every law article is extracted twice, by two different methods, and compared.** The 2015 Gazette PDF has a broken text layer, so extraction is error-prone; a single wrong digit would make the system quote wrong law with full confidence. (2026-10)
- **Statutes belong to the platform, not to companies.** Only the Platform Admin can add, version, repeal or correct Labor Law 37/2015, Labor Law 71/1987 (Kurdistan) and Social Security Law 18/2023. Social Security 18/2023 applies to both regions by default — unconfirmed for Kurdistan, kept as a setting, not code. (2026-10)
- **Tenant children carry denormalised `company_id` and composite foreign keys to tenant parents.** The duplication lets MySQL reject cross-company relationships instead of relying on application checks alone. (2026-10)
- **`users` is outside the fail-closed tenant model scope.** Authentication must find an account before it knows the tenant; company access is enforced through roles and policies. (2026-10)
- **MySQL triggers enforce legal-record immutability.** Both local and production MySQL need `log_bin_trust_function_creators=1` so migrations can create those triggers. (2026-10)
- **Automatic reference numbers skip numbers already entered manually.** A company may reserve a number outside the app; automatic issuance advances past occupied values. (2026-10)
- **Termination issuance requires an HR leave/absence confirmation.** HR checks that the employee is not on statutory leave or documented sick absence; the system records who and when. This is the source until the leave module exists. (2026-10)
- **Requested legal consultations are visible firm-wide.** Every reviewer at the partnered law firm can see a consultation-requested case, rather than only the reviewer who first opens it. (2026-10)
- **Legacy `company_legal_reviewer` accounts become deactivated reporters.** A company admin must deliberately reassign access; the migration does not silently elevate them. (2026-10)
- **Tenant middleware never clears context in `finally`.** Filament persistent middleware is a pre-check for Livewire updates; context is cleared on `RequestHandled`. (2026-10)
