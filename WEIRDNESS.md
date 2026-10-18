# WEIRDNESS.md

Running log of deliberate, non-obvious decisions — so nobody "fixes" them later thinking they were mistakes. Add one line whenever you make a call that looks odd out of context but was intentional.

---

- **Intake AI asks questions only; it never answers them.** Human answers remain separate from the incident description and are the only new intake facts passed to defense-letter drafting. Open questions stay internal and never appear in issued documents. (2026-10-09)

- **The interface defaults to English and remembers each user's Arabic choice.** Statistical exports use the language captured when requested; issued documents keep their bilingual content with Arabic governing, while operational mail to the service operator stays English.

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
- **Case status has a MySQL trigger boundary.** Only `CaseWorkflow` may set the session flag that permits a status update; model checks give an earlier error, while the trigger catches raw SQL. (2026-10)
- **Corrected documents never move deadline anchors or case stage.** New versions preserve the original defense and decision issue dates, even when a correction is issued after archive. (2026-10)
- **Requested consultation becomes visible to the partnered firm only after signed Doc A.** A request may be recorded earlier; the reviewer cannot open the case until `signed_recommendations_uploaded` or a later stage. (2026-10)
- **A name-only committee conflict requires HR clearance with a reason.** Employee-linked conflicts remain hard exclusions; a clearance records the identity check without rewriting the original party or member name. (2026-10)
- **Deadline alerts use a retryable outbox.** `case_alerts` can move from pending or failed to sent, while the corresponding `case_events` entry remains the append-only delivery record. (2026-10)
- **A committee-pool login may be a committee member, HR staff or company admin, never a reporter or department manager.** HR and Legal alternates are usually staff who already have an account; forcing a second committee-only account would split their audit trail. Pool members see a case only at the approval stage (or as attendees), not as a standing grant. (2026-10)
- **Templates hold wording only, never facts or decisions.** Employee, case, committee and outcome values are resolved from the records when the document is issued. (2026-10-05)
- **Kurdish templates must be supplied by the company.** There is no system default, even when Kurdish is enabled. (2026-10-05)
- **Issued PDFs render from frozen HTML.** A later template or fact edit cannot change the issued output. (2026-10-05)
- **English name fields are optional renderings; Arabic remains the source.** Missing English values fall back to the Arabic fields. (2026-10-05)
- **The shared `/login` admits every active role to its own landing, while Filament panels remain role-limited.** A workspace login does not grant panel access. (2026-10-05)
- **An invisible case returns 404.** The URL does not reveal whether another tenant's or another assignee's case exists. (2026-10-05)
- **No response is a case event, not a committee session.** HR may record that the response deadline passed without inventing a session or changing the case stage. (2026-10-05)
- **A second reviewer differs from both the issuer and the last content editor.** Editing after review clears the review, so issuance covers the current text. (2026-10-05)
- **HR or a company admin records the committee session, while the minutes name the attending pool member who typed them.** This preserves committee authorship without giving an unrecorded attendee a case transition. (2026-10-06)
- **Signature presence is recorded by HR from the scan.** AI may suggest a box state, but HR records the observation; a filled box is never proof of signer identity. (2026-10-06)
- **The legal-library AI transcribes page images only; our splitter identifies articles and clauses.** AI output is an independent reading, never the source of unit boundaries or legal decisions. (2026-10-07)
- An outside page transcription can fill one reading slot, and a separately sourced typed law copy can fill the other. Their origin changes the extraction method, not the two independent readings, mismatch review against the gazette image, or `verified_*` citation gate. (2026-10-14)
- Batch reading waits for the Message Batch to end before streaming its per-page results; replay must be idempotent and a failed page returns to the existing retry path. The batch changes cost and timing, not verification. (2026-10-14)
- **Text and Word regulations are verified from their exact source text but still need company lawyer approval.** Source fidelity and company authorization are separate gates. (2026-10-07)
- **A clear PDF regulation page may have one local reading, with individual company lawyer approval as its second check.** Statutes still require two independent readings; mixed articles require source-image review. (2026-10-14; ADR 0011)
- AI law-versus-regulation comparisons are review aids: HR confirms or changes them before AI drafting, and their quoted note stays on internal review screens, never in an issued letter or PDF. Manual drafts also need a confirmed non-conflict pair before citing each regulation unit; without one, or after a skip, only the labour law may be cited. (2026-10-09)
- **A `verified_auto` unit stores the AI reading's clean spacing as its official text.** The normalized comparison establishes equivalence while the published text remains readable. (2026-10-07)
- **PDF text layers with spaces inside words fall back to OCR.** Such layers can appear selectable while corrupting Arabic word boundaries. (2026-10-07)
- **The drafting AI never receives names or personal IDs.** Known names in free text are masked as whole words and restored after drafting; long numbers, phones, and emails remain masked. (2026-10-08)
- **The outcome keyword check is a review flag.** The operative recommendation sentence comes from the recorded outcome through the template, while the keyword check only calls attention to potentially conflicting prose. (2026-10-08)
- **Legal topics live outside immutable unit rows.** Platform editors can retag a current verified unit without changing its official text or version. (2026-10-08)
- **Claude responses use the first text block.** A thinking block can precede the model's text, so the first content block is not necessarily the answer. (2026-10-08)
- **An AI transcription is an attempt until HR checks it against the response scan.** Committee readers and drafting receive only the confirmed case text; starting a correction clears that text until HR confirms a replacement. (2026-10-09)
- **Signature presence suggestions describe boxes, never signer identity.** HR records the final observation against the exact signed scan; a newer unchecked scan cannot use an older check for override. (2026-10-09)
- **Scan reading sends the full uploaded scan.** The response requires its handwriting and the recommendations require printed names, roles, and boxes in context. Logs record hashes and attachment IDs instead of scan bytes. (2026-10-09)
- **A stats-only HR partner uses an aggregate-only visibility path.** Category counts may include linked companies without case access, while case lists and links still use normal case visibility. (2026-10)
- **Dashboard department hotspots use the employee's current department.** Historic cases may move between groups when the employee record changes; this is the product's chosen grouping. (2026-10)
- **Dashboard and notification deadline alerts share the global approaching-hours setting.** There is no company override, so the same case reaches both surfaces at the same time. (2026-10)
- **Report downloads re-check the current owner's role and company access.** A previously generated file is revoked and deleted when the owner's access is withdrawn. (2026-10)
- **A historical report's open count means open at the period end.** Dashboard open counts remain live; past reports describe their selected period. (2026-10)
- **Every Excel text cell is an explicit string.** A name beginning with a formula character remains text to prevent spreadsheet formula injection. (2026-10)
- **Empty optional employee CSV cells never erase stored email or gender.** Imports update known employees while preserving those values unless a replacement is supplied. (2026-10)
- **Employee CSV accepts Windows-1256 as well as UTF-8.** Iraqi Excel exports may use this encoding and semicolon separators; the file is decoded before row validation. (2026-10)

