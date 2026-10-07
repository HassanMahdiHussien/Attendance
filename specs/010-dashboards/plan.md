# Implementation Plan: Dashboards

**Branch**: `010-dashboards-reports` | **Date**: 2026-10-07 | **Spec**: [spec.md](./spec.md)

## Summary
One visibility-respecting metrics service feeding four dashboards: HR (alerts, counters, hotspots with trends,
threshold alerts, drill-down), Platform Admin (all companies), HR partner (counts; lists only with full access) and
external reviewer (consultations). No charts library, no new packages.

## Technical Context
PHP 8.4 · Laravel 13 · Livewire 4 · Filament 5 (platform widget, company settings) · MySQL aggregate queries · Pest.

## Constitution Check
| Principle | Status | How |
|---|---|---|
| I. Tenancy | PASS | every figure from CaseVisibility; partner/company lists explicit; tests per role |
| II. AI boundary | PASS | no AI; alerts are visibility only |
| III. Immutable | PASS | read-only; settings changes audited |
| IV. Tests | PASS | visibility matrix, counter = drill-down, deadline edges, thresholds |
| V. Thin UI | PASS | Livewire/Filament read from CaseMetrics |
| VI. Decided | PASS | split and no-charts per product owner / CLAUDE.md §13 |

## Structure
```text
database/migrations/2026_10_10_* (companies hotspot_min_cases, hotspot_window_days, deadline_alert_hours)
app/Services/Dashboards/{CaseMetrics, DashboardFilters, Hotspots}.php
app/Livewire/Dashboard/{HrDashboard, CaseDrilldown}.php + views; app/Livewire/Home.php (partner overview); MyCases summary
app/Filament/Platform/Widgets/CompaniesOverview.php (or Page) · CompanySettings fields
resources/views/layouts/workspace.blade.php (nav item) · routes
tests/Feature/Dashboards/*
```

## Delegation
Codex plan review → run S (all tasks) → orchestrator gates, diff check, browser walkthrough with seeded demo cases,
PR.
