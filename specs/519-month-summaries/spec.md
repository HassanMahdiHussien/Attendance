# Feature Specification: Monthly reporting summaries

**Decision date:** 2026-11-05

## Problem

Dashboard totals project hundreds of thousands of days for long periods. Render time and memory grow with every additional month.

## Mechanism

Store each employee's additive calendar-month result from the existing live attendance calculation. A queued job rebuilds dirty or missing months; a version check prevents a concurrent change from being marked clean. The verify command compares stored fields with live results.

## Rules

- Unfiltered totals combine clean whole months with live partial, dirty and missing months. Other filters and per-employee detail stay live.
- Day, leave and meal changes invalidate affected months. Pending forms, credit balances, issues, employee state and non-employee meals remain live for the requested period.
- Stored rows are tenant scoped, rebuildable derived data. They never replace source days, allocations or audit history.

## What stays live

Overtime totals with the default as-of date and coverage quality use versioned result caches; their underlying calculations remain live services. Explicit overtime as-of dates, attendance exports, drill-downs and per-employee views stay live. Source and run timestamps and the updating state are always live.
