# ADR 0013: Attendance as a separate repo copied from Displanary, designed to merge back

**Status:** Accepted, 2026-10-21

## Context

The product owner needs attendance and leave reconciliation (`docs/attendance/PRD-EN.md`) for their own company first, then as a product for other companies. The end goal is one HR dashboard and one employee page where searching an employee shows both attendance and disciplinary history.

Building attendance inside the Displanary repo was considered first. The product owner chose a separate repo so attendance work can never break Displanary while it serves its pilot company.

Leave requests are submitted and approved in each company's existing HR system. The platform's own leave module (`docs/upstream/displanary/PLATFORM-PRD.md` §10) is still in the future.

## Decision

- Create `HassanMahdiHussien/Attendance` as a copy of Displanary's `main` at `2d85ddb`, **with full history**, so both repos share a common ancestor.
- Spec 500 removes the discipline module and keeps the shared foundation: tenancy, users and roles, audit, employees, branches, import pattern, design system, i18n, help, deploy and backups.
- Attendance code lives in its own namespaces and migrations; foundation changes are logged in `docs/upstream/FOUNDATION-CHANGES.md`; specs are numbered from 500.
- Extend the shared `employees` table (plus an effective-dated history table) instead of creating a second employee model. External reports match by `employee_code` as exact text.
- Import leave from the company system as read-only facts; `leave_requests.source` lets a future native leave module become another source.
- Calculations are deterministic service code with versioned policies. AI only proposes; HR confirms.
- **Merge back later:** when attendance is stable, bring its commits into Displanary as a module, excluding the spec-500 cleanup commit, and apply `FOUNDATION-CHANGES.md`. A two-app integration over an API remains a fallback if the merge proves impractical.

## Consequences

Displanary is fully protected while attendance is built. The price is temporary duplication: two employee lists until the merge, and fixes to the shared foundation must be ported between repos. Keeping the foundation structurally identical and logging every foundation change keeps that cost low and the eventual merge mechanical.
