# 0006 — File storage on a private VPS, not third-party cloud (AWS/GCP)

**Status:** Accepted

## Context
Uploaded case evidence (scanned signed documents, employee responses) is sensitive HR/legal data. An earlier design considered AWS S3/Google Cloud Storage. The product owner decided against foreign cloud storage in favor of full control and a stronger "your data never leaves your hands" sales pitch for privacy-sensitive Iraqi companies.

## Decision
Store all files on local disk on a single private VPS (Laravel's local `flysystem` driver), with a daily automated backup to a server on a **different provider**, encrypted, not co-located with the primary VPS.

## Consequences
**Gained:** a genuine marketing differentiator against foreign-cloud-based competitors; simpler initial setup (no cloud SDK/IAM configuration); full data sovereignty.
**Lost:** the team is now responsible for their own backup reliability, disk capacity planning, and server hardening — there's no managed-service safety net. Losing the primary VPS without a verified-working backup means losing all clients' legal evidence, so backup verification (not just "backup exists") is a launch-readiness gate (see `docs/upstream/displanary/LAUNCH-CHECKLIST.md`).
**Revisit when:** the client base grows enough that a single VPS's disk/bandwidth becomes a real constraint (see PRD Section 13's "past ~15–20 companies, re-evaluate" note).
