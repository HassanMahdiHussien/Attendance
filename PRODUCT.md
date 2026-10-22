# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

- **HR staff and HR managers** at Iraqi companies import employee, attendance-device, leave,
  overtime and holiday records. They review data issues, confirm interpretations, reconcile hours
  and explain results to employees.
- **Platform Admin** manages tenant companies and access through explicit cross-company paths.
- Employees are the people whose records are reconciled. An employee account is not required to
  explain a result or review its source.
- English is the default interface language; Arabic is fully supported with native RTL layouts.

## Product Purpose

Reconcile attendance and leave without replacing the company's leave system or calculating pay.
Keep original files and raw transactions, clean repeated punches, calculate work and absence
minutes, match leave and approved overtime, and show how each employee result was reached.
Success means HR can correct a source interpretation without losing history, re-upload safely,
and give the same answer from the profile, dashboard, reports and chat.

## Positioning

An explainable attendance and leave reconciliation workspace. Versioned service rules calculate
hours, balances and allocations. AI may propose the meaning of a leave description, a holiday
notice or a chat request; HR confirms before a proposal affects a record. The source and each
correction remain visible and auditable.

## Operating Context

- Multi-tenant SaaS: each company sees its own records; Platform Admin has explicit cross-company
  access.
- Inputs include attendance-device exports, employee lists, leave reports, approved overtime
  reports and holiday notices. A re-upload never duplicates effects.
- Ongoing or incompletely covered days remain pending. Missing data is not automatically absence.
- The product does not issue disciplinary decisions, approve leave or calculate payroll.

## Capabilities and Constraints

- Responsive web app built with Laravel, Livewire, Tailwind and Filament for administration.
- Full right-to-left Arabic interface and left-to-right English; employee names and source
  descriptions are never machine-translated.
- Source files and raw rows remain immutable; corrections, allocations and reversals point to the
  source and record an actor and reason.
- Import and calculation remain available when AI is unavailable.

## Brand Commitments

- Name: **Attendance**.
- Keep the established midnight shell, restrained signal amber and readable work surfaces.
- Voice: calm, precise and respectful of the employee. Explain uncertainty and pending data plainly.

## Evidence on Hand

- The pilot company and sites are represented by anonymised seed data. Real employee reports and
  names must never be committed or shown.
- No customer testimonials, logos, metrics or pricing exist yet; do not fabricate any.

## Product Principles

1. Explain every result from source rows, correction history and a versioned rule.
2. Keep pending and incomplete days distinct from confirmed absence.
3. Re-upload without duplicate transactions, balances, allocations or notifications.
4. Let HR confirm interpretations; AI proposals never change records by themselves.
5. Keep English and Arabic equally usable, including native RTL layouts.

## Accessibility & Inclusion

Keyboard-reachable controls and visible focus; WCAG AA contrast; works at 375 px; RTL-correct
layouts; legible Arabic text for long employee records.
