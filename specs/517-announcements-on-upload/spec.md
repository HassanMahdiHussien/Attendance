# Feature Specification: Announcements on upload

**Created**: 2026-11-04 | **Status**: Product-owner decision

## Problem

An off-site work announcement was read as a holiday, while an unrelated notice could be approved for the whole company. AI errors left uploads waiting, and HR could not see recalculation progress.

## Decision

Announcements are classified as holiday, field work, or notice. HR reviews the proposed dates and people on the Upload page, sees a dated coverage preview and deterministic questions, then approves once or closes the announcement without an attendance effect.

## Requirements

- Field work counts as a full worked day for covered employees, keeps punch evidence, and gives no overtime, holiday credit, or follow-up case. A holiday or rest day wins.
- Department and employee scopes combine with OR and then intersect with legal entity, branch, and pattern. Historical employee values determine coverage.
- AI reading accepts text blocks only, reports failure, and supports bounded retry. Spreadsheet attachments provide employee codes without sending rows to AI.
- Approval is one auditable transaction. Empty scope requires an explicit Everyone choice. Field work for Everyone needs a second count confirmation. One source can be approved once.
- Reassessment and leave reconciliation run in a named batch with visible progress. The original source remains immutable.

## Out of scope

Employees with `pattern_missing` stay that way on a field day. Leave imported for a field day is not removed.

## Acceptance

Uploading an announcement on Upload shows its type, dates, scope, preview, questions, and one approval action. A covered person without punches reads as field work, a holiday retains priority, and withdrawing returns normal assessment. Tenant isolation, audit, English and Arabic strings, and the calculation gates pass.
