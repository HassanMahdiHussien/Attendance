# 0007 — Discipline ships first, as module 1 of an employee-lifecycle platform

**Status:** Accepted

## Context
The product scope grew from a disciplinary-process tool into a full employee-lifecycle platform: recruitment, onboarding, employee record, discipline, performance, leave, and offboarding, with a Flutter employee app, federal Iraq + Kurdistan Region coverage, and per-company approval chains. Building all of it before launch would delay any market feedback by a year or more.

## Decision
Design the whole platform now (`docs/PLATFORM-PRD.md`), but build and launch module by module, discipline first — it is fully specified and has a pilot company. Phase 1 follows a small set of platform-readiness rules (CLAUDE.md Section 19): logic in service classes (API-ready), jurisdiction on branches, nullable user↔employee link.

## Consequences
**Gained:** early launch and real feedback; later modules plug into the same foundation.
**Lost:** some later-phase needs (a generic approval engine, holiday calendars, employee accounts) are deliberately deferred, so there will be refactoring when module 2 starts — kept small by Section 19.
**Revisit when:** the pilot company's feedback suggests a different module order.
