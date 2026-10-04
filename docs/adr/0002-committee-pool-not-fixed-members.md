# 0002 — Committee membership is a many-to-many pool, not fixed individuals

**Status:** Accepted

## Context
Initial design assumed one fixed person per committee role (a single "legal member," a single "HR member," etc.) per branch, stored as boolean flags on the employee record. A real Administrative Order document from the pilot company showed the actual legal structure: each role has multiple named alternates ("X or Y or Z"), with an explicit quorum rule — minimum 3 members present, the labor union representative mandatory among them, decision by majority vote of those actually present.

## Decision
Model the committee as a pool: a `committee_mandates` table (one administrative order per company, versioned over time) linked to a `committee_pool` table (many-to-many: person × role_type × branch). When a case's committee session happens, the system presents the pool per role (filtered to the case's branch) and the user selects who actually attended — captured per-case in `committee_session_attendance`, with an individual vote/signature record per attendee.

## Consequences
**Gained:** matches the real legal structure exactly; supports the confirmed multi-branch requirement where committee composition differs by location; auditable record of who actually attended (and voted on) each specific case.
**Lost:** meaningfully more complex than a single fixed-person-per-role model — more tables, more UI (select-from-pool instead of auto-fill), and quorum validation logic (≥3 present, union rep mandatory) must be enforced before a case can move to "committee_voted."
**Revisit when:** never, unless a pilot company's real structure turns out to be simpler than this (unlikely, given it's already confirmed from a real legal document).
