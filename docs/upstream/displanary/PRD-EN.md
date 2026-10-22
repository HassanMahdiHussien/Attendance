# Product Requirements Document (PRD)
## Disciplinary Process Automation Service for Companies — Iraq

**Version:** 0.1 (Draft)
**Date:** August 2026

---

## 1. Overview

A SaaS service that helps HR departments at Iraqi companies manage the disciplinary process against an employee from start (defense request letter) to finish (Disciplinary Committee decision), in an automated way, built on Iraqi Labor Law No. 37 of 2015, with support/oversight from a legal partner (law firm) and an HR partner.

**Core idea:** Turn a slow, error-prone, manual/paper process — exposed to legal appeals — into an organized digital workflow that saves time, reduces legal risk for the company, and documents every step.

---

## 2. Problem Statement

- HR departments at companies (especially medium and large ones) handle disciplinary cases regularly, but usually:
  - Write defense letters and warnings manually, without a consistent, legally accurate template.
  - Don't precisely follow the procedural requirements the law demands (e.g. the 30-day warning period, the 30-day right to appeal before the Service Termination Committee or Labor Court).
  - Keep disorganized documentation of the case (statements, employee responses, committee deliberations), which weakens the company's position if the decision is appealed.
  - Sometimes make the final decision without a clear legal basis, exposing the company to wrongful-termination claims.

---

## 3. Goals

1. Reduce the time needed to complete a full disciplinary procedure (from warning to decision).
2. Reduce the rate of successful appeals against the company due to procedural errors.
3. Provide a reliable legal basis (backed by a real legal partner) for every decision.
4. Build a distribution channel through partners (law firm + HR company) instead of cold direct sales.

### Non-goals (for this phase)
- The service **does not** make the final decision on the company's behalf — it is a decision-support tool; signing/approval remains with the human Disciplinary Committee and the legal reviewer.
- This version does not cover public-sector employees (Civil Service Law differs from Labor Law No. 37).

---

## 4. Users & Personas

| Role | Description | What they need from the system |
|---|---|---|
| HR Officer (Admin) | Opens the case, documents the violation | Case creation, automatic defense-request letter generation |
| The employee concerned | Receives the letter, submits their response | Simple defense-submission form (text/attachments) |
| Disciplinary Committee (3 fixed members: internal Legal as chair, HR, Union representative) | Reviews the case and always signs the recommendations | Case summary, AI alert, decision options |
| External Legal Reviewer (from your company, via the partner law firm) | **Additional consultation after the committee signs**, only for sensitive cases (e.g. termination) — not a standing committee member | Dashboard showing only cases assigned to them for consultation, approve/edit, sign |
| HR Partner | Sells the service to its clients, oversees onboarding | Multi-client (multi-tenant) management dashboard |

---

## 5. Process Flow (User Flow / Process Map)

1. **Violation logging:** HR officer enters the violation details (date, description, relevant legal article if known).
2. **Defense request letter generation:** The system generates a formal letter (legally-approved template) describing the violation and the legal response deadline.
3. **Employee response:** The employee submits their defense via a link/form (text, attachments, witness names).
4. **Disciplinary Committee session:** The session minutes are recorded (witnesses, expert opinion if any, additions from the employee).
5. **AI drafts the defense request letter:** Based on violation details HR enters (place, time, type, responsible person), the AI drafts the **complete legal text** of the defense request letter. The committee reviews and confirms before printing. (Full detail in Section 26.)
6. **External legal reviewer consultation (sensitive cases only):** After the Disciplinary Committee signs its recommendations, if the case is "sensitive" (e.g. termination), it is automatically sent **as a consultation request** to the external legal reviewer (from the partner law firm, via your company) before the final decision letter is issued to the employee. Non-sensitive cases (recommendations/warning) skip this step and are issued directly by the committee. ("Sensitive case" criteria to be defined later — see Section 12.)
7. **Final decision and documentation:** The committee approves the decision (by vote/signature of its members — see Section 16), the system generates the official **paper** decision letter, and archives the full case file (usable as evidence if appealed).

---

## 6. MVP Scope

**In scope:**
- Defense request letter generation (templates by violation type).
- Employee response intake form.
- Committee session minutes documentation (text + attachments).
- AI drafting + alert engine (Claude API) — drafts the complete legal text for every document (defense letter, recommendations, decision letter) from facts/decisions entered by humans only, and cites legal articles from a curated database. **Never invents facts or makes a decision** — see Section 26 for the full five-touchpoint design.
- Review and approval portal for the legal reviewer.
- Final decision letter generation and full case archiving.
- Simple dashboard for the HR partner to manage multiple clients (companies).

**Out of scope for v1:**
- Direct integration with external HRMS/Payroll systems.
- Support for public-sector employees.
- Generating a "final" decision without human review.
- A standalone mobile app (a responsive web interface is sufficient).

---

## 7. Legal & Compliance Requirements

