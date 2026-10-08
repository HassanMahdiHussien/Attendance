# Implementation Plan: Design System, Sidebar Layout and Mobile

**Branch**: `017-design-system` | **Date**: 2026-10-08 | **Spec**: [spec.md](./spec.md)

## Summary
Anonymous Blade components under `resources/views/components/ui/*` implementing the approved mockup, a new workspace
layout (sidebar + top bar + mobile bottom bar), every workspace Livewire view rebuilt on the components without
behaviour change, and a Filament theme pass.

## Technical Context
Blade anonymous components · Livewire 4 · Tailwind v4 (`@theme` tokens already in `resources/css/app.css`; logical
utilities only) · Alpine (ships with Livewire) for the mobile "More" sheet and toasts · Filament 5 theme
(`->colors()`, `->font()`, `->brandName()` per locale) · no new packages (inline SVG icons).

## Research (decisions)
- **R1 Components** (`<x-ui.*>`): `page-header` (title, subtitle, actions slot, back link), `card` (title, actions,
  padding variants), `stat` (label, value, hint, tone), `badge` (tone: red/amber/green/indigo/gray), `button`
  (variant, size, href or wire:click passthrough via `$attributes`), `table` (head/body slots; at `<md` each row
  renders as a card using `data-label` cells), `field` (label, hint, error from `$errors`), `input`/`select`/
  `textarea`/`file` (pass attributes, including `wire:model*`), `empty` (icon, title, text, action slot), `alert`
  (tone), `toast` (Alpine listener for a `notify` browser event + session flash), `tabs`, `stepper` (5 stages from the
  existing `StageIndicator`), `icon` (inline SVG set: home, folder, inbox, check, users, chart, book, settings, search,
  plus, chevron, arrow, file, clock, alert, x, menu, logout, globe). Components only present; they contain no
  business logic.
- **R2 Status → tone mapping** in one PHP helper `App\Support\Format\Tone` (case status, document status, deadline
  state, report status, approval state) so every badge uses the same meaning; replaces scattered inline colour
  classes.
- **R3 Layout** `layouts/workspace.blade.php`: sidebar (≥ lg) built from one nav definition `App\Support\Access\Nav`
  (role-filtered items reusing the existing `DashboardNav`, `CommitteeNav`, `InternalRegulationNav` checks; badge
  counts: open reports in the HR inbox, pending approvals for the user — both through the existing visibility
  classes, cached per request), company name + user card + language switch + logout at the bottom; top bar with page
  title area and primary action slot; < lg: compact top bar + bottom tab bar (first 3 role items + "More" opening a
  sheet with the rest). `aria-current="page"` on the active item. Guest layout restyled to the mockup.
- **R4 Views**: rebuild every view in `resources/views/livewire/**` on the components, one screen at a time, keeping
  every `wire:*` binding, field name, button action, `@can`/role condition, translation key and test-asserted text.
  Long one-line views are reformatted for readability. Document preview areas keep their explicit `dir` and Amiri.
  The confirm-document screen keeps §18's distinct document card.
- **R5 Filament**: both panels `->colors(['primary' => Color::hex('#4F46E5'), …])`, `->font('Tajawal')`,
  `->brandName(fn () => __('Displanery'))` with `ar.json` "نظام الانضباط", favicon; custom Filament views use the same
  badge tones.
- **R6 Tests**: component rendering tests (badge tones, table responsive markup, field errors, nav filtered by role
  and showing counts, `aria-current`, brand name per locale); all existing screen tests must pass unchanged (they
  assert behaviour/text, not markup). Visual verification by the orchestrator (screenshots per screen × 2 widths × 2
  languages).

## Constitution Check
| Principle | Status | How |
|---|---|---|
| I. Tenancy | PASS | nav counts use existing visibility classes |
| II. AI boundary | PASS | no AI change |
| III. Immutable | PASS | document views/templates untouched; confirm screen keeps its gate |
| IV. Tests | PASS | full suite + component tests + screenshot pass |
| V. Thin UI | PASS | components are presentational; tone/nav helpers are small support classes |
| VI. Decided | PASS | mockup approved by product owner |

## Structure
```text
resources/views/components/ui/*.blade.php · resources/views/layouts/{workspace,guest}.blade.php
app/Support/Format/Tone.php · app/Support/Access/Nav.php
resources/views/livewire/** (all) · resources/css/app.css (component layer, if needed)
app/Providers/Filament/{Admin,Platform}PanelProvider.php · lang/ar.json · tests/Feature/Ui/*.php
```

## Delegation
Codex plan review → run D1 (components, Tone, Nav, layouts, Filament theme, cases/case-page screens) → my gates +
screenshots → run D2 (all remaining screens) → my gates + full screenshot pass (EN/AR × 1280/375) → PR.