- Email forwards use a separate `email_drafts` table because an email lacks the structured facts required by a violation report or case. HR enters those facts through the normal form. (2026-10)
- Only the topmost raw `Authentication-Results` header counts; its authserv-id must be the configured receiving host. Lower headers can be forged by a sender. (2026-10)
- Discarded mail leaves only its sender domain, receipt time and rejection reason in the platform log. Addresses and content are never copied to a tenant. (2026-10)
- The 48-hour duplicate check stays at HR case intake. A reporter sees only the bare status of a submitted report and must not learn whether a case already exists. (2026-10)
- Committee members use their own accounts for invitations and approvals; invitation links are deliberately deferred. (2026-10)

- The workspace follows the approved mockup's layout and components. The design refresh itself did not add routes or change case behavior; global search arrived as a separate feature. (2026-10)
- A case's next action favors one the viewer can take, then a pending corrected document, then a requested consultation, then its workflow stage; deadline alerts change the badge, never the actor. Product-owner default (2026-10-08).
- Reporters and department managers have no global search; their own reports list is their only route to those records. Product-owner default (2026-10-09).
- Before the response due day ends, the Next step card says it is waiting for the employee while still offering HR a secondary paper-response action. Product-owner default (2026-10-09).
- Board branch and stage preferences are UI state and bypass user model events, so they do not create audit entries. Search terms are not stored. (2026-10-09)

