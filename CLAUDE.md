# CLAUDE.md — Disciplinary Process Automation Service (Iraq)

This file is the build reference for Claude Code. Read this fully before writing any code. The full narrative PRD (with rationale/discussion) lives in `docs/PRD.md` and `docs/PRD-EN.md`, and the complete database schema (ERD) lives in `docs/ERD.md` — refer to them only when this file lacks a detail.

**This is Phase 1 of a larger platform.** Discipline is the first module of an employee-lifecycle platform (recruitment, onboarding, performance, leave, offboarding) specified in `docs/PLATFORM-PRD.md`. Build only discipline now — but follow Section 19 so later modules plug in without rewrites.

## 1. What this system does

A multi-tenant SaaS for Iraqi companies to run employee disciplinary procedures end-to-end: log a violation → AI drafts the defense request letter from human-entered facts → capture the employee's (paper) response → run a Disciplinary Committee session → AI drafts the committee recommendations from the committee's own typed decision notes → committee confirms/signs → optional external legal consultation (sensitive cases only) → AI drafts the final decision letter if a penalty applies → archive. The AI never supplies facts or decisions — it only turns human-supplied facts/decisions into legal-quality drafts, always behind a human confirmation gate. See Section 3b for the exact five AI touchpoints.

**Core value prop:** eliminate repetitive manual typing of the same facts (employee name, hire date, committee members, case details) across multiple legal documents — everything is entered once and pulled automatically into every document. See Section 6 (Single Source of Truth).

## 2. Tech stack (fixed — do not deviate without asking)

- **Backend:** Laravel (PHP), latest LTS
- **DB:** MySQL, multi-tenant via `company_id` on every core table (shared schema, not separate DBs)
- **File storage:** local disk on a private VPS (`flysystem` local driver), NOT S3/cloud
- **PDF generation:** Browsershot (Spatie) — headless Chromium, NOT DomPDF/mPDF (they mishandle Arabic/Kurdish RTL). Documents are Blade HTML/CSS templates with proper `dir="rtl"`/`dir="ltr"` per section, embedded Arabic font (Amiri or Noto Naskh Arabic), and Kurdish glyph coverage (Noto Sans Arabic or a dedicated Kurdish font) — test this in the first week.
- **AI (drafting + alerts):** Claude API (Anthropic). Used for both legal drafting (defense letters, recommendations, decision letters — from human-supplied facts/decisions only) and legal-reference alerts (citing curated law articles). Never supplies facts or makes the decision. See Section 3b.
- **Notifications:** Email (SMTP) + WhatsApp Business API (WhatsApp is phase 2, not MVP) — both are alert-only, never official legal delivery.
- **Employee portal:** none in Phase 1 (discipline). Employees have no login in this phase; the discipline interaction is 100% paper-based. **This is a Phase-1 scope limit, not a permanent rule** — the wider platform (`docs/PLATFORM-PRD.md`) gives every employee a Flutter app account (phone + OTP) in a later phase. Design the `employees` table so a `users` account can be attached later without migration pain. Even once employees have the app, official discipline delivery stays paper-based.
- **Frontend (interactive UI):** Blade + Livewire + Tailwind CSS. One PHP codebase, no separate SPA build/deploy — matches the "simple code wins" principle (Section 13). Not a separate Vue/React app. This is for the interactive dashboard/forms only — printed documents still use plain Blade + Browsershot (Section 2 above), not Livewire.
- **Admin/back-office screens: Filament (v4), built on the same Livewire.** Use Filament for CRUD-style management screens: Platform Admin dashboard, companies, branches, employees (incl. CSV import), users and reporter accounts, committee mandates and pools, legal-reference articles. Use Filament's built-in multi-tenancy (tenant = company) and RTL. Theme it with the Section 18 tokens and fonts so it sits close to the mockup. **Do not** build the case workflow in Filament — the case board, case page, and the document confirmation/issuance screen are custom Livewire matching `docs/design/dashboard-mockup.html` exactly.
- **Auth:** internal roles only (Platform Admin, HR, committee members, external legal reviewer, HR partner).

## 3. Critical business rules (get these exactly right — they are legally load-bearing)

- **Response deadline:** fixed at **3 days** system-wide (config value, not per-case).
- **15-day procedure deadline:** starts from `defense_letter_issued_at` (the date the defense request letter is issued), **not** case-opened date or committee-notified date. This is confirmed from a real Administrative Order document — do not use any other start date. Dashboard must show a red top-priority alert as this deadline approaches/passes.
- **All deadlines are CALENDAR days, and nothing pauses them (confirmed).** The 15-day procedure deadline, the 3-day response deadline and the 30-day appeal window all count plain calendar days — weekends, public holidays and Eid are counted, and an employee being on leave does **not** pause any counter. Do not build working-day logic and do not use any holiday calendar for these deadlines. (A holiday calendar will exist later for the leave module only — Section 19.) Corroborated by the pilot company's real defense letter: issued 18 June with the response due 21 June — exactly 3 calendar days.
- **30-day appeal window:** starts after the final decision letter is issued. Tracked manually — HR logs whether an appeal was filed; no automatic detection (appeals happen at external labor court). If nothing logged after 30 days, auto-archive as final.
- **Termination leave check (confirmed):** before a termination decision letter is issued, HR must confirm that the employee is not on statutory leave or documented sick absence. Record who confirmed it and when. This confirmation is the Phase-1 source until the leave module exists (PLATFORM-PRD §3.7).
- **Firm-wide consultation visibility (confirmed):** once legal consultation is requested, the case is visible to every reviewer of the partnered law firm.
- **Documents are immutable after issuance.** No silent edits. Any change requires a new versioned document + reason + who changed it. Always show a final preview/confirmation screen before issuance (default: single confirmation with warning; per-company optional setting: mandatory second-person review before issuance).
- **Two separate document types per case:**
  - **Doc A — Committee Recommendations:** ALWAYS issued for every case. Signed by whoever actually attended the committee session (see Section 4 — committee is a pool, not fixed people).
  - **Doc B — Decision Letter to employee** (Warning or Termination — same template, penalty type + legal article are variables): ONLY issued if the outcome includes an actual penalty. Signed by General Manager + Labor Union representative (different signers than Doc A). Must explicitly state whether the employee's defense was accepted fully/partially/rejected. Includes the employee's acknowledgment/signature section.
