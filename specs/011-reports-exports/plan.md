# Implementation Plan: Reports, Exports and the Employee Page

**Branch**: `011-reports-exports` | **Date**: 2026-10-08 | **Spec**: [spec.md](./spec.md)

## Summary
The employee page (missing until now), four reports built on 010's metrics service, and queued PDF/Excel exports with
owner-only downloads and 7-day retention.

## Technical Context
PHP 8.4 · Laravel 13 · Livewire 4 · existing Browsershot `PdfGenerator` · OpenSpout ^4.32 (approved, installed) ·
queue + scheduler · Pest.

## Constitution Check
| Principle | Status | How |
|---|---|---|
| I. Tenancy | PASS | CaseVisibility for lists; aggregateFor only for partner counts; exports owner-only; job re-checks scope |
| II. AI boundary | PASS | no AI |
| III. Immutable | PASS | read-only over cases; export rows and audit append-style (status updates only) |
| IV. Tests | PASS | figures = dashboard, visibility per role, export lifecycle, no names for partners |
| V. Thin UI | PASS | Livewire over EmployeeProfile / ReportBuilder / ReportExporter |
| VI. Decided | PASS | OpenSpout and the split per product owner |

## Structure
```text
database/migrations/2026_10_11_* (report_exports)
app/Services/Employees/EmployeeProfile.php · app/Services/Statistics/{ReportBuilder, ReportExporter, ComplianceRows}.php
app/Jobs/GenerateReportExport.php · console report-exports:prune (+ schedule)
app/Statistics/{PdfReport, ExcelReport}.php (renderers) · resources/views/reports/*.blade.php
app/Livewire/Employees/{EmployeeIndex, EmployeePage}.php · app/Livewire/Statistics/Reports.php + views
routes (employees, statistics, export download) · nav items · links from case page / board / drill-down
tests/Feature/Statistics/*, tests/Feature/Employees/*
```

## Delegation
Codex plan review → run T (all tasks) → orchestrator gates, diff check, browser walkthrough (employee page, each
report preview, PDF + Excel export, download), PR.