- Every letter and form must be drafted and reviewed by the legal partner before launch, and updated whenever labor law or Ministry of Labor instructions change.
- The system must clearly state to the user (in the UI and the contract) that the AI **drafts legal text from human-supplied facts/decisions only**, and never invents facts or makes a decision — the decision is entirely human, and a human confirmation is always required before any issuance (see Section 26).
- The warning period and right to appeal (30 days), **and the 15-day deadline for completing procedures from the date the committee is notified (Section 22.1)**, must appear automatically on every relevant letter/dashboard, to reduce the risk of procedural errors.
- A clear privacy and data-protection policy for employee data (storage, who has access, retention period).
- Contractual clarity on liability: professional responsibility for the accuracy of legal content lies with the partner legal reviewer, while the developing company provides the tool and technical infrastructure.

---

## 8. Partnership & Revenue Model (points requiring a decision)

- [ ] Partnership structure with the law firm and HR company: equity / per-case commission / shared subscription?
- [ ] Exclusive partnership, or an open platform for multiple law firms?
- [ ] End-client pricing model: fixed monthly subscription, per-case payment, or a hybrid (base subscription + per-case fee)?
- [ ] Who owns the long-term end-client relationship?

---

## 9. Success Metrics

- Number of companies onboarded through the HR partner within the first 6 months.
- Average time to complete a full disciplinary case (before/after using the system).
- Percentage of cases where the legal reviewer approved the committee's decision without material changes (an indirect indicator of the quality of the AI's alerts and legal references).
- Number of legal appeals against decisions issued through the system (ideally zero or very low).

---

## 10. Key Risks

