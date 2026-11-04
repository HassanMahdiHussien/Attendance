# Feature Specification: Office rest hour

**Created**: 2026-11-04 | **Status**: Product-owner decision

## Problem

Office staff may spend a one-hour rest outside the office. A nine-hour minimum presence marks ordinary days short, and the daily lists hide the out interval that explains elapsed versus worked time.

## Decision

An office day requires eight hours of recorded presence within a nine-hour elapsed workday. Rest allowance is `max(0, workday_minutes - min_presence_minutes)` from the day's policy. No assumed break is deducted from presence. Out time beyond the allowance is information only.

## Requirements

- Default minimum presence and replacement versions of eligible historical policies use 480 minutes; workday and absence remain 540 and 480 minutes respectively.
- Preserve each effective date and all unrelated policy values. Reassessment is a separate manual command.
- Employee and admin daily rows show total out time; expanded rows show each interval. The policy page shows the derived allowance.
- Presentation uses shared localized durations and keeps clock times left to right.

## Out of scope

Shift and security rules, calculation formulas, balances, allocations, overtime, exports, and stored day columns.

## Acceptance

An office day from 08:29 to 17:53 with 65 minutes out has 499 minutes presence, no short time, and a 17:29 required departure. The row shows 1 h 5 min out and 5 min over rest without raising review. A 479-minute presence is short by one minute.
