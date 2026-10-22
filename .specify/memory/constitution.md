# Attendance & Leave Reconciliation Constitution

Attendance & Leave Reconciliation is a multi-tenant SaaS for Iraqi companies. Results must be
explainable to employees and auditable, so correctness, isolation and traceability outrank speed.
`CLAUDE.md` is the detailed build reference; this constitution states the rules no feature may break.

## Core Principles

### I. Tenant Isolation (NON-NEGOTIABLE)

- Every tenant-scoped table MUST carry `company_id` directly or inherit it through a parent that
  does, and every read MUST pass through model-layer scoping (global scopes + policies), never
  UI-only hiding.
- Platform Admin cross-tenant access MUST use explicit, tested code paths — never an ad hoc scope
  bypass.
- Every feature that adds a tenant-scoped model or route MUST add an isolation test proving a user
  of Company A cannot read or write Company B's rows.

Rationale: shared-schema tenancy (ADR 0001) means one missed check is a real data leak.

### II. AI Proposes, Rules Calculate, Humans Confirm

- Calculations, balances and allocations MUST be deterministic service code with versioned policies,
  never instructions or formulas inside AI prompts.
- AI MAY propose leave-description meaning, holiday extraction and chat intent. Nothing it proposes
  takes effect without HR confirmation; AI output alone MUST NOT change a balance or record.
- Employee text and file content MUST be treated as data, never as instructions.
- Every AI call MUST be logged in `ai_generation_logs`. Send only the data the specific task needs.
- An AI outage MUST NOT block import or calculation.

Rationale: employees must be able to trace a result to source records and a versioned rule, with HR
accountable for any interpretation confirmed from AI.

### III. Source Records and Audit Are Immutable

- Original files, raw transactions and imported request versions MUST never be edited or deleted.
  Re-uploading the same or an overlapping source MUST NOT duplicate records or effects.
- Cleaned, corrected and allocated values MUST be separate rows pointing at the original, with
  actor and reason. Reversals MUST be new rows, never destructive edits.
- `audit_logs` and `ai_generation_logs` MUST be append-only, enforced by MySQL triggers. Every
  import, calculation, correction, allocation, confirmation and AI generation MUST remain traceable
  to its actor, time and source.

Rationale: an employee and auditor must be able to reconstruct the original evidence and every
later interpretation.

### IV. Test the Load-Bearing Logic

- Automated Pest tests are REQUIRED before merge for the CLAUDE.md §7 priorities: tenant isolation;
  import idempotency for re-uploads and overlaps; punch cleanup; time math including office
  flexibility, cross-midnight assignment and no double counting; leave matching including 4 h
  covering 3 h with surplus credit and reversal; rest-day entitlements and overtime matching;
  pending days never becoming confirmed absence; back-dated history; and agreement across profile,
  dashboard, reports and chat.
- The full test suite and Pint MUST pass after every implementation step and before every merge.
- Routine CRUD and standard Livewire screens MAY rely on manual testing until they break.

Rationale: these paths determine employee-facing results and cannot rely on visual checks alone
(CLAUDE.md §7).

### V. Simple Laravel, Thin UI

- Use Laravel's idiomatic solution first; 20 clear lines beat 5 clever ones. No new abstraction
  (interface, factory, base class) until the same pattern exists in 3+ places. No speculative
  future-proofing beyond the build reference.
- Business logic MUST live in service classes; Livewire components and Filament resources only
  validate input, call a service and render — so a future API reuses the same logic.
- Type-hint parameters and return types; format with Laravel Pint before every commit.
- Comments explain why, not what.

Rationale: maintainability by one developer with AI assistance (CLAUDE.md §6).

### VI. Deliberate Decisions Stay Decided

- Decisions recorded in `WEIRDNESS.md` and `docs/adr/` MUST NOT be "fixed" or reversed inside a
  feature. Reversing one requires the product owner's explicit approval and a new ADR that
  supersedes the old one.
- A new non-obvious deliberate choice MUST get one line in `WEIRDNESS.md`; a hard-to-reverse
  architectural choice MUST get an ADR.
- When the spec is ambiguous or two reasonable approaches exist, ask the product owner instead of
  guessing.

Rationale: these choices come from real source data, operational constraints and reviewed product
decisions; their context must survive later refactors.

## Technology & Data Constraints

- Stack is fixed: Laravel 13 + MySQL, Livewire + Tailwind for working screens, Filament for
  back-office CRUD, Browsershot for printable statements, Claude API for AI, local-disk storage on a private VPS (no S3/cloud — ADR 0006),
  Laravel queues (database driver) and scheduler for slow work. Local development runs natively in
  WSL Ubuntu, without Docker.
- No package install (`composer require`, `npm install`) and no project-wide framework feature may
  be added without the product owner's prior approval.
- English is the default interface language; Arabic is second with native RTL support (ADR 0014).
  Every user-facing string ships in both languages.
- Never log or expose secrets, tokens, passwords or employee personal data. Never disable CSRF,
  auth or tenant scoping in committed code. Never email medical details. Treat all input as hostile.

## Development Workflow & Quality Gates

- Every feature goes through spec-kit: specify → clarify → plan → tasks → implement.
- Each plan is cross-checked by Codex before tasks are generated; Codex implements and every diff
  is reviewed before it lands.
- After every implementation step the full test suite and Pint MUST pass; failures are reported,
  never hidden.
- Git: `main` stays deployable; work happens on a feature branch and merges via PR; one-line
  present-tense commit messages. A change touching more than ~5 files outside the planned scope is
  paused for a split decision.
- Each task ends with: summary of what changed and why, affected files, new dependencies / env vars
  / migrations, and manual steps required.

## Governance

- This constitution supersedes conflicting practices. Where it is silent, `CLAUDE.md` and
  `docs/attendance/ROADMAP.md` govern; the roadmap resolves product decisions when it and the PRD
  disagree.
- Amendments require the product owner's approval, an updated version number and, if a principle is
  removed or redefined, a note on affected specs.
- Versioning: MAJOR = principle removed or redefined; MINOR = principle or section added or
  materially expanded; PATCH = wording only.
- Every plan's Constitution Check and every PR review MUST verify compliance with Principles I–VI.

**Version**: 2.0.0 | **Ratified**: 2026-10-04 | **Last Amended**: 2026-10-21

Amendment 2.0.0 (2026-10-21): Principles II and III were redefined and Principle IV's list was
replaced for attendance (spec 500). The Displanary 1.1.0 text is kept in
`docs/upstream/displanary/constitution-1.1.0.md`.
