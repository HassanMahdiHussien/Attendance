# Feature Specification: One brand everywhere

**Feature Branch**: `052-brand-consistency` | **Created**: 2026-10-17 | **Status**: Approved

**Input**: Product owner (2026-10-17): "check all design, some pages have a different logo, check everything" → audit found 6 mismatches + an empty admin home; owner approved all 8 fixes.

## The brand (authority: DESIGN.md, the workspace top bar today)
The **split-flap mark**: a rounded amber tile (`--color-signal` #F2B33D) cut by a thin darker line across the middle (`--color-signal-dark` #DE9A1A), carrying a bold monospace **D** in night (#0E1A2B), followed by the name `__('Displanery')` (Arabic «نظام الانضباط»), on the midnight shell (`--color-night` #0E1A2B) where a top bar exists.

## Requirements
- **FR-001** One Blade component `x-ui.brand` (mark + name; props: size, show name, optional subtitle) used by every place that shows the brand. No other hand-drawn "D" marks remain.
- **FR-002** Guest layout (login, 403 for guests, error pages): same midnight top bar and mark as the workspace; the language switch stays.
- **FR-003** Filament admin and platform panels show the mark + name (`brandLogo`), not text only; favicon is the new one.
- **FR-004** `public/favicon.svg` redrawn as the split-flap mark; `public/favicon.ico` regenerated from it (32×32, PNG-in-ICO).
- **FR-005** Branded error pages 404, 419, 500, 503 (and 403 restyled to match) in English and Arabic (RTL), each with a way back (my work when signed in, else log in). They must render without a database or session (500/503 safe).
- **FR-006** Branded email layout for all notifications/mail: mark + name header, amber button, night text, footer; all Laravel boilerplate lines translated to Arabic; RTL when the recipient's locale is Arabic.
- **FR-007** Admin panel home is never empty: a shortcuts widget (employees, users, branches/locations, committee order, templates, company settings, open the workspace) always shown, setup checklist above it when setup is incomplete.
- **FR-008** Test: every page type renders `x-ui.brand` (workspace, guest/login, admin, platform, 404/419/500/503/403, email); no `bg-primary ... >D<` style marks remain; translation coverage passes.

## Success criteria
Every screen, error page and email shows the same mark; before/after screenshots in both languages reviewed.
