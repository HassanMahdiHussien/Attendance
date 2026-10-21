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

## R7 Plan review resolutions (Codex review, 2026-10-08) — override R1–R6 where they differ

### Blockers
- **A1 Behaviour inventory**: before rewriting any view, D1 generates `tests/fixtures/ui-inventory.json` from the
  current views (per view: every `wire:model*` target, `wire:click`/`wire:submit` method with arguments pattern,
  `wire:key` presence, `@error` keys, `@can`/`@if` role/permission conditions by expression, `<input type=file>`
  bindings, route/link targets). `tests/Feature/Ui/BehaviourInventoryTest.php` re-extracts the same from the rebuilt
  views and fails if anything is missing (additions are allowed only through an explicit, commented allowlist in the
  test). The legally significant screens (confirm-document incl. leave confirmation, second review, blockers,
  reference number, issue/send back; signed-scan incl. role-dependent override and appearance-only wording;
  hand-offs incl. witness scan; review-approval; record-session quorum) are also screenshot-verified in their key
  states by the orchestrator.
- **A2 Nav counts bounded**: the reports inbox shows a number (one `count()` over `ViolationReportVisibility::inbox()`
  + `EmailDraftVisibility::inbox()`); pending approvals show a **dot** using the existing `CommitteeNav::hasPending()`
  boolean — no per-document scan for a number. Both computed once per request (memoised in `Nav`). Tests: counts and
  dot correct per role and never include another company's rows.

### Should-fix
- **B1** `/home` (`livewire.home`) is added to D2; the drilldown view is verified under both the HR and the partner
  route.
- **B2** Existing markup-asserting tests (`RunMTest.php:426` `<strong>Findings</strong>`, `SignedScanBindingTest.php:93`
  `border-red`, locale `lang`/`dir`) are kept where they protect meaning (e.g. bold findings, red warning tone) —
  update the markup expectation deliberately and keep the meaning assertion; never delete such an assertion.
- **B3 Component contract**: `$attributes->merge()` lands on the real `<input>/<select>/<textarea>/<button>/<a>` so
  `wire:model*`, `wire:click`, `wire:key`, `type`, `accept`, `disabled`, `name` pass through; `<x-ui.field>` takes the
  error key explicitly (`error="presence.{{ $id }}"`) so nested keys work. Tables: each row rendered once; below `md`
  the same row is styled as a card and each cell shows its localized label from a `label` attribute on
  `<x-ui.td>`; dynamic tables (statistics preview) pass header labels from the same data used for the header row;
  empty rows use `<x-ui.empty>`.
- **B4 Toasts**: only for success outcomes. The layout shows `session('status')` once as a toast (works across
  redirects); components may dispatch a `notify` browser event for in-place successes. Validation/blocking errors
  stay inline next to their fields/actions (unchanged). Issuance and approval feedback keep their current
  post-redirect visibility (flash → toast).
- **B5 Accessibility**: badge text uses the darker shades (red-700, amber-700, green-700, indigo-600 on the light
  tint) as in the mockup — never the bright tokens as text; focus-visible rings on all interactive components; the
  "More" sheet traps focus, closes on Escape and returns focus to its trigger; bottom bar leaves content padding;
  directional icons flip in RTL; case/reference numbers wrapped in `<bdi>` with JetBrains Mono.
- **B6 Acceptance gates**: D1 and D2 each end with the full suite + Pint green and a recorded screenshot matrix
  (route, role, state, width 1280/768/375, locale en/ar) — confirm-document in draft, blocked and issued states.
  T004 is split into per-screen checkboxes.
- **Nits**: Filament already sets colours/font/`viteTheme`; T003 only adds the translated brand name, favicon and any
  measured adjustment in the existing theme file. Breakpoints: navigation switches at `lg`, tables at `md`; the
  matrix includes 768 px.

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
