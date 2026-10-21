# Implementation Plan: One Shared Menu

**Branch**: `026-shared-admin-navigation` | **Spec**: [spec.md](spec.md)

## Design
- `App\Support\Access\AdminNav::items(User)`: admin entries derived from the admin panel's registered resources and
  pages (for the user's company tenant), filtered by their own `canAccess()` / `canViewAny()`; returns
  `{label, url, icon, pattern}` like `Nav::item`. Replaces the "Settings" and "Internal regulation" entries in
  `Nav::items` (keep `InternalRegulationNav` only if still needed).
- Workspace layout: a collapsible "Settings" group under the main items (open state remembered in localStorage,
  wrapped in try/catch); the mobile "More" sheet lists the same entries.
- Admin panel: navigation items for a first "Workspace" group built from `Nav::items` (URL items); resources/pages
  placed in a translated "Settings" group with a sort order matching `AdminNav`.
- Theme: `resources/css/filament/theme.css` with §18 tokens — page bg #F7F8FB, white rounded-2xl cards with gray-200
  border and soft shadow, rounded-2xl buttons with indigo shadow, rounded-xl gray-200 inputs, active sidebar item in
  indigo-light like the workspace, indigo-tinted initials avatar; custom views under `resources/views/filament/**`
  switched to Filament section components or the same classes; RTL verified.
- The orchestrator builds assets and restarts the app container before browser checks.

## Tests
- `AdminNav` lists only pages the user can access (company admin vs HR staff vs legal reviewer); URLs scoped to the
  user's company.
- Workspace sidebar renders the Settings group; admin panel renders the Workspace group with the same main links.
- Behaviour inventory and translations updated; full suite + Pint; screenshots.

## Plan review resolutions

- **Blocker — access context:** `Page::canAccess()` and resource policies use the authenticated user and Filament tenant. Build admin links only for an active user who can access their company, with the admin panel and that tenant selected while asking the registered page/resource classes for their own navigation and access decisions. Never infer access from a role list or reuse `InternalRegulationNav` as a second rule. Page middleware and policies remain authoritative.
- **Blocker — navigation source and labels:** The registered Filament dashboard, pages, and resources are the source for Settings. Omit the dashboard from Settings because the workspace already has its dashboard and the admin dashboard remains reachable through the Filament brand/route; do not create a duplicate workspace Dashboard link. Preserve Filament navigation labels and class order in both surfaces. The resource named Employees and workspace Employees are separate destinations; retain both under their respective groups.
- **Blocker — panel rendering:** Filament v5 supports a custom `NavigationBuilder`; use it for Workspace first and Settings second, with `Nav::items()` for Workspace and the same `AdminNav` entries for Settings. This avoids static navigation-group mutation and handles translated labels at request time. Render Settings in the workspace sidebar and mobile More sheet from that same list.
- **Should fix — active and mobile state:** Match the current path against each admin URL including nested resource routes. Workspace links retain their existing patterns. Keep the Settings disclosure open on an active admin route; persist manual changes in guarded localStorage. Ensure controls remain keyboard reachable and RTL uses logical direction classes.
- **Should fix — visuals:** The existing Filament theme only changes a few colors. Apply the mockup's page, card, button, input, sidebar and avatar tokens in the shared theme, then update custom admin views that use bare black borders or controls. Keep semantic alert colors and document text styling.
- **Should fix — verification:** Add focused navigation tests for company admin, HR and regulation reviewer, both locales and tenant URLs. Only new UI controls are added to the behaviour inventory allowance; existing bindings and links remain. Docker gates and visual screenshots are delegated to the orchestrator because this environment has no Docker access.
- **Blocker — reviewer conflict:** `User::canAccessPanel('admin')` currently admits platform admin, company admin and HR staff only. A company legal reviewer cannot enter the admin panel even though `InternalRegulation::canAccess()` accepts the review permission. FR-005 and the instruction against authorization changes take precedence: do not advertise an inaccessible link or alter panel admission. The reviewer sees no Settings group until a separately authorized access change is approved.
- **Blocker — HR visibility conflict:** `BranchPolicy::viewAny()` allows HR staff while `create()` and `update()` do not. The branch list is readable and Filament currently shows it. Follow the requested page access checks, so HR sees Branches as a read-only destination. Likewise an HR reviewer with `review_internal_regulation` retains other HR-readable admin links. Making either persona see only Internal regulation or hiding readable Branches would require a separate navigation rule or policy change that contradicts FR-001/FR-005.
- **Should fix — avatar source:** Filament's default `UiAvatarsProvider` returns a black avatar image from an external service, so CSS cannot recolor it. Use one local indigo SVG avatar provider in both panels; keep the existing workspace initials format.
