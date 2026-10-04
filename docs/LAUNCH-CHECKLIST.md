# Launch Readiness Checklist

A single reference to confirm before onboarding the first real paying/live company. Not everything needs to be done before writing code — this is a pre-*launch* gate, not a pre-*development* gate.

## Technical

- [ ] MVP feature set complete per `CLAUDE.md` Sections 1–13 and the state machine in Section 7.
- [ ] Arabic + Kurdish RTL PDF rendering tested and confirmed correct (Section 24.5 of the PRD / Section 2 of CLAUDE.md).
- [ ] `audit_log` and `ai_generation_logs` are live and capturing real events (CLAUDE.md Section 13).
- [ ] Backups running daily, verified restorable (not just "configured"), stored encrypted on a different provider/server than the primary VPS.
- [ ] HTTPS/TLS enforced, no plain HTTP access possible.
- [ ] Uptime monitoring + backup-failure alerting active.
- [ ] Multi-tenancy isolation tested: confirm one company genuinely cannot see another company's data through any endpoint, not just the UI.
- [ ] CSV bulk employee import tested with a real company's data.

## Legal content

- [ ] General legal-reference database populated (Labor Law No. 37/2015 articles relevant to discipline, safety, PPE, warnings, termination) — reviewed by the law firm partner, not just pulled from general AI knowledge.
- [ ] Pilot company's internal work regulation (if they have one) uploaded and scoped correctly as company-private (PRD Section 22.2).
- [ ] Standard blank Administrative Order template ready for companies without one (PRD Section 24.1).
- [ ] Defense letter / recommendations / warning-termination templates reviewed and approved by the law firm partner — not just matched to the sample documents provided.
- [ ] "Sensitive case" trigger definition finalized (currently open — CLAUDE.md Section 12).
- [ ] Confirmation from the law firm partner on whether employee leave pauses the 15-day/3-day deadlines (currently open).

## Business / contracts

- [ ] Terms of Service drafted (with the law firm partner).
- [ ] Data Processing Agreement drafted — clarifies who is legally responsible for employee data.
- [ ] Partnership agreement with the law firm signed (revenue share, responsibilities, exclusivity — PRD Section 8).
- [ ] Partnership agreement with the HR company signed.
- [ ] Startup legally registered (PRD Section 24.9).
- [ ] Pricing model tested with 3–5 real target companies, not just the initial proposal in PRD Section 17.
- [ ] Cancellation/data-export policy finalized in writing (PRD Section 24.7).

## Pilot company readiness

- [ ] Pilot company's branches, employees, and committee pool (per branch) fully entered.
- [ ] Pilot company's Administrative Order uploaded and linked.
- [ ] At least one full test case run end-to-end (defense letter → response → session → recommendations → decision → archive) with real people reviewing the output quality.
- [ ] Pilot company's HR staff given a short walkthrough/training session — don't assume the interface is self-explanatory on day one.

## Operations

- [ ] A defined process for who handles a bug report or support question from a live client (even if it's just "message the founder" at this stage — write it down).
- [ ] A defined process for updating the legal-reference database when the law changes or the law firm flags an update.
- [ ] A single owner identified for reviewing `audit_log`/`ai_generation_logs` periodically, not just collecting the data passively.

---

**How to use this:** don't treat this as sequential — legal content and business/contract items can run in parallel with development. The one hard gate is: no real company's real employee data goes into the system until every item in "Technical" and "Legal content" is checked, because those two sections are what make the output legally defensible.
