# 0004 — Issued documents are immutable; edits create new versions

**Status:** Accepted

## Context
Every generated document (defense letter, recommendations, decision letter) may end up as legal evidence before a labor court. If a document row could be silently UPDATEd after issuance, opposing counsel could credibly argue "who guarantees this is the original text?" — destroying the document's evidentiary value.

## Decision
Once a `documents` row is issued (`status = issued`, `is_locked = true`), no UPDATE is ever performed on its content. Any correction creates a new row with an incremented `version`, linked to the same case, with the previous version retained (never deleted). A mandatory final-preview/confirmation screen is shown before any document moves to `issued` — by default a single advisory confirmation, with an optional per-company setting requiring a second person's approval (PRD Section 24.3).

## Consequences
**Gained:** every document's history is fully auditable; the evidentiary chain for a labor-court dispute is intact by construction, not by developer discipline alone.
**Lost:** slightly more storage and query complexity (always fetch latest version, not just "the" document); the UI must clearly distinguish "draft, editable" from "issued, locked" states so users don't expect to edit a printed letter in place.
**Revisit when:** never — this is a Non-Negotiable (see CLAUDE.md Non-Negotiables section), not a scalability-driven decision.