- **AI's role — drafts full legal text, but NEVER supplies the facts or the decision.** This is the critical distinction (see Section 3b below for the full touchpoint-by-touchpoint design): the AI writes polished legal Arabic wording, but every fact and every decision it drafts from was entered by a human first. The AI never determines what happened or what the outcome should be — it only turns human-supplied facts/decisions into a properly formatted legal document. A mandatory human confirmation screen always sits between any AI draft and printing/issuance (who confirms depends on the document — see the approval-gates rule below).
- **Signature verification is a presence-check only, never authentication.** When a scanned signed document is uploaded, the AI (vision) can detect whether a signature box appears filled or empty and flag missing ones — it cannot and must not claim to verify whose signature it is. UI copy must say "signature box appears empty — flag" not "signature verified as authentic." A human remains responsible for actually confirming signer identity.
- **External legal reviewer (from the service provider's partner law firm) is NOT part of the committee.** They are consulted only when someone on the case explicitly requests it (see the rule below), and only AFTER the committee has already voted/signed Doc A. Their review is an additional layer before Doc B is issued, not a replacement for the committee's internal legal member.
- **Official/legal delivery is always paper-based.** Email/WhatsApp are alert-only ("you have something to review"). Every physical hand-off needs: (a) signature capture, or (b) if refused, 2 witness signatures confirming delivery + refusal — this applies to all 3 hand-off points: defense letter receipt, response submission, decision letter receipt. Also model a distinct "delivery refused" status (employee wouldn't even accept the paper) separate from "received but refused to sign" — needs date/place/attempting-party/2 witnesses.
- **Internal work regulation (per company, optional):** if a company has a Ministry-of-Labor-certified internal regulation, it's an ADDITIONAL AI reference scoped to that company only (never visible cross-tenant). Otherwise AI relies on Iraqi Labor Law No. 37/2015 only. Two-tier legal reference: general tier (all companies) + private tier (per-company, if uploaded).
- **Duplicate report detection:** when a new report is created, check for another report on the same employee within 48 hours → prompt to link/merge instead of creating a duplicate case.
- **Conflict of interest check:** when a case is opened, check if any committee-pool member for that branch is also listed as a witness/party in the case → warn and require a substitute.
- **Prior-cases banner (broadened, see Section 24.3):** whenever a case is opened for an employee who has **any** prior case, show a prominent banner at the top of the case: how many prior cases, and for each its date, violation type and outcome (recommendation only / warning / termination). A repeat of the **same** violation type within 12 months is highlighted as a repeat.
- **External legal consultation happens ONLY on request (confirmed).** No case is routed to the external lawyer automatically — not even termination. A committee member or HR on that case can press "request legal consultation" while the case is open; only then does it route. Record who asked and when (`cases.legal_review_requested_by` / `_at`). The cost to surface in the UI: while the case waits on the lawyer, the 15-day calendar-day clock keeps running, so a pending consultation must be visible and alerted on, not silent.
- **Approval gates — only the recommendations document has one (confirmed).** HR operates the whole process day to day; where the committee steps in is deliberately narrow:
  - **Defense letter:** HR drafts (or triggers the AI draft), confirms, prints and issues it **directly**. No committee approval gate. This matches the Administrative Order, which anchors the 15-day clock to the defense request being issued *by the HR department*.
  - **Recommendations (Doc A):** requires **three individual in-system approvals — one from the Legal pool, one from the HR pool, one from the Labor Union pool** (matching the quorum rule in Section 4). Each approver has their own account and approves separately. Once all three are in, their names are injected into the document, HR prints it, all three sign on paper, and the signed copy is scanned back in for the signature-presence check (touchpoint 4).
  - **Decision letter (Doc B — warning/termination):** derived from the already-approved recommendations, so it gets **no separate committee approval gate**. HR prints it and collects paper signatures from the Labor Union representative and the General Manager.
- **The General Manager does not need a user account (confirmed).** They are a paper signer only. Keep `employees.is_general_manager` so the system knows whose name to print on Doc B, but do not create a `general_manager` login role.
- **Approval mechanics for Doc A.** A committee member can **approve** or **reject with a required comment** — they cannot edit the document text themselves. Rejection returns the case to HR with the comment visible; HR edits and resubmits. Any edit to the text **resets all prior approvals**, since the approved text is no longer the text on the page. Routing: the pending document is visible to every pool member for that case's branch, and the first approval from each of the three role categories satisfies that category — HR does not hand-pick three specific individuals.
- **Multilingual documents (confirmed).** Every issued document carries its text in more than one language, in one physical document (the pilot company's real samples are an Arabic page followed by an English page). **Arabic and English are always included; any further language is an optional per-company setting**, with Kurdish (Sorani) the first of those. Build this as an extensible list, never as fixed `ar`/`en`/`ku` columns: a `document_contents` table with one row per (document, language), and a per-company `enabled_languages` setting. Adding a language later must be a config change, not a migration of every document table.
  - The AI drafts each enabled language for the same document. Treat the Arabic text as the authoritative source and the others as renderings of it — if they ever disagree, Arabic governs (it's the language of the governing law and of the pilot company's own process). State this explicitly in the AI prompt.
  - **Approval covers all languages of the document, not just the one the approver can read.** Show the approver every enabled language side by side on the review screen, and record which languages existed at the moment they approved — so an appeal can never turn on "I only approved the Arabic."
  - **Kurdish is the weakest link and must be verified before it is offered to any real company.** Legal-register Kurdish (Sorani) drafting quality is meaningfully less proven than Arabic or English across all AI models, and the font/glyph coverage problem is separate and additional (Section 2). Keep Kurdish behind the optional per-company flag, off by default, until a Kurdish-reading lawyer has reviewed real generated output — do not ship it on the assumption that it works because Arabic does.
- **Official reference numbers (العدد) — every issued document needs one.** Real Iraqi official correspondence carries a reference number plus a date (the pilot company's own Administrative Order shows `العدد: 1097`), and the pre-issuance confirmation screen explicitly warns the reviewer to check them. Implement: `documents.reference_number`, assigned at the moment of issuance (not at draft time — a draft that is never issued must not consume a number), generated per-company and per-year as a sequential counter. A company that already has its own correspondence-numbering system must be able to enter the number manually instead of using the generated one — make the field editable on the confirmation screen, pre-filled with the generated value.
- **Case numbers.** `cases.case_number`, per-company sequential, assigned at case creation, displayed everywhere the UI shows a case (the design mockup shows this as the small monospace number on each case card). Separate from `documents.reference_number` — a case has one case number and produces several documents, each with its own official reference number.

- **Repeat-responsible-person pattern flagging (new):** `cases.alleged_responsible_employee_id` is a nullable FK to `employees` — when HR opens a case, they search/select the responsible person from the employee list if that person is in the system (structured link, enables pattern-matching); only fall back to a plain-text name (`cases.alleged_responsible_person_freetext`) if the person isn't in the employee database (e.g. an external contractor). When a case is opened with a linked `alleged_responsible_employee_id`, check: has this same person been named as the alleged responsible party in 2+ other cases within the last 12 months (any employee, same branch)? If so, show a distinct banner ("this person has been named in N other cases in the past year") to HR/the committee — visibility only, no automatic escalation or action. This is separate from the "Repeat-violation banner" above (that one tracks the case's own employee re-offending; this one tracks a recurring responsible party across different employees).

## 3b. The five AI touchpoints (exact design — build in this order)

The AI (Claude API) is used at five distinct points. At every drafting point, human confirmation is mandatory before print/issuance. Facts and decisions always originate from a human field/note; the AI never invents or infers either.

1. **Draft the defense request letter.** Input: structured incident fields entered by HR (place, time, violation type, alleged person responsible) — sourced either from direct HR entry or from an HR-forwarded email that HR turns into these structured fields (the raw email itself is never auto-converted into a case; HR always confirms/completes the structured fields first — see Section 18.5 in the PRD for the email-forward-to-draft flow). Output: the full legal-Arabic defense request letter text, using the company's standard template structure + AI-drafted violation description. HR reviews and confirms before printing — no committee gate on the defense letter (see the approval-gates rule in Section 3).
2. **Ingest the employee's response.** Input: the scanned response (uploaded after the paper hand-off). The AI reads it purely to build case context for later steps — it does not draft anything at this stage.
3. **Draft the committee recommendations (Doc A).** Input: a committee member's typed notes on what happened during the session and what the committee decided (MVP = typed notes only; audio recording + transcription is deferred to a later phase — see decision below). Output: the full "Committee Recommendations" legal document, using the committee's actual decision as given. The committee reviews, confirms, prints, and collects signatures from attendees.
4. **Verify + close + draft the decision letter.** Input: the scanned, signed recommendations document. The AI (a) does a signature-presence check per expected signer (flag missing/empty boxes — NOT identity authentication, see the caveat above), (b) confirms the case can close, and (c) if the recommendation includes a penalty (warning/termination), automatically drafts Doc B (the decision letter to the employee) from the same recommendation content. Doc B still goes through the same human-confirmation gate before printing.
5. **Side-channel notes/flags.** At touchpoints 1 and 3 (the two drafting points), the AI can append internal notes alongside the draft — missing information, unclear points, anything worth double-checking — visible to the committee/HR as a review aid, but never inserted into the official letter text itself.

**MVP decision (confirmed):** committee session minutes are captured as typed notes by a committee member, not audio recording. Audio recording + speech-to-text transcription (a separate service before the transcript reaches Claude, since the Claude API takes text input at this touchpoint) is a Phase 2 item, not MVP.

## 3bb. If the AI is unavailable — manual fallback is mandatory

Every drafting step in this system depends on an external API, and the case is running against a hard 15-day legal deadline. An outage is therefore a legal risk, not just an inconvenience — the process must never be blocked on the AI being reachable.

- At every drafting touchpoint (1, 3, 4), the review screen must offer a **"write it manually"** path: the same document, in the same template, with an empty editable body the user fills in themselves. A manually written document goes through the identical confirmation, citation-check, issuance and immutability rules — the only difference is where the initial text came from.
- Record the origin on the document row (`documents.drafted_by`: `ai` | `human`) so the audit log shows which documents were AI-drafted and which weren't.
- AI calls should be retried a couple of times with backoff, then surface a clear "AI unavailable — you can write this manually or try again" state. Never leave a case stuck in an `ai_drafting_*` status with no way out.

## 3c. QA pipeline for AI-generated drafts (two layers, not one)

A human confirmation screen alone isn't a full QA layer — a busy HR person can click "confirm" without deeply checking a legal citation. Build two layers, in this order, between any AI draft and the printed document:

**Layer 1 — citation integrity (deterministic + an independent check).** Fully specified in **Section 23** — the AI never writes article text; it references verified articles by id, the system inserts the official text, and an independent second pass checks relevance. A reviewer may override a flag, but the override is logged in `audit_log` (who, what was flagged).

**Layer 2 — human review (already designed, Section 3 immutability rule).** The committee/HR reviews the full draft, including addressing any Layer-1 flags, before confirming.

**Layer 3 — external legal reviewer, but only when requested (Section 3).** This *is* your second independent pair of eyes for the highest-stakes outcomes (termination, etc.) — it doesn't need to be duplicated for every case. For non-sensitive cases (most of them), Layers 1+2 are the QA process; adding a second AI model's opinion on every routine warning letter would cost more complexity than it buys, given how low-stakes those cases are and how it's still a human, in the end, who signs.

**Not in MVP, worth revisiting later:** a second-model cross-check (a different AI provider giving an independent opinion) specifically on Doc B drafts, before they even reach the external legal reviewer — this was discussed earlier as an option. Given the current three-layer design already gives solid coverage and the cost of adding it is negligible at this scale, it's a reasonable Phase 2 addition if Layer 1 alone turns out to miss things in practice — but don't build it into the MVP; see how often Layer 1 actually catches something first.

## 4. Committee model — IMPORTANT, this is a pool, not fixed individuals

Confirmed from a real Administrative Order: the committee is defined as **alternates/pools per role**, not single fixed people.

- `committee_mandates` table: one administrative order per company (`company_id`), versioned over time (order number, date, effective_from, superseded_at). Every Doc A references the mandate version that was in effect on the case's `defense_letter_issued_at` date, not necessarily the latest.
- `committee_pool` table: many-to-many. Each row = { mandate_id, person, role_type: legal | hr | labor_union, branch_id (nullable — order is company-level but content can assign different pools per branch) }.
- **Quorum rule (from the real order):** minimum 3 members present; the labor union representative MUST be among those present; decision = majority vote of the members actually present at the session.
- When a committee session is opened for a case, HR/the system does NOT auto-fill one fixed person per role — it presents the pool per role (filtered to the employee's branch) and lets the user select who actually attended. Each attending member gets an individual vote/signature record, not a single blanket approval.
- The "chair" role defaults to whoever attended from the Legal pool, per the real templates ("القانونية: رئيساً").
- General Manager (paper signer on Doc B — no system account, see Section 3) and Department Manager (can open new cases, scoped to their branch) are separate single-person roles, not pool-based.

## 5. Employee & branch data model

- `companies` → has many `branches` → has many `employees`.
- `employees`: name, employee_id, department, position, hire_date, branch_id.
- **`employee_id` (the company-facing employee number):** if the company already assigns their own employee numbers, use that value as given during CSV import/entry. If a company doesn't have one, **auto-generate one** (per-company sequential, e.g. `EMP-0001`, `EMP-0002`...) — never leave it blank, since it's used as the primary human-facing identifier across every document and the employee profile page.
- **Employee profile page (MVP scope, not just a DB record):** every employee needs an actual page in the UI — identity fields + their full disciplinary history (their prior rows in `cases` — see the bullet below; there is no separate violations table). Scope this page **strictly to disciplinary case history for now** — do not build out other HR-lifecycle features (resignation, leave balances, performance reviews, etc.) even though this page is the natural place they'd eventually live. Structure the page/model so those are addable later without restructuring (e.g., don't hardcode the page as "case history only" in a way that can't hold other sections), but don't build them now — see PRD Section 11 (out of scope) and CLAUDE.md Section 13 ("ambiguous task → ask before writing 200 lines on a guess").
- **Violation history is NOT a separate table.** An employee's disciplinary history is simply their prior rows in `cases` (filtered by `employee_id`, closed/archived ones). Do not create an `employee_violations` table — it would duplicate `cases` and inevitably drift out of sync. When sending prior-record context to the AI (touchpoints 1 and 3) or rendering the employee profile page, query `cases` + their issued `documents`.
- Internal work regulation file: stored at `companies` level (nullable), flag `has_internal_regulation`.
- Legal reference database: general (labor law articles, shared across all tenants) + company-scoped (internal regulation excerpts, if any) — AI must only cite verified units from `legal_units` by id — see Section 23.

## 6. Single source of truth (core design principle)

No field is ever typed twice for the same case. Entered once → pulled everywhere:
- Employee identity data → employee record → all their case documents, for their whole tenure.
- Committee mandate (order number/date) → company settings → every Doc A.
- Company letterhead → company settings → every printed document.
- Violation details → entered once at case-open → defense letter + recommendations + decision letter.
- All legal deadlines → computed from `defense_letter_issued_at` (or relevant anchor date) by fixed system rules → every place a date appears.

## 7. Case status states (for the state machine)

```
draft (from a reporter's submission or an email-forward — not yet a real case; HR completes and confirms it)
→ open (violation logged: place, time, type, alleged responsible person)
→ ai_drafting_defense_letter        [AI touchpoint 1 — drafts from HR-entered facts only]
→ defense_letter_citation_check     [Layer 1 QA — Section 3c — flags unverified article citations]
→ defense_letter_ready_for_review   [HR reviews and confirms — no committee gate, Section 3]
→ defense_letter_confirmed
→ defense_letter_issued             [printed; starts 3-day response clock + 15-day procedure clock, anchored to this event]
→ awaiting_employee_response
→ response_received | response_refused_to_sign | response_delivery_refused
→ response_scanned_uploaded → ai_ingested_response   [AI touchpoint 2 — context only, no drafting]
→ committee_session_held            [typed minutes by a committee member: what happened + the decision — MVP, no audio]
→ ai_drafting_recommendations       [AI touchpoint 3 — drafts Doc A from the member's typed decision notes]
→ recommendations_citation_check    [Layer 1 QA — Section 3c]
→ recommendations_awaiting_approvals [needs 3 individual approvals: Legal + HR + Union — Section 3; any HR edit resets them]
→ recommendations_confirmed → recommendations_printed → awaiting_committee_signatures
→ signed_recommendations_uploaded
→ ai_verify_and_close               [AI touchpoint 4 — signature presence-check (not authentication) + closes case + drafts Doc B if penalty applies]
→ decision_letter_citation_check    [Layer 1 QA — Section 3c, for Doc B if generated]
→ (if consultation requested) awaiting_external_legal_consultation   [Layer 3 QA — Section 3c — second independent review]
→ decision_letter_ready_for_review  [Doc B, only if penalty applies; HR reviews — no separate committee gate, Section 3]
→ decision_issued                   [printed; employee signs/refuses per Section 3 rules]
→ awaiting_appeal_window (30 days)  [manual HR logging of appeal filed y/n]
→ archived_final | appeal_pending → appeal_resolved → archived_final
```

## 8. Roles & permissions summary

- **Platform Admin (the service provider's own owner/founder account — you):** full visibility and management across **every** tenant company — all their cases, documents, and stats, plus account-level management (activate/deactivate a company, view subscription status). Not scoped to `company_id` at all (`users.company_id` is null for this role, like the External Legal Reviewer). This is a different, much broader role than HR Partner — HR Partner sees cross-company stats they're entitled to; Platform Admin sees and manages everything, unconditionally.
- **Reporter (accounts created by HR):** HR creates accounts for anyone allowed to raise a violation (supervisors, foremen, department managers — whoever the company chooses). A reporter can only **submit a report** (place, time, type, alleged responsible person, witnesses, attachments) and see the bare status of their own reports (received / under review / closed). They never see the case itself, the employee's response, committee notes or documents. A submitted report lands as a **draft** that HR reviews and turns into a case — same path as the email-forward draft. Creating, disabling and scoping reporter accounts (per branch) is an HR action, logged in the audit log.
- **Company Admin (HR Manager):** sees all company cases, manages users/permissions.
- **HR Staff:** default = only their own assigned/created cases; company can widen to "see all."
- **Department Manager:** a reporter with branch scope (submits reports for their branch); does not open cases directly — HR does.
- **Company legal reviewer (a permission on a company user — the company's own lawyer):** approves the company's uploaded internal regulation, article by article (Section 23.4). Sees only that.
- **Committee pool members:** see only cases where they're an attendee; can have full accounts or single-use time-limited invite links (no permanent account needed).
- **External Legal Reviewer (partner law firm, your company's side):** sees only cases where consultation was requested, routed to them for consultation, across all tenant companies.
- **HR Partner:** cross-company overview dashboard (stats/counts), full case detail only if the specific company opts in.
- Employees: no account in Phase 1 (see Section 2 — the platform adds employee app accounts later).

## 9. Build order (suggested session breakdown for Claude Code)

0. **Project setup (first session):** `laravel new`, git init on a private repo, install Livewire, Filament v4, `spatie/laravel-permission`, Pint; MySQL connection; queue (database driver) and scheduler wired; Arabic RTL layout + Tajawal/Amiri fonts per Section 18. Ask before each `composer require` (Section 13).
1. **Migrations + models:** companies, branches (with `jurisdiction`), employees, committee_mandates, committee_pool, violation_reports, cases, case_events/audit_log, documents (with immutability/versioning), signatures. See `docs/ERD.md` for the full schema.
2. **Auth + roles/permissions** (internal users only, per Section 8) + **Filament admin screens**: companies, branches, employees, users and reporter accounts, committee mandates/pools (Section 2).
3. **Case workflow engine:** the state machine in Section 7, including duplicate-report detection, conflict-of-interest checks, and the repeat-responsible-person pattern check (Section 3).
4. **Document generation:** Blade templates + Browsershot pipeline for Doc A (recommendations) and Doc B (warning/termination, shared template with variables) and the defense request letter. Test Arabic/Kurdish rendering FIRST before building more templates on top.
5. **Claude API integration:** all five touchpoints from Section 3b (drafting the defense letter, ingesting the response, drafting recommendations, verify+close+draft decision letter, side-notes). Any legal article cited within a draft must be constrained to the curated legal-reference tables (Section 5) — never free-form model knowledge — to avoid hallucinated article numbers.
6. **Dashboard & reports (full spec in Section 24):** per-company HR dashboard, **Platform Admin dashboard (full cross-company visibility + company account management — activate/deactivate, subscription status)**, HR partner cross-company dashboard (stats-only, narrower than Platform Admin), external legal reviewer dashboard, exports (PDF/Excel).
7. **CSV bulk employee import** (columns: name, employee_id, department, position, hire_date, branch, email [optional]).

## 10. Seed data

Use anonymized real data from the pilot company's actual Administrative Order (Al Waha, Hilla branch) as seed data instead of fake placeholders — it already has a real quorum scenario (5 HR alternates, 2 Legal alternates, 2 Labor Union alternates) to test the pool/quorum logic against from day one.

## 10b. Audit log — required, not optional

This system documents legal decisions. If a decision is ever disputed, "who did what and when" must be reconstructable with certainty. Build this from the first migration, not bolted on later.

- Every case gets a `case_events` log (see `docs/ERD.md`). Log at minimum: case opened, each AI draft generated (touchpoint + input summary), each human confirmation/edit, each document printed, each signature recorded (including presence-check results), status transitions, appeal logged.
- Separately, `ai_generation_logs` records every AI touchpoint call in detail (input given, output produced, model used) — this is both a debugging tool and a compliance record showing exactly what the AI was given and what it produced for any document.
- Both logs store: actor (`user_id`, nullable for system/AI-generated events), timestamp, event type, and a metadata JSON blob with type-specific details.
- The audit log is append-only — no update or delete on these rows, ever, even when a case is archived or a company cancels its subscription.
- This log is what lets a company answer "prove HR actually reviewed this before it was sent" if a case is ever appealed or challenged — treat it as seriously as the documents themselves.

## 10c. Security baseline

- **Encryption in transit:** HTTPS/TLS everywhere, including the VPS-to-VPS backup transfer (Section 24.4 in the PRD) — never sync backups over plain rsync/FTP.
- **Encryption at rest:** database and file storage should be encrypted at the disk/volume level at minimum (most VPS providers support this natively) — this matters more here than in a typical app because the data includes disciplinary records and salary info.
- **Passwords:** standard Laravel hashing (bcrypt/Argon2), never plain text or reversible encryption.
- **Access is role-scoped everywhere, not just at the UI layer.** Every query for case/document/employee data must go through the same `company_id` + role-scoping logic described in Section 5/8 and `docs/ERD.md`, enforced at the model/query layer (e.g., Laravel global scopes + policy classes) — never rely on the frontend alone to hide data a logged-in user's query could still fetch.
- **Backups are encrypted** before/during transfer to the off-site provider (Section 24.4), not just relying on the destination server's own security.
- **Auth hardening:** rate-limit login attempts, session timeout for internal users (especially external legal reviewer accounts, which span multiple tenant companies and are higher-value targets).
- **Uptime/failure monitoring:** given the hard 15-day legal deadline, silent downtime is a legal risk, not just a technical inconvenience. A simple uptime check (e.g. a scheduled ping + alert to HR/the developer) and a backup-failure alert should exist from the first deployment, even if basic. Also add a business-level alert (not just infra): any case within 48 hours of its 15-day deadline with no decision issued should trigger a notification independent of the dashboard banner (Section 15 of the PRD), so it can't be missed if nobody happens to open the dashboard that day.

## 11. Explicitly out of scope for MVP

- External HRMS/Payroll integration or API surface (standalone product for now).
- Government/civil-service employees.
- Mobile app — Phase 1 is responsive web only; the Flutter employee app arrives with the platform (`docs/PLATFORM-PRD.md`).
- WhatsApp Business API (phase 2).
- Paper archive import (phase 3, optional per company).
- Cloud storage (AWS/GCP) — private VPS only, with off-site backup on a *different* provider/server.

## 12. Still open — do not guess, ask the product owner

- Revenue-share model with partners (irrelevant to this codebase, business-only).

## 13. Working With Claude Code — Daily Process Rules

These govern how Claude Code should work on this codebase, session to session. Adapted for a **solo developer building with Claude Code** — no CI/CD pipeline or staging environment expected at MVP stage, so some rules below are lighter-weight than a large-team version would be.

### Scope & sizing
- One task (one feature/file/component) per session. Don't ask to "build the whole system" — if a request is broad, propose a breakdown and wait for confirmation before writing code.
- Don't touch files outside the current task's scope.
- A change touching more than ~5 files: stop and ask whether it should be split.

### Read before writing
- Read at least one or two similar existing files before writing new code, and match their style/structure/naming.
- If existing patterns are inconsistent, ask which one to follow — don't silently pick one.

### Simple code wins
- Use Laravel's standard/idiomatic solution before inventing a custom one.
- 20 clear lines beat 5 clever ones.
- Don't introduce new abstractions (interfaces, factories, base classes) unless the same pattern already appears in 3+ places in the codebase.
- No speculative future-proofing — solve the problem in front of you.

### No silent dependencies
- Never run package installs (`composer require`, `npm install`, etc.) without asking first.
- Never enable a framework feature that changes project-wide behavior without asking.
- Don't touch central config files unless that's explicitly the task.

### Architecture is not yours to change
- Don't change folder structure, add new layers, or modify system-wide patterns without explicit permission.
- If a task seems to require an architecture change, stop and ask.
- Any real architectural decision → write an ADR (see `docs/adr/`).

### Documentation: less, clearer, focused on "why"
- Code comments explain **why**, not **what**. A better variable/function name usually replaces the need for a comment.
- Doc-comments only on public interfaces (services, controllers, endpoints) — skip them on small private functions.
- A non-obvious or deliberate deviation from the "obvious" approach → one line in `WEIRDNESS.md`, not a long inline comment.

### Delivery format for every task
End each task with: a one-paragraph summary of what changed and why; the list of affected files; any new dependencies/env vars/migrations (or "none"); any manual steps required from the developer (or "none").

### Security & data invariants (non-negotiable)
- Never log or expose secrets, tokens, passwords, or employee personal data.
- Never disable CSRF, auth, or the `company_id` tenant-scoping middleware in committed code.
- Treat every piece of user input as hostile by default.
- Never touch the production server without explicit authorization.

### When in doubt, ask
- Ambiguous task → ask before writing 200 lines on a guess.
- Two reasonable approaches → present both with tradeoffs and let the developer decide.

### Governance files in this repo
- `WEIRDNESS.md` — running log of deliberate non-obvious decisions (seeded with the first few from this project's design phase — see the file).
- `docs/adr/` — Architecture Decision Records for the big, hard-to-reverse calls (multi-tenancy model, committee pool design, AI's role boundary, PDF rendering choice — backfilled for the major decisions made during design, see `docs/adr/README.md`).

## 14. Git workflow (lightweight — solo developer)

- `main` is always deployable. No direct commits to `main` — even working alone, work on a feature branch (`feature/short-name`) and merge via PR, so there's always a reviewable diff and a point to revert to.
- One PR per task (matches Section 13's "one task per session" rule) — small, reviewable diffs, not one giant PR per week.
- Commit messages: one line, present tense, says what changed (`Add committee quorum validation`, not `fixes`).
- No `staging` environment for MVP (Section 13 note) — test locally, merge to `main`, deploy manually to the VPS. Revisit once there's a live paying client and downtime actually costs something.

## 15. Coding standards

- Follow Laravel's own conventions (naming, folder structure, Eloquent patterns) — don't invent a house style where Laravel already has one.
- PSR-12 formatting, enforced via **Laravel Pint** (ships with Laravel, zero-config) — run it before every commit.
- Type-hint everything reasonable (method params, return types) — catches mistakes early, especially valuable with AI-generated code.

## 16. Testing priorities (test the legally load-bearing logic first, not everything)

Per Section 13's "simple code wins" philosophy, don't chase 100% coverage. Write tests first/only for the paths where a bug has real legal or cross-tenant consequences:

1. **Multi-tenant isolation** — a user from Company A can never fetch Company B's data through any route, not just the ones the UI exposes.
2. **The 15-day / 3-day deadline math** — anchored correctly to `defense_letter_issued_at` (ADR/WEIRDNESS note), computed correctly across edge cases (e.g. month boundaries).
3. **Committee quorum enforcement** — a case cannot move past `committee_session_held` with fewer than 3 attendees or without a labor-union-role attendee present.
4. **Document immutability** — an issued (`is_locked = true`) document can never be UPDATEd; corrections must create a new version row.
5. **The automated citation validator** (Section 3c, Layer 1) — correctly flags a non-existent article number, and correctly passes a real one.
6. **The three-approval gate on the recommendations document** — it cannot be issued with fewer than three approvals, cannot be issued if any of the three role categories is missing, and an HR edit after partial approval resets those approvals.
7. **Signature-presence check messaging** — confirm the UI/API never phrases a presence-check result as identity verification (Section 3, Section 3c).

Everything else (typical CRUD, standard Livewire components) can rely on manual testing at this stage — write automated tests for those only once they've broken in practice, not preemptively.

## 17. Environment variables checklist (for `.env`, before Session 1)

Have these ready before the first migration/model session — Claude Code will assume they exist:

```
APP_KEY=                    # generated by `php artisan key:generate`
DB_CONNECTION=mysql
DB_DATABASE= / DB_USERNAME= / DB_PASSWORD=
ANTHROPIC_API_KEY=          # for Claude API — needed from Session 5, not before
QUEUE_CONNECTION=database   # Section 20
MAIL_MAILER=smtp ...        # for defense-letter-ready/case-update alert emails
FILESYSTEM_DISK=local       # NOT s3 — see ADR 0006
# WHATSAPP_BUSINESS_* — not needed yet, Phase 2 (Section 11)
```

## 18. Visual design direction (confirmed — see `docs/design/dashboard-mockup.html`)

A working HTML mockup exists at `docs/design/dashboard-mockup.html` — Claude Code should open/read it before building any Livewire view, and match its look exactly (colors, spacing, component shapes), not just the general idea.

**Design tokens:**
```css
--ink:        #111827   /* primary text */
--bg:         #F7F8FB   /* page background */
--primary:    #4F46E5   /* indigo — primary actions, active states */
--primary-light: #EEF0FE
--amber:      #F59E0B   /* pending/awaiting-signature status */
--amber-light: #FEF6E6
--red:        #EF4444   /* urgent/deadline-approaching status */
--red-light:  #FEECEC
--green:      #10B981   /* closed/confirmed status */
--green-light: #E9FBF3
```

- **UI font:** Tajawal (Google Fonts) for all interface chrome — labels, buttons, tables, nav.
- **Document-preview font:** Amiri, used *only* when displaying an actual legal document's text (defense letter/recommendations/decision letter previews) — a deliberate contrast: modern UI everywhere, traditional Naskh the moment a real legal document appears. Do not use Amiri for app chrome.
- **Data/reference font:** JetBrains Mono, for case IDs and similar reference numbers.
- **Case cards:** single-row horizontal layout — initials avatar circle (indigo-tinted), name + case ID + department inline, a 5-dot step indicator (filled dots = completed stages, collapsing the Section 7 state machine into 5 visual stages: opened → defense letter → response → committee session → decision), and a pill-shaped status badge on the far side. Closed cases render at `opacity-60` to visually recede below active ones.
- **Buttons:** rounded-2xl/3xl, indigo primary with a soft colored shadow (`shadow-indigo-200` style), not flat/sharp-cornered.
- **The document confirmation/issuance screen** (Section 3/24.3's mandatory preview gate) is the one screen that should feel structurally different from the rest of the app — the document preview sits in its own card with a light gray background and the Amiri font, clearly visually separated from the surrounding indigo/Tajawal UI chrome, reinforcing "this is the actual legal document, review it as such" before the confirm action.

An earlier, more formal navy/bronze "official seal" direction was explored and explicitly rejected as too dated — don't resurrect it without a new explicit request.

## 19. Platform-readiness rules for Phase 1 (cheap now, expensive later)

Build only the discipline module, but respect these so later modules (see `docs/PLATFORM-PRD.md`) don't force a rewrite:

- **Business logic in service classes, never inside Livewire components.** A Flutter employee app will later call the same logic over a Laravel Sanctum API. Livewire components should be thin: validate input, call a service, render.
- **`branches.jurisdiction`** (`federal_iraq` | `kurdistan_region`) — add the column now. The platform serves both, each with its own legal rules and holiday calendar. The `legal_articles` table should also carry a jurisdiction, so federal Law 37 articles are never cited for a Kurdistan branch.
- **Discipline deadlines stay calendar days** (Section 3). A public-holiday calendar is coming later **for the leave module only** — don't let its existence leak into discipline deadline math.
- **`users.employee_id` stays nullable and one-to-one** — later every employee gets a user account; internal staff already have one.
- **Don't build a generic approval engine yet.** The platform will need one (configurable chains per company for leave, goals, resignation, etc.), but discipline's three-category approval is fixed by the Administrative Order. Build discipline's rule directly; extract a general engine when the second module actually needs it.

## 20. Technical pieces that must exist (confirmed gaps, review 2026-10)

- **Queues + scheduler.** PDF generation (Browsershot) and every Claude API call run as **queued jobs**, never inside a web request — they are slow and can fail. Use Laravel queues (database driver is enough at MVP; Redis later) with a supervised worker on the VPS. Deadline alerts (15-day, 3-day, appeal window, pending consultation) run from the **Laravel scheduler** (one cron entry). Without these, alerts never fire.
- **Handwritten Arabic responses.** The employee's defense is written by hand on paper. AI reading of handwritten Arabic is error-prone. Treat the AI's reading as a **draft transcription that HR checks against the scan** before it is used as context; always keep the original scan as the record, never the transcription.
- **Data leaving the server.** Files stay on the private VPS, but every AI touchpoint sends case text to the Claude API, which is outside Iraq. So the sales claim must be "your files are stored on a private server", **not** "your data never leaves". Minimise what is sent: never send national ID numbers, salaries or phone numbers to the AI; send only what the draft needs. Check Anthropic's current commercial terms on data retention before launch and put the AI processing in the Data Processing Agreement.
- **Known packages to use rather than hand-roll:** `spatie/laravel-permission` (roles), `spatie/laravel-backup` (encrypted off-site backups), `spatie/browsershot` (PDF). The audit log stays a custom append-only table (Section 10b) — don't use a package that allows edits/deletes.
- **Server needs** Node + Chromium installed for Browsershot, and a process supervisor for the queue worker.
- **Not Supabase.** Considered and rejected (ADR 0008): Laravel already provides auth, storage, queues and the API layer; Supabase would duplicate them, and its hosted version is foreign cloud (contradicts ADR 0006).

## 21. Solo developer + hosting (confirmed 2026-10)

- **Built by one person with Claude Code**, no second developer. That makes Section 16's tests the only safety net for the legally load-bearing logic — write them, run them before every merge. Rule for the developer: don't merge code you can't explain; if a diff is unclear, ask Claude Code to explain it before merging.
- **Phase 1 is web only.** Responsive Livewire pages, usable from a phone's browser. No mobile app in this phase.
- **Hosting (confirmed): a plain VPS (Hetzner CX23 class), set up entirely by Claude Code over SSH — no Forge/Ploi panel.** Not shared hosting: the app needs Node + Chromium (Browsershot), a supervised queue worker, a scheduler cron entry and SSH. Requirements for that setup: (1) everything goes in a **provisioning script kept in the repo** (`deploy/provision.sh` or similar) so the server can be rebuilt from scratch with one command; (2) automatic security updates on; (3) a free external uptime monitor; (4) encrypted daily backup to a different provider. During development nothing is hosted — run locally. The developer's own part: creating the provider account, paying, adding the SSH key, and DNS.

## 22. Content the developer must supply before Session 5 (not code)

- **The legal library import (Section 23).** The three law PDFs are in `docs/laws/`. Running the import and clearing the extraction-mismatch queue (human compares flagged articles to the page image) must be done before Session 5 — until then, no article is citable.
- **The pilot company's letterhead, logo and its real templates** (already in `docs/` as samples) to match the generated documents against.

## 23. Legal library & citation integrity (confirmed 2026-10 — the most important correctness rule in the product)

**The rule: the AI never writes the text of a legal article.** It references an article by its database id; the system inserts the official text from the library. That makes "article number X with the text of a different article" structurally impossible.

### 23.1 The platform's built-in laws (shipped with the platform, always present)
Source PDFs in `docs/laws/`:

| Law | File | Jurisdiction | Note |
|---|---|---|---|
| Labor Law No. 37 of 2015 | `labor-law-37-2015-gazette-4386.pdf` (official Gazette 4386, 9/11/2015) | federal_iraq | **Text layer is broken** (custom font encoding) — `pdftotext` returns garbage. Must be read from page images. |
| Labor Law No. 71 of 1987 | `labor-law-71-1987.pdf` | kurdistan_region | **Confirmed still in force in the Kurdistan Region.** Text layer works (Arabic presentation forms — normalise with Unicode NFKC). Store laws with `effective_from`/`repealed_at` so a future replacement can be added without touching old cases. |
| Retirement & Social Security Law No. 18 of 2023 | `gazette-4734-incl-social-security-law-18-2023.pdf` (Gazette 4734, 28/8/2023) | federal_iraq + kurdistan_region (default) | The Gazette issue contains **other laws too** — import only the pages of Law 18/2023. Text layer works but has letter-spacing artifacts. Applied to **both** jurisdictions by default; applicability to Kurdistan is unconfirmed, so it is a Platform Admin setting that can be changed later without code. |

### 23.1b Who can change the built-in laws
- **Only the Platform Admin** (the platform owner) manages statutes: upload a new law or a new version, mark a law repealed, set which jurisdictions it applies to, and fix an extracted text in the mismatch queue. **Companies can never edit statutes** — they only manage their own internal regulation (23.4).
- Law-to-jurisdiction applicability is a many-to-many setting (`law_jurisdictions`), not a fixed column, so a law can apply to one region, the other, or both.
- **Text corrections are versioned, never overwritten.** Fixing a unit creates a new version; `citations` point to the exact version used. Already-issued documents keep the text they printed (they are immutable anyway) and the audit log shows who changed the library and when.

### 23.2 Import pipeline (built-in laws and uploaded internal regulations)
1. Split into atomic units: law → article → clause (أولاً/ثانياً… and أ/ب…). Each unit stores: `law_id`, `article_number`, `clause_path` (e.g. `43/ثانياً/ز`), exact text, source file, **page number**.
2. **Two independent extractions per unit**, compared after normalising (NFKC, unify Arabic-Indic and Western digits, strip tatweel/diacritics/whitespace): (a) the PDF text layer where it works, else OCR; (b) AI vision reading of the page image.
3. Identical → `verified_auto`. Different → goes to a **mismatch queue**; a human compares against the page image (shown side by side) and fixes it → `verified_human`. The purpose is extraction accuracy, not legal opinion — the law text itself is official.
4. Only `verified_*` units are citable. Nothing else can be cited, ever.
5. Every unit keeps its page number so any citation in the UI links to the exact page of the original.

### 23.3 Drafting and the double check
1. **Retrieval first:** before drafting, the system picks candidate units (law of the case's branch jurisdiction + that company's verified internal regulation) by violation type/keywords, and passes ONLY those to the AI, each with its id.
2. **Structured output:** the AI returns the letter text with citation markers (`[[cite:<unit_id>]]`), never typed article text. Output not in this format is rejected and retried.
3. **Check 1 (code):** every marker's id exists, is verified, belongs to the case's jurisdiction or that company's regulation. Then the system renders "المادة (X) البند (Y) من قانون…" and, where the template quotes, the official text. **Any article number in the AI's prose that is not a marker is a hard error** (regex over the draft for patterns like المادة/مادة + number).
4. **Check 2 (independent AI pass):** a separate call that did not write the draft receives each sentence + the cited unit's official text and answers, per citation: supports / does not support / partially, with a one-line reason. "Does not support" blocks confirmation. This catches the remaining failure: a correct article that does not apply to the claim.
5. Then human review (Section 3). Overrides of any flag are logged.

### 23.4 Internal regulation vs. labour law
- Uploaded as text, Word (.docx) or PDF (text or scanned) — all must be readable; same import pipeline (23.2).
- **Approved by the company's own lawyer**, through the platform: a company user with the `company_legal_reviewer` permission reviews the split articles and approves them. Unapproved regulation articles are not citable.
- **When drafting, the law comes first.** For the case's topic the system shows the matching law unit and regulation unit side by side **before** drafting:
  - Consistent → draft normally, citing both if useful.
  - Conflict → **the law is applied** and a visible note states the conflict (both texts quoted, ids recorded). Exception to note, not to decide: where the regulation gives the worker *more* than the law (e.g. extra leave), that is not a conflict — the platform already allows companies to exceed the legal minimum (`docs/PLATFORM-PRD.md` 3.3).
- Conflict detection is an AI suggestion shown to humans, never an automatic legal ruling.

### 23.5 Company onboarding (first-run, required before the first case)
Company name, logo and letterhead details (fixed per company), branches and **each branch's jurisdiction** (federal Iraq / Kurdistan Region), the committee Administrative Order, enabled languages, and **"Do you have a Ministry-certified internal regulation?"** → if yes, upload + route to the company's lawyer for approval. Jurisdiction can change later; **existing cases keep the law version they were opened under** (store `law_version_id` on the case).

## 24. Dashboard, employee page and pattern detection (confirmed 2026-10)

The goal is control and understanding: not just tracking open cases, but seeing where problems repeat.

### 24.1 HR dashboard (per company, filterable by branch)
- **Live cases** with their stage (5-dot indicator, Section 18) and deadline alerts on top: 15-day, 3-day response, pending external consultation, appeal window.
- **Counters:** open, closed this month, by outcome (recommendation only / warning / termination), average days to close, % closed within the 15-day deadline.
- **Hotspots** — where problems repeat, each a ranked list with counts over a chosen period (default last 6 months), with a trend vs. the previous period:
  - by **branch / site (معمل)**
  - by **department**
  - by **location inside the site** (production line, warehouse, gate…)
  - by **violation type**
  - by **alleged responsible person** (Section 3 pattern rule)
  - by **employee** (repeat offenders)
- A hotspot crossing a threshold (default: 3+ cases of the same violation type in the same department or location within 90 days; configurable per company) raises a dashboard alert — **visibility only**, never an automatic case or action.
- Every number is clickable down to the list of cases behind it.

### 24.2 Reports
Monthly/quarterly summary, per-employee report, compliance report (deadlines met vs. missed), hotspot report. All exportable as PDF and Excel. All respect the viewer's role scope (Section 8).

### 24.3 Employee page
- Current status: an open case and its stage, if any.
- **Case count and full history:** every prior case with date, violation type, outcome, and links to its issued documents.
- Counters at the top: total cases, warnings, cases in the last 12 months.

### 24.4 Prior cases inside the new case
- When a case is opened, the system **links** all of the employee's prior cases to it (`case_links`, type `prior_record`) and shows them in the banner (Section 3).
- Prior cases are passed to the AI as context at touchpoints 1 and 3. **In the recommendations (Doc A)** the AI adds a short "السوابق" paragraph listing the prior cases by date, type and outcome — taken from the database, never from the AI's own wording of numbers or dates. The defense letter mentions prior cases **only if HR ticks "include prior record"**.
- A case related to another (the same incident, or a follow-up of an earlier case) can be linked manually with type `related`; it is shown and referenced the same way.

### 24.5 Structured locations (so hotspots work)
Free-text places can't be grouped ("خط 3" vs "الخط الثالث"). Each branch has a **`locations` list** managed by HR (production lines, warehouses, offices…). The report form and the case form pick a location from that list; free text is only a fallback, and HR can map a free-text entry to a list location later.
