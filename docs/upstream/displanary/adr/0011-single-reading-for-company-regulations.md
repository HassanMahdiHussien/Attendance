# ADR 0011: Single local reading for company regulations

**Status:** Accepted, 2026-10-14

## Context

Every statute article needs two independent readings of its official source. A company regulation has an additional, mandatory company lawyer approval before an article may be cited. Reading every clear regulation page again with AI adds cost without removing that lawyer gate.

## Decision

For a company regulation PDF, use a usable PDF text layer or Tesseract OCR with mean word confidence at or above the configured threshold as one local reading. Skip AI for that page. A unit supported entirely by such pages is `verified_single`. It is immutable, but remains uncitable until the company's lawyer checks its text against the linked page image and approves that unit individually. Bulk approval never includes it. A correction creates a new version and requires approval of that version.

An article spanning a local-only page and an AI-read page remains a mismatch for human resolution against its page images. Failed or uncertain local readings use the existing AI and review paths. Outside transcriptions and supplied independent secondary texts retain their two-reading path. Text and Word regulations retain their direct-source verification and lawyer approval.

Statutes keep two independent readings for every unit and cannot acquire `verified_single`. The statute JSON archive rejects that status.

## Consequences

Clear regulation PDFs can import without an AI call. The company lawyer provides the second check for each single-reading unit, and the approval audit records `method = single_reading`. Mixed and uncertain articles take longer because they require review before approval.
