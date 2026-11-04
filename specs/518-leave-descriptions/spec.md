# Feature Specification: Leave descriptions and saved form follow-up

**Decision date:** 2026-11-04

## Problem

The new company leave export includes a filled-down record type and free-text description. Earlier imports lost both, so work-from-home and travel forms with blank leave type became unmapped. Descriptions can identify a compensatory worked date or an earlier period of hourly leave. Saved `Leave Entry` forms currently go unnoticed.

## Decision

Import both fields without changing old-file behavior. AI proposes a reading using only text, type, dates and duration. HR confirms calculation effects. Deterministic replay allocates a confirmed worked-date entitlement first or settles a confirmed hourly period first. Saved forms remain drafts and cover nothing, but generate a manager follow-up within the configured window.

## Requirements

- Skip subtotal and total rows without form numbers; fill record type down; use it as the type when both leave-type columns are empty. Work from home and business travel cover when approved; administrative leave remains unmapped.
- Keep source versions append only. Provide an idempotent company-scoped backfill from stored batches, preserving history and dispatching replay only for changed types.
- Read descriptions in queued batches of at most 25, deduplicated by text, type and start date, with a daily cap, schema validation, metadata-only AI logs, visible failure and retry.
- Categories are compensation for a worked day, hourly settlement, work from home, plant stop, medical, reason only and no information. Day-first dates must be stated and valid. AI cannot change calculations without HR confirmation.
- A confirmed compensation date consumes its rest-day credit first; a confirmed hourly period consumes unreconciled late, early and exit minutes oldest first, then follows the existing surplus rule. Flags explain missing credit or period minutes. Undo and rejection restore replay results.
- HR has a bilingual, tenant-scoped description review page with progress, filters, decisions, retry and high-confidence bulk confirmation. Source text remains exact and right-to-left safe. Medical details stay on leave-authorized screens only.
- A recent `Leave Entry` produces one per-form follow-up item to the manager via the existing notification route. It closes on a later status or window expiry, never covers a day, never emails the employee and never includes the description in mail.

## Out of scope

Counting `Leave Entry` as cover; emailing employees; changing Annual Leave balance rules; AI changing a result without HR.

## Acceptance

Invented-data Pest cases cover import and backfill idempotency, AI validation and isolation, confirmed allocation and undo, tenant and permission checks, and notification lifecycle. The full suite, Pint and browser checks run in WSL before merge.