- **Related-case links are permanent; mistakes receive an append-only "linked in error" annotation.** They appear only to viewers who can currently open the other case, and are internal context for the AI. They are never printed in documents because the linked case may concern another employee; this is the deliberate exception to CLAUDE.md §24.4. (2026-10-09)
- **Automatic decision-letter drafting never blocks recording signature-box presence.** A refused request leaves the ordinary manual and retry paths available, and a later AI outage follows the existing failed-draft recovery. (2026-10-09)

# Provisioning boundaries

- **A selected violation type keeps its Arabic name in the existing text column.** This is a historical snapshot for older guards and unmapped comparisons; current screens use the translated list name. (2026-10-09)
- **New document HTML is digit-normalised when issued.** The frozen HTML includes facts, manually entered reference numbers and citations; previously issued HTML, contents and PDFs remain untouched. (2026-10-09)

- Existing companies with cases inherit only the internal-regulation answer during setup migration; a new opening still needs current letterheads, branch coverage and a committee order. An uploaded regulation satisfies setup while lawyer approval is pending, but its units remain uncitable until approved. (2026-10)

- Outside local and testing, a backup refuses an archive password shorter than 32 characters. The archive entries expose storage path names, but their contents require the password.
- Restore drills use a dedicated scratch database and marker table. The drill checks its account grants in production; tests share the `testing%` database account and skip that grant check.
- The LUKS data volume unlocks with a key stored on the root disk. This protects a detached volume or volume-only snapshot, not a running server or both disks together.
- MySQL uses a bind mount from the encrypted volume to /var/lib/mysql, created before installation, so AppArmor's normal datadir path remains valid.
- Production migrations are expand-then-contract. A failed migration leaves maintenance enabled; deploy never reopens old code against a possibly changed schema.
- Local development runs natively in WSL2 (Ubuntu 26.04, `deploy/dev-setup.sh`) from the Linux filesystem (`~/displanery`), not a Windows drive and no longer through Docker Sail: reading a Windows drive made every file check ~3 ms, and Docker Desktop repeatedly wedged. Dev and production both use Ubuntu 26.04 with PHP 8.5 (decided 2026-10-16).
- The admin and platform panels have no login page of their own: everyone signs in at `/login` (old `/admin/login` and `/platform/login` redirect there). Filament's login refused non-panel roles with the generic "credentials do not match" error, which looked like a wrong password.
- Filament's top bar and navigation are disabled in both panels; the workspace navigation partial renders through the panel body hook so all three surfaces share one header and phone menu. (2026-10-18)
- PDF text-layer kaf repair learns words only from current verified units of other statutes, never this run's AI reading, so the two readings remain independent evidence.
- A multi-employee incident copies shared intake facts to its cases but requires a separate case-specific description and minutes for legal drafting; shared text may name another employee and cannot safely be printed in that employee's letter. (2026-10-15)
- Free employee names stay on the report; HR selects real employee records before conversion, with at least one record for each reported person. The report keeps the original names as submitted evidence. (2026-10-18)
- The report assistant sends a masked reporter conversation to Claude in queued turns. It validates extracted facts against reporter messages before filling the draft; invalid turns retry and then open the plain form. Unsent draft sessions are pruned after one day; sent transcripts remain immutable. (2026-10-18)
- Report assistant checks are tuned to real Haiku output: a reworded fact that is already filled keeps the stored answer instead of failing the turn; a list location ("خط الإنتاج 1") needs a traced place_text, not the reporter's exact words; facts and summary compare light Arabic stems; hours may be words ("العاشرة"). New numbers, dates and name tokens in a summary are still hard errors, and the reporter approves the summary. (2026-10-18)
- Report assistant asks only what a defense letter needs (the committee investigates later): the model may set no_witnesses for defects or failures found afterwards, shown as "No witnesses" for the reporter to confirm on review; an employee concerned is never also stored as "responsible", so the model cannot single one out. Untraceable or decorated facts are cleaned or dropped, never a reason to fail the turn. (2026-10-18)
