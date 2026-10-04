# 0001 — Multi-tenancy via shared schema (`company_id`), not separate databases

**Status:** Accepted

## Context
The system serves multiple companies (tenants). Data must be strictly isolated between them — one company must never see another's employees, cases, or documents. Two standard approaches exist: a separate database per tenant, or a shared schema with a `company_id` column on every table.

Expected scale is fewer than 10 companies in year one, growing gradually. The team is small (one developer with PHP/Laravel experience, learning as they go).

## Decision
Use a single shared MySQL database with a `company_id` foreign key on every tenant-scoped table, enforced via a global Eloquent scope (not ad-hoc per-query checks). Do not provision a separate database per company.

## Consequences
**Gained:** far simpler to build, migrate, and back up at this scale; one connection pool; easy cross-tenant reporting for the HR partner's dashboard.
**Lost:** isolation depends entirely on the scope being applied correctly everywhere — a single missed check is a real cross-tenant data leak, not just a bug. This makes the global scope + Section 13 (CLAUDE.md) access-control tests a *hard* requirement, not optional polish.
**Revisit when:** a large enterprise client requires contractually guaranteed physical data isolation, or the tenant count grows large enough that per-tenant backup/restore or noisy-neighbor performance becomes a real problem (see PRD Section 13, "Note on Multi-tenancy").