| Risk | Mitigation |
|---|---|
| The system relying on a faulty suggestion that leads to a wrongful-termination claim | Mandatory legal review before any final decision + clear contractual language on liability |
| Slow digital-transformation adoption by companies in this space | Sell through a trusted HR partner instead of cold direct sales |
| Changing interpretations of labor law or legislative amendments | Periodic review (every 3-6 months) of templates and rules by the legal partner |
| Small target market (disciplinary cases aren't a daily occurrence) | Hybrid pricing model (low base subscription + per-case fees) instead of a high fixed subscription |

---

## 11. Roadmap

**Phase 1 (0-3 months):** Build the full MVP per this document — letter generation, the AI alert engine (Claude API, purely advisory role), multi-branch support, the core dashboard, legal reviewer consultation for sensitive cases — and test it with 2-3 companies through the HR partner.

**Phase 2 (3-6 months):** Improve AI alert accuracy based on real pilot case data, add WhatsApp Business API, fully activate the appeal-window tracking, expand the HR partner's dashboard.

**Phase 3 (6-12 months):** Import legacy paper archives (optional per company), expand the client base through additional partnerships, evaluate the need for stronger data isolation (a separate database per company) if the client count grows.

---

## 12. Open Points Requiring a Decision Before Development

1. Structure and split of the partnership with the law firm and HR company.
2. Final pricing model.
3. Is the system built as a standalone product, or as a module integrated later into existing HR systems?
4. Which pilot companies will test the first version?

---

## 13. Proposed Technical Architecture (Technical Stack)

Based on: (technical partner's strongest skill is PHP, expected size of fewer than 10 companies in year one, multi-client system from day one, cloud file storage, Arabic/English support, AI-based alert engine):

| Layer | Recommendation | Rationale |
|---|---|---|
| **Backend / Framework** | **Laravel** (PHP) | The most mature PHP framework for multi-tenant projects; has ready-made tools for permissions, templating, billing (later), and Arabic/English i18n. Directly leverages your partner's expertise. |
| **Database** | **MySQL** (or PostgreSQL if the partner is comfortable with it) | More than sufficient for fewer than 10 companies in year one. Multi-tenancy is implemented in its simplest form: a `company_id` column on every table (shared schema), instead of a separate database per company — cheaper and easier to maintain at this stage, upgradeable later to stronger isolation if the client count grows. |
| **File storage** | **Local storage on the same private VPS** (dedicated folder + periodic off-site backup) | Your decision: a fully private server instead of relying on a foreign cloud provider. Laravel supports this directly (local disk driver via `flysystem`). **Important:** an automatic daily backup schedule to a separate location is required (even if locally encrypted), because losing a single VPS without a backup means losing all companies' legal evidence. |
| **AI drafting + alert engine** | **Claude API (Anthropic)** | Drafts the complete legal text for every document (defense letter, recommendations, decision letter) from human-supplied facts/decisions, and cites legal articles from a curated database — **never invents facts or makes a decision**, always followed by human confirmation before issuance (see Section 26), with strong support for legal Arabic. |
| **Notifications** | **Email (SMTP) + WhatsApp Business API** | Alerts only ("you have an update in the system, go check it") — **not a formal delivery method**. Formal delivery remains paper-based (see Section 13.2). |
| **Hosting** | A single private VPS to start (e.g. Contabo/Hetzner/local Iraqi provider) | Matches your decision on private hosting. Once the client count grows past ~15-20 companies, an evaluation for upgraded specs or a stronger server will be needed. |

### 13.1 The AI's Role — Drafts From Human Facts/Decisions, Never Invents Them (updated — see Section 26)
**This section was substantially updated after further discussion — see Section 26 for the final complete design with five specific touchpoints.** Quick summary: the AI drafts the complete legal text (defense letter, recommendations, decision letter), but the **facts** (place/time/violation type) and the **decision** (what the committee decided) always come from human input first — the AI never determines what happened or what the outcome is, it only turns them into professional legal drafting. Human review and confirmation is mandatory before any printing or issuance.

### 13.2 Official Notification — always paper-based
Digital notifications (email/WhatsApp) serve only to **alert** the employee that a document is ready for them to view in the system. **Formal legal delivery** (the defense request letter, the warning, the decision) remains **paper-based**:
1. The system generates the document as a print-ready PDF.
2. HR prints it and hands it to the employee in person, who signs a receipt copy.
3. The signed receipt copy is scanned and uploaded to the system as documented proof of formal delivery in the case file.
This preserves the legal strength of the notification compared to relying solely on a WhatsApp "delivered" status.

### Note on Multi-tenancy
Given your expected size (fewer than 10 companies in year one), you **do not need** a complex data-isolation architecture (a separate database per company). A `company_id` column on every core table (employees, cases, letters) plus enforcing it on every query is sufficient. This is faster and cheaper to develop, and can be upgraded later if a large client requires full data isolation.

---

## 14. Employee Data Model — the basis for AI alerts

Since you want a database per employee that becomes part of their record and is used as a reference for AI alerts on future cases, the proposed core fields are:

- **Core data:** name, employee ID, department, job title, hire date.
- **Violation history:** every prior case linked to the employee — its type, date, the decision issued (recommendation/warning/termination), appeal status if any.
- **Current status:** any currently open disciplinary case, linked directly to their file.
- **Reference for AI alerts:** when analyzing a new case, the system automatically sends the employee's prior violation history (as context) to the AI engine, so the alert is grounded in precedent (e.g., "this is the third violation of this type within a year" changes the picture from warning to termination, to the extent the law allows).
- **Future case linkage:** if a legal appeal occurs, the employee's complete file (all cases + decisions + evidence) is ready as documented evidence for the partner legal reviewer.

**Privacy note:** since this record contains sensitive data (salary, violations, evaluations), precise permissions are required — who sees the full record (HR Admin, the legal reviewer) versus who sees only a summary (the Disciplinary Committee, for the specific case) — so no party ever sees employee data or cases outside their authorization.

---

## 15. Dashboard & Reporting

A core element of v1 — every company (tenant) needs a dashboard showing the status of its disciplinary procedures at a glance.

### 15.1 HR Dashboard (per company)
- **Live case tracking:** a list of all currently open cases with their stage (letter sent / awaiting employee response / awaiting committee session / awaiting legal review / decision issued), with an indicator for any case that has exceeded a legal deadline (e.g. a late employee response).
- **Quick KPIs:** number of open cases, number closed this month, number of cases by outcome (recommendations only / warning / termination), average case-closing time.
- **Alerts:** cases nearing a legal deadline (e.g. 3 days left on the employee's response window), cases awaiting legal review for more than X days.
- **Filtering and search:** by department, violation type, status, time period, employee.

### 15.2 Reports
- **Periodic report (monthly/quarterly):** case count, breakdown by type/department/outcome, average processing time.
- **Employee report:** the full disciplinary history for a given employee (also usable as legal evidence in an appeal).
- **Compliance report:** cases that met legal deadlines versus those that didn't — an important indicator for reducing appeal risk.
- **Export:** ability to export any report as PDF or Excel to attach to official company files or submit to the Labor Court when needed.

### 15.3 HR Partner Dashboard (across all companies)
- An overview of all companies subscribed through them: number of active companies, total cases across all of them, which company needs follow-up (low activity, delayed cases).
- Useful later as a sales/account-management tool for the HR partner.

### 15.4 Partner Law Firm Dashboard
- A list of sensitive cases awaiting their review across all companies, sorted by priority/deadline.

---

## 16. Detailed Roles & Permissions

Each company (tenant) has multiple users with different access levels:

| Role | Sees what | Notes |
|---|---|---|
| **HR Manager (Company Admin)** | All of the company's cases, without exception | Can manage users and assign permissions |
| **HR Staff** | By default: **only cases assigned to or created by them** — expandable to "see all" per the company's settings | Configurable per user by the Company Admin |
| **Disciplinary Committee member (general)** | Only cases they're assigned to as a committee member | Can have a full system account, or access via a temporary **invite link** for the specific case (no permanent account) — useful for occasional committee members from outside HR |
| **Committee member — Legal (in-house counsel or law firm delegate)** | Only cases assigned to them for review (sensitive ones) | Same link/account principle |
| **Partner Legal Reviewer (law firm)** | All sensitive cases across every company they're subscribed to | Unified dashboard (Section 15.4) |
| **HR Partner** | An overview across all companies (statistics, not full case details unless the company allows it) | Needs a decision: do they see full case details, or just status/counts? (a point requiring your decision) |

**Link-based access:** for a committee member without a permanent system account — they're sent a unique, time-limited link that takes them directly to the specific case file only, without a full login. Useful for external or occasional committee members.

---

## 17. Proposed Pricing for the Iraqi Market (initial proposal)

⚠️ This is an initial proposal for discussion, not a final recommendation — it should be tested with 2-3 companies during the pilot phase before being finalized, because price sensitivity in the Iraqi market and the nature of each sector (banks vs. contracting companies, for example) differ.

**Proposed model: low base subscription + per-case fee** (this fits the nature of the service, since disciplinary cases aren't a daily occurrence, making a high fixed subscription hard to justify to a company):

| Tier | Base monthly price | Includes | Additional per-case fee |
|---|---|---|---|
| Small company (up to ~50 employees) | A low nominal amount (covers hosting and support) | One or two cases/month included in the subscription | Flat fee per additional case |
| Medium company (~50-200 employees) | Slightly higher subscription | A larger implied case allowance | Lower fee per additional case (volume discount) |
| Large company (200+) | Custom negotiated pricing | Per agreement | Per agreement |

**Why this specific model for Iraq:**
- Iraqi companies are generally sensitive to a "fixed subscription" for a service they use intermittently — tying part of the price to actual usage (per case) eases the purchase decision and reduces resistance ("why pay every month when I have no cases?").
- The per-case fee lets you compare directly against the company's current alternative (hourly legal consultation per disciplinary case) — you can typically be cheaper, faster, and better documented.
- During the pilot phase (first 2-3 companies), I'd suggest making the subscription **free or purely nominal** in exchange for feedback and case studies you can use in later sales — more valuable than profit at this stage.
- Revenue-sharing with the HR partner and the law firm (a percentage of every subscription/case they bring in) is still an open point (Section 8) — best resolved after you have real pricing numbers from actual conversations with target companies, not guesswork.

**Disclaimer:** this is not formal financial advice — I'm not a pricing expert or a financial advisor, this is a market-logic-based suggestion. The best way to validate it is to ask 3-5 target companies directly: "how much would you pay monthly for a service like this?" before locking in numbers.

---

## 18. Update Based on Real Templates Submitted by the Company

After reviewing real templates (defense request letter, disciplinary committee recommendations, written warning letter), the following points were confirmed/revised:

### 18.1 Two separate types of decision documents (critical for design)

| Type | Name | When issued | Who signs it | Does the employee see it? |
|---|---|---|---|---|
| **A) Disciplinary Committee Recommendations** | "Committee Recommendations" — internal record | **Always**, for every case without exception | The three members: Legal (**chair**), HR (member), Labor Union representative (member) | No — an internal file document |
| **B) Decision Letter to the Employee** | Warning Letter, or a **Termination Letter** (the exact same template, except: the stated penalty changes from "written warning" to "termination," and the cited legal article varies based on the type and severity of the violation) | **Only if the decision includes a penalty** (warning/termination) — if the recommendation is "training/awareness" only with no penalty (as in Maryam's case), **this letter is never issued** | **The General Manager + the Labor Union representative** (note: not the same signers as Document A) | Yes — and it includes the employee's acknowledgment of receipt and agreement |

This is a fundamental difference that must be reflected in the database: every case has a **mandatory Document A always**, and an **optional Document B** that's only triggered if the decision includes an actual penalty. The signers differ between the two documents (the Disciplinary Committee for Document A, senior management + the union for Document B) — meaning the system needs a separate "signatories" table per document type, distinct from the committee member list.

**Template design note:** since the warning and termination letters are the exact same form, treat it as **a single template with two variables**: (1) the penalty type (written warning / termination) controls the wording of "...decided to issue the penalty of X," (2) the cited legal article (chosen from the legal reference database — Section 21) is inserted into the reasoning sentence. This saves you from building two separate templates and reduces the risk of wording inconsistency between them.

### 18.2 Decision Letter (B) explicitly states acceptance/rejection of the defense
The real template states: "the Disciplinary Committee decided not to accept the defense submitted by you." Every decision letter must include an explicit field: **was the employee's defense accepted in full / in part / rejected entirely** — this is an important legal line that documents that the committee actually reviewed the defense before deciding (protects against an appeal claiming "my defense was never considered").

### 18.3 Basis for forming the committee (Administrative Order)
The "committee recommendations" document is based on an administrative order number and date (example: "Administrative Order No. 963-B dated 19/11/2024") establishing the committee's official formation. This field is stored **once at the company level** (or updated if the committee is reconstituted), and is automatically inserted into every recommendations document that company issues.

### 18.4 Employee database includes committee roles (updated: by branch/location)

Since you confirmed the company is **large and likely has more than one branch/location, with the committee changing by location**, this part needed to be redesigned:

- A **"Branches/Locations" table** is added per company — each branch has its own name and address.
- Every record in the employee table gets a **"assigned branch"** field.
- Committee role flags (`is_legal_committee_member`, `is_hr_committee_member`, `is_labor_union_rep`, `is_general_manager`) are **tied to the branch**, not the company as a whole — meaning the same person could be a committee member at one branch but not another, and (rarely) one person could be a member at more than one branch if the company assigns them that way.
- When a new case is opened for an employee, the system auto-fills the committee **from that specific employee's branch**, not from a company-wide list.
- `is_department_manager` (permission to open a new case) follows the same principle — a department manager is responsible for their own branch/department only, and can't see other branches' cases unless given broader access (e.g. a company-wide HR manager).

This lets the system auto-suggest/auto-fill committee names for any new case instead of manual entry each time, reduces the risk of forgetting a member's signature, and **respects the fact that committee composition differs from one location to another within the same company.**

### 18.5 Violation intake channel (recommendation)
You asked me to weigh in between: (a) keeping email as the intake channel, with the service converting it into a defense request, or (b) moving reporting fully into the system, with permission restricted to department managers.

**My recommendation: option (b) as the foundation, with (a) as a lightweight assist channel only:**
- Formal reporting happens from within the system, restricted to department managers (and possibly HR), via a structured form (violation type, date, description, witnesses, attachments). This ensures every case has structured data from the very first moment, usable later for AI alerts and reporting.
- Email remains an **optional lightweight intake channel** (forwarding): a department manager can forward an email to a dedicated address, and the system creates a **draft case** from it only — it doesn't become an actual case until HR reviews and completes it via the structured form. This reduces initial adoption friction (people are used to email) without sacrificing data quality.

### 18.6 Receiving the employee's response — a unified paper process
Confirmed: the employee's response is currently a "blank sheet of paper" they write on and hand in together with the defense request, signing both.
**Suggestion to increase professionalism without changing the substance of the process:** instead of a completely blank sheet, the system prints a "response form" with the company letterhead + the employee and case details pre-filled (only the response content is blank, for the employee to write by hand). This keeps every file uniformly formatted and easier to archive and scan later, without changing the fact that it's still "a paper they handwrite and sign."

**Two separate signature steps the system must track:**
1. Signature acknowledging receipt of the defense request letter itself (proof of delivery).
2. Signature acknowledging submission of the written response (proof of defense submission).
Each has its own status and date in the system, even if they happen in the same in-person session.

### 18.7 Refusal-to-sign scenario (confirmed)
Committee members or the direct manager sign as witnesses to "delivery occurred and the employee refused to sign" — this applies to **all three signature steps** (receiving the defense request, submitting the response, receiving the final decision letter), not just the last one.

### 18.8 Small quality note on the original template
The English version of the "defense request" that was uploaded has a leftover line from a previous version: "*Please provide us your written defense letter for this case until date 12 May 2026 time 11:00 AM*" — a date that contradicts the main deadline (June 21, 2026). This is a live example of why automation helps: an automated system prevents this kind of inconsistency because the date is computed once as a single field and inserted in one place, not manually copy-pasted from document to document.

---

## 19. Single Source of Truth — the Core of the Solution

This is the most important section in the document because it directly addresses the original problem the idea was built on: **repeatedly typing the same information manually into every document (employee name, hire date, department, job title, committee member names...) and the time and errors this causes.**

**Principle:** every piece of information is entered **once, in the right place**, and the system pulls it automatically into any document that needs it afterward. No field is ever typed manually twice for the same case.

| Information | Entered once, where? | Auto-pulled into |
|---|---|---|
| Employee name, hire date, department, position | The employee's record (when their profile is first created) | Every document for every case of theirs, for their entire tenure |
| Fixed committee members (Legal/HR/Union/GM) | Company settings (set once, only changes when the actual person changes) | Every "recommendations" and "decision" document the company issues |
| Administrative order number and date forming the committee | Company settings | Every recommendations document |
| Company letterhead (logo, address, phone, email) | Company settings | Every printed document |
| Violation details and date | Entered once when the case is opened | Defense request letter + recommendations document + decision letter |
| Legal deadlines (e.g. 3 days to respond, 30 days to appeal) | Auto-calculated from the case-opening date per a fixed system rule | Every place a date appears in every document (prevents date contradictions like the one noted in Section 18.8) |

**Practical result:** opening a new case = select the employee from a list (all their data auto-fills) + type the violation details once → the system generates a print-ready defense request letter within seconds, and every subsequent document for the same case is built on the same data without retyping anything.

---

## 20. Stress-Test Scenarios — challenging the document with real-world examples

I ran 8 possible scenarios against the current design to see where it breaks:

**Scenario 1 — the employee refuses to even receive the paper (not just refuses to sign):**
The current document (18.7) covers "received but refused to sign," but **does not cover "refused to receive it at all"** or "avoided delivery entirely." This is a legally different and riskier scenario (may require proof of a delivery attempt with witnesses at the workplace, or formal registered correspondence). ➜ **A real gap.** Added as a separate system state (Section 21).

**Scenario 2 — two reports from two different people about the same incident:**
If different supervisors submit two separate reports about the same event, the system as-is could open two duplicate cases. ➜ **A gap.** Needs a "link related reports to the same incident" mechanism when opening a new case (warning: "there's a similar report for the same employee on the same date, link them?").

**Scenario 3 — conflict of interest with a committee member:**
What if the union representative or the HR member is related to / a party in the same violation (e.g. they're the witness or the complaining supervisor themselves)? The current document has no replacement mechanism. ➜ **A gap.** Needs: when a case is opened, the system checks whether any of the fixed committee members is also listed as a witness in the same case, and if so warns HR and requires a temporary substitute member for that case specifically.

**Scenario 4 — a third violation of the same type (escalation):**
This is nominally covered (the employee's record is sent as context to the AI alert), but it needs to be **prominent and clearly visible at the top of the case screen**, not buried in the details — e.g. a red banner "⚠ this is the third violation of this type within 12 months." ➜ A UI improvement, not a fundamental gap.

**Scenario 5 — the employee is on leave (sick/annual) when the case is opened or during a deadline:**
Do legal deadline counters (e.g. the response window) pause until they return? **This is a purely legal question I can't determine on my own** — you need to ask your law firm partner specifically, because the answer changes the system's entire date-calculation logic. ➜ **Open question for the lawyer, necessary before development.**

**Scenario 6 — the company has multiple branches/locations:**
If the company has more than one production site (e.g. a Babil branch and another), does each branch have its own committee and its own department manager, or one unified company-wide committee? ➜ **An organizational question requiring your answer** (Section 22).

**Scenario 7 — the company's internal work regulation (ratified by the Ministry of Labor) versus the general labor law:**
Many Iraqi companies have an "internal work regulation" ratified by the Ministry of Labor that specifies more precise penalties than the general law. Should the AI reference each company's internal regulation (if one exists) in addition to the general labor law, or rely on the general law alone? ➜ **An important question — if the answer is "yes," every company needs to upload its internal regulation as an additional reference that constrains the AI specifically for that company (Section 22).**

**Scenario 8 — multi-tenant data seen by the legal reviewer/HR partner:**
Confirmed the current design (Section 16) clearly separates: the legal reviewer sees only sensitive cases, the HR partner sees statistics without full case details unless the company allows it. ➜ **No additional gap found**, the design is sound.

---

## 21. Workflow Updates Resulting from the Scenarios

- **New state: "Delivery Refused":** separate from "received but refused to sign." Requires: date of the delivery attempt, name of the person who attempted delivery, names of two witnesses confirming the attempt and the refusal, location of the attempt. This documentation matters even more than "refused to sign" documentation, because it's legally weaker and more exposed to appeal.
- **Linking duplicate reports:** when creating a new report, an automatic check: is there another report for the same employee within the last 48 hours? If so, suggest merging instead of opening an independent case.
- **Conflict of interest:** an automatic check when opening a case: is one of the fixed committee members also listed as a witness or party in the same report? Warn + require assigning a substitute.
- **Precedent-escalation banner:** any case involving a second or later violation of the same type within 12 months displays a clear warning banner at the top of the case page, visible to both the committee and the legal reviewer.

---

## 22. Received Legal/Organizational Answers — turned into system rules

### 22.1 The 15-day deadline for completing procedures (an important new business rule)
**Answer received:** from the date the committee is notified, procedures (from case opening to decision issuance) must be completed within **15 days** per law.

**How this is reflected in the system:**
- When a case is opened (the moment the committee is officially notified), a "15-day" counter starts automatically.
- This counter is **separate** from the employee's response window (e.g. 3 days per Riyadh's template) — the two deadlines run in parallel, and the 15-day counter is the overall "umbrella" within which the entire procedure must be completed.
- **Dashboard alert (Section 15):** any case that has exceeded, or is approaching, 15 days without a final decision appears with a red banner at the top of the priority list — this is the single most important alert in the entire system, since it touches the legal validity of the procedure if the company exceeds it.
- **Remaining question (needs later confirmation from your lawyer, not urgent now):** does the employee's leave pause this specific 15-day counter, or does it keep counting regardless? The answer received establishes the base rule (15 days) but doesn't settle the "pause during leave" question specifically — keep it in mind to confirm with the lawyer when you meet, no need to hold up the design over it now.

### 22.2 Internal work regulation — an additional reference per company (confirmed)
**Answer received:** if the company has an internal regulation ratified by the Ministry of Labor, it is relied upon. If not, the general Iraqi Labor Law is relied upon.

**How this is reflected in the system:**
- A field in each company's settings: **"Has a ratified internal work regulation?"** (Yes/No).
- If "Yes": the internal regulation file is uploaded as an onboarding step, and becomes an **additional reference scoped to that specific company only** for the AI alert (in addition to the general labor law, not instead of it).
- If "No": the AI relies on Iraqi Labor Law No. 37 of 2015 and its amendments only.
- This means the legal reference database (mentioned in Sections 18.1/13.1) becomes **two-tiered**: a general tier (labor law, for every company) + a private tier (internal regulation, if one exists, scoped to one specific company and never shown to another).

## 23. Confirmed: the company has multiple branches

**Answer received:** the company is likely large, and the committee changes per location (branch) — meaning committee members differ by branch.

This has been fully incorporated into Section 18.4 above (branch structure + tying committee roles to the branch instead of the company as a whole). **Important MVP decision:** since this adds real complexity (a branches table + composite permissions), it's best included in the core design from day one (not patched in later) — the technical cost of adding it now is far lower than restructuring after launch with a real multi-branch company.

**Point needing your clarification later (not urgent now):** is the "administrative order forming the committee" (Section 18.3) unified for the whole company, or does each branch have its own separate order forming its own committee? This determines whether that field is stored at the company level or the branch level.

---

## 24. Final Pre-Development Decisions (Pre-Dev Sign-off)

### 24.1 Administrative order forming the committee — a document with multiple versions over time
Uploaded once during onboarding. **If the company doesn't have a ready document**, the system generates a standard blank template for them, which they sign and upload scanned. **Must support change over time** (member turnover, etc.) — built as a `committee_mandates` table holding multiple versions per company: order number, its date, effective date, and superseded-until date (`superseded_at`). Every "recommendations" document issued automatically references **the version in effect on the date the case was opened**, not always the latest version — this preserves the historical archive's accuracy even if the committee changes later.

### 24.2 Appeal window (30 days) — manual, not automatic, tracking
The case enters a **"awaiting end of appeal window"** state for 30 days after the decision is issued. Since an appeal happens at an external court/body with no system integration, the company (HR) is the one who **manually logs** whether an appeal notice arrived during that window. If nothing is logged, the case is automatically archived as "final" once the 30 days pass. If an appeal is logged, the case stays open in an "appeal pending" state until its outcome is manually entered.

### 24.3 Document issuance confirmation — a warning that companies can make stricter
Since documents become non-editable after issuance (Section 20, immutability), the system always shows a **final preview screen** before issuance warning: "confirm the accuracy of names, dates, and letter numbers — this cannot be edited after issuance." By default this is **advisory only** (a single confirmation and proceed). But each company can enable a stricter mode in its settings: **"mandatory second review"** requiring a second person (other than the one who issued the document) to approve before it's finalized — an optional setting per company as they wish.

### 24.4 Backups — a completely different provider/server
Confirmed: periodic backups are stored on a **server with a different provider** than the primary VPS (not the same company, and preferably a different physical location), so that even a failure or breach on the primary server doesn't lose the legal evidence.

### 24.5 Arabic/Kurdish RTL PDF generation — technical recommendation

⚠️ You asked me to propose the right solution, and this is an important point that deserves attention from day one because fixing it later is costly:

**The problem:** traditional PHP PDF libraries (**DomPDF, mPDF**) have a long history of issues with Arabic — broken characters, incorrect number ordering in tables, connected-letter shaping issues, and even bigger problems with Kurdish (extra characters not present in most standard Arabic fonts).

**Recommendation:** use **Browsershot** (a Laravel package by Spatie) which runs an actual headless Chromium browser and converts a full HTML/CSS page into a PDF. Chromium is a real browser engine — it handles Arabic/Kurdish RTL, letter shaping, and mixed-direction (bidi) text correctly, because it's the same engine that renders everyday websites.

**How to build the documents in practice:**
1. Each document template (defense request, recommendations, warning...) is designed as an **HTML/CSS template** (a Laravel Blade template), with `dir="rtl"` for Arabic and `dir="ltr"` for English, each section on its own.
2. For fonts: use an Arabic font designed for official documents (**Amiri** or **Noto Naskh Arabic**), embedded directly in the template so it doesn't depend on server-installed fonts.
3. **For Kurdish specifically:** make sure the chosen font covers the extra Kurdish characters (Sorani in particular has characters not present in every standard Arabic font) — **Noto Sans Arabic**, or a dedicated Kurdish font available online (like Rabar or Sarchia), covers them fully. **This is a point your technical partner should test in the first week of development**, before building the rest of the system on top of it.
4. For bilingual documents (Arabic on one page + English on another, as in the original templates): build it as a single HTML document with a page-break between sections, and Browsershot exports them directly as a single multi-page PDF — no need to merge separate PDF files.

### 24.6 Updated roadmap (deferring what isn't critical)
Based on your decisions, the following items are **deferred to a later phase** (won't delay the MVP):
- **WhatsApp Business API:** added in a later phase after launch — email is sufficient to start.
- **Legacy paper archive import:** added as an optional feature in a later roadmap phase, not part of the MVP, so the project isn't delayed.
- **Partner revenue-sharing model:** resolved after the service is actually built, not a prerequisite for development.

### 24.7 Cancellation policy
When a company withdraws, they are given **a complete copy of all their files and documents** before their account is deleted. *(A simple additional suggestion: it's best to define a grace period — e.g. 30-60 days after cancellation before final deletion from the server — in case the company needs additional data shortly after withdrawing. This is a contract detail, not a technical one.)*

### 24.8 The employee has no login account (confirmed)
The employee involved in a case **has no account or system login whatsoever** — their entire interaction is paper-based (receiving, signing, submitting). This greatly simplifies the permissions model: the system only needs authentication for internal roles (HR, the committee, the legal reviewer, the HR partner), with no external portal for employees at all.

### 24.9 Registering the startup itself legally
A non-technical but essential point before the first real client (signing contracts, formal billing) — pending your follow-up on this with your law firm partner.

---

## 25. Final Review — 4 points that came up now, needed your clarification

### 25.1 ✅ Resolved: one committee, one unified name
Confirmed: the same committee, one name used everywhere in the system and documents: **"Disciplinary Committee."** All references throughout this document have been unified (they were previously split between "Disciplinary" and "Investigation" due to inconsistency in the company's original templates — it's worth flagging this to the company so they can unify their paper templates too, not just our system).

### 25.2 ✅ Resolved: two entirely separate legal roles
- **The committee's "Legal" member** (chairs the Disciplinary Committee on the recommendations document) — **an internal employee of the company itself**, always present, a fixed part of every case.
- **The External Legal Reviewer** — from **your company** (the service provider, via your partnership with the law firm), **consulted only when needed** (sensitive cases), not a standing committee member and doesn't attend its sessions.

**How this is reflected in the workflow (update to Section 5/6):** when a case is "sensitive," the internal committee signs its recommendations first as usual, and the case is then sent **as a consultation request** to the partner law firm (external to the company, via your company) before the final decision letter is issued to the employee. In other words, the external review happens **after** the internal committee signs, as an additional verification layer before final issuance — not a substitute for the internal legal committee member.

### 25.3 ✅ Resolved: the administrative order is at the company level
The administrative order forming the committee is issued **at the company level (the company as a whole, not per individual branch)**. The `committee_mandates` table remains tied to `company_id`. **Design note:** since the actual committee composition (the individual members) differs by branch (Section 18.4), a single administrative order can include, in its content, different member appointments per branch — meaning the document itself is company-level, but its **content** (member names) may be broken down by branch within the same document. This only requires: committee role flags (`is_legal_committee_member`, etc.) remain tied to the branch as agreed, but the administrative order's number/date itself is a single company-level field inserted into every document regardless of branch.

### 25.4 ✅ Resolved: a standalone product, expandable in the future
Confirmed: **a standalone product for now**, with no integration into external HR/Payroll systems at this stage. The intended future growth is **expanding the service itself to cover more things** (e.g. new features or additional HR-procedure types), **not turning it into a module embedded in a third-party product**. This means there's no need to design external API interfaces at this stage — full focus stays on the service itself as a complete product.

---

## 26. Final AI Role Design — Five Specific Touchpoints (supersedes/expands Section 13.1)

This section documents the final design after extended discussion. **Core principle:** the AI drafts complete, high-quality legal text, but **facts and decisions always come from a human first** — the AI never invents what happened or decides the penalty, it only turns human input into professional legal drafting. Human review and confirmation is mandatory before any printing.

### 26.1 The Five Touchpoints

1. **Draft the defense request letter:** Input = structured fields HR enters (place, time, violation type, responsible person) — either direct entry or from a forwarded email HR completes into these fields (a raw email is never auto-converted into a live case without HR confirmation — Section 18.5). Output = the complete legal-Arabic letter text. The committee reviews and confirms before printing.
2. **Ingest the employee's response:** Input = the scanned response after the paper hand-off. The AI reads it only to build case understanding used later — **it drafts nothing at this step**.
3. **Draft the committee recommendations (Doc A):** Input = a committee member's typed notes on what happened during the session and what was decided (MVP: typed notes only, audio recording deferred — see 26.2). Output = the complete "Committee Recommendations" document, built on the actual decision the member provided. The committee reviews, confirms, prints, and collects signatures.
4. **Verify, close, and draft the decision letter:** Input = the scanned, signed recommendations document. The AI (a) checks for the **presence** of a signature in each expected box (a presence check only, not identity verification — see 26.3), (b) confirms the case can be closed, (c) if the decision includes a penalty, automatically drafts **Doc B** (the decision letter) from the same recommendations content. Doc B goes through the same human-confirmation gate before printing.
5. **Side-channel notes:** At the two drafting touchpoints (1 and 3), the AI can attach internal notes (missing information, unclear points, things worth double-checking) visible to the committee/HR as a review aid — **never inserted into the official letter text itself**.

### 26.2 Confirmed decision: committee session minutes are typed, not recorded, in the MVP
The committee session record (touchpoint 3) is captured as typed notes from a committee member, not audio. Audio recording plus speech-to-text (requiring a separate service before the text reaches the Claude API, since the API takes text input at this touchpoint, not raw audio) is **deferred to a later phase**, not part of the MVP.

### 26.3 Important caveat: signature verification is a "presence check" only, not identity verification
When a scanned, signed document is uploaded, the AI (via vision) can detect whether a signature box has something written in it or is empty — this is straightforward and reliable. But it **cannot** reliably verify that a signature is "genuine" or matches a specific person — that kind of visual analysis isn't reliable enough for a legal determination. The UI must clearly say "this signature box appears empty — flagged" and **not** "signature verified as authentic" — a human remains responsible for actually confirming signer identity.
