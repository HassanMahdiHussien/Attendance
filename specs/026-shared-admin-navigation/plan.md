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
