# Displanery Constitution

Displanery is a multi-tenant SaaS for Iraqi companies to run employee disciplinary procedures
under Iraqi labour law. Its output can end up as evidence before a labour court, so correctness,
isolation and traceability outrank speed and cleverness. `CLAUDE.md` is the detailed build
reference; this constitution states the rules no feature may break.

## Core Principles

### I. Tenant Isolation (NON-NEGOTIABLE)

- Every tenant-scoped table MUST carry `company_id` directly or inherit it through a parent that
  does, and every read MUST pass through model-layer scoping (global scopes + policies), never
  UI-only hiding.
- Cross-tenant roles (Platform Admin, External Legal Reviewer, HR Partner) MUST get their broader
  access through explicit, tested code paths — never by disabling the scope ad hoc.
- Company-private legal content (internal regulations) MUST never be visible to another tenant.
- Every feature that adds a tenant-scoped model or route MUST add an isolation test proving a user
  of Company A cannot read or write Company B's rows.

Rationale: shared-schema tenancy (ADR 0001) means one missed check is a real data leak.

### II. AI Drafts, Humans Decide

- The AI MUST only turn human-entered facts and human-made decisions into draft wording. It MUST
  NOT infer missing facts, suggest an outcome, or make any decision.
- Every AI draft MUST pass a human confirmation screen before printing or issuance, and every
  drafting step MUST have a manual "write it yourself" path so an AI outage never blocks a case.
- The AI MUST NOT type the text of a legal article. It cites verified `legal_units` by id
  (`[[cite:<id>]]`); the system prints the official text. An article number in AI prose outside a
  marker is a hard error. Only `verified_*` units are citable.
- Signature checks are presence checks only; no UI or API text may claim identity verification.
- Every AI call MUST be logged (input, output, model) in `ai_generation_logs`, and only the data a
  draft needs may be sent (never national IDs, salaries or phone numbers).

Rationale: the product's legal defensibility depends on this line (ADR 0003, CLAUDE.md §3b, §23).

### III. Legal Records Are Immutable and Audited

- An issued document MUST never be updated in place; corrections create a new version row with
  reason and actor. This MUST be enforced in code, not by convention.
- Official reference numbers are assigned at issuance; drafts MUST NOT consume a number.
- `case_events`, `audit_logs` and `ai_generation_logs` are append-only: no update or delete path
  may exist, including on archive or company cancellation.
- Every state transition, confirmation, edit, print, signature and AI generation MUST be logged
  with actor, timestamp and metadata from the first migration onward.

Rationale: evidentiary value before a labour court (ADR 0004, CLAUDE.md §10b).

### IV. Test the Legally Load-Bearing Logic

- Automated Pest tests are REQUIRED for, and MUST precede merge of, any change touching: tenant
  isolation; the 3-day / 15-day / 30-day deadline math (calendar days, anchored to
  `defense_letter_issued_at`, no holiday or leave pausing); committee quorum (≥3 present, a labour
  union member present, majority of those present); document immutability; the citation
  validator; the three-category approval gate on Doc A (and its reset on edit); signature
  presence-check wording.
- The full test suite MUST pass after every implementation step and before every merge.
- Routine CRUD and standard Livewire screens MAY rely on manual testing until they break.

Rationale: a solo developer has no second reviewer; these tests are the safety net (CLAUDE.md §16,
§21).

### V. Simple Laravel, Thin UI

- Use Laravel's idiomatic solution first; 20 clear lines beat 5 clever ones. No new abstraction
  (interface, factory, base class) until the same pattern exists in 3+ places. No speculative
  future-proofing beyond the platform-readiness rules in CLAUDE.md §19.
- Business logic MUST live in service classes; Livewire components and Filament resources only
  validate input, call a service and render — so a future Sanctum API reuses the same logic.
- Type-hint parameters and return types; format with Laravel Pint before every commit.
- Comments explain why, not what.

Rationale: maintainability by one developer with AI assistance (CLAUDE.md §13, §15, §19).

### VI. Deliberate Decisions Stay Decided

- Decisions recorded in `WEIRDNESS.md` and `docs/adr/` MUST NOT be "fixed" or reversed inside a
  feature. Reversing one requires the product owner's explicit approval and a new ADR that
  supersedes the old one.
- A new non-obvious deliberate choice MUST get one line in `WEIRDNESS.md`; a hard-to-reverse
  architectural choice MUST get an ADR.
- When the spec is ambiguous or two reasonable approaches exist, ask the product owner instead of
  guessing.

Rationale: many rules look wrong out of context but come from the real Administrative Order or
the governing law.

## Technology & Data Constraints

- Stack is fixed: Laravel (latest stable) + MySQL, Livewire + Tailwind for the case workflow UI,
  Filament for back-office CRUD only, Browsershot for PDFs, Claude API for AI, local-disk storage
  on a private VPS (no S3/cloud — ADR 0006), Laravel queues (database driver) + scheduler for all
  slow work and deadline alerts. Local development runs on Laravel Sail (Docker).
- No package install (`composer require`, `npm install`) and no project-wide framework feature may
  be added without the product owner's prior approval.
- Arabic is authoritative; document text lives in `document_contents` (one row per language); Arabic
  and English are always enabled, further languages are per-company settings (Kurdish off by
  default).
- Discipline deadlines are plain calendar days. No working-day or holiday logic in discipline code.
- Never log or expose secrets, tokens, passwords or employee personal data. Never disable CSRF,
  auth or tenant scoping in committed code. Treat all input as hostile.

## Development Workflow & Quality Gates

- Every feature goes through spec-kit: specify → clarify → plan → tasks → implement.
- Each plan is cross-checked by Codex (delegated) before tasks are generated; implementation is
  delegated to Codex, and every Codex diff is reviewed before it lands.
- After every implementation step the full test suite and Pint MUST pass; failures are reported,
  never hidden.
- Git: `main` stays deployable; work happens on a feature branch and merges via PR; one-line
  present-tense commit messages. A change touching more than ~5 files outside the planned scope is
  paused for a split decision.
- Each task ends with: summary of what changed and why, affected files, new dependencies / env vars
  / migrations, and manual steps required.

## Governance

- This constitution supersedes conflicting practices. Where it is silent, `CLAUDE.md` governs; where
  `CLAUDE.md` and other docs disagree, `CLAUDE.md` wins.
- Amendments require the product owner's approval, an updated version number and, if a principle is
  removed or redefined, a note on affected specs.
- Versioning: MAJOR = principle removed or redefined; MINOR = principle or section added or
  materially expanded; PATCH = wording only.
- Every plan's Constitution Check and every PR review MUST verify compliance with Principles I–VI.

**Version**: 1.0.0 | **Ratified**: 2026-10-04 | **Last Amended**: 2026-10-04
