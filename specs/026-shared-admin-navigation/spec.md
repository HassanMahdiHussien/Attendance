# Feature Specification: One Shared Menu for the Workspace and the Admin Panel

**Feature Branch**: `026-shared-admin-navigation`

**Created**: 2026-10-10

**Status**: Draft

**Input**: Product-owner feedback (2026-10-10): "when I click on Internal regulation dashboard be different and see
another options." Decision (2026-10-10): "One shared menu" — restyle the admin panel to match the workspace and share
navigation both ways.

## Clarifications

### Session 2026-10-10

- Q: What is wrong today? → A: "Internal regulation" and "Settings" in the workspace sidebar open the Filament admin
  panel (`/admin/{company}`), which has a different look (black borders, black avatar, different spacing) and its own
  menu (Dashboard, Company settings, Document templates, Internal regulation, Branches, Administrative Orders,
  Employees, Locations, Company Users, Violation Types) with no way back to My work, Cases, Reports inbox, Statistics.
- Q: The fix? → A: (1) The admin panel adopts the workspace look (CLAUDE.md §18 tokens: indigo primary, Tajawal UI
  font, rounded-2xl cards and buttons with a soft indigo shadow, light gray page, gray-200 borders, same RTL
  behaviour) — including the custom Filament pages (internal regulation, document templates, setup checklist).
  (2) The admin panel menu starts with a "Workspace" group holding the same main links the workspace sidebar shows the
  user (exactly per `Nav::items` role rules). (3) The workspace sidebar gets a collapsible "Settings" group listing the
  admin pages the user may open, replacing the single "Settings" and "Internal regulation" links. Both menus use the
  same labels and order so moving between them feels like one site.
- Q: Who? → A: Company admins and HR staff (each sees only the admin pages their policies allow; a company legal
  reviewer keeps seeing only Internal regulation). Platform Admin's `/platform` panel only gets the shared look.
- Q: Phones? → A: The workspace "More" sheet lists the Settings group too; the admin panel's mobile menu shows the
  Workspace group.

## User Scenarios & Testing *(mandatory)*

1. **Given** a company admin on the case board, **When** they open Settings → Internal regulation, **Then** the page
   looks like the rest of the app and its menu still shows My work, Cases, etc.; clicking Cases returns to the board.
2. **Given** HR staff who may not manage branches, **Then** neither menu offers Branches.
3. **Given** a company legal reviewer, **Then** they see only Internal regulation in Settings.
4. **Given** Arabic, **Then** both menus are right-to-left and translated.

## Requirements *(mandatory)*

- **FR-001**: One source for the admin link list (label, URL, icon, access check) used by the workspace sidebar and
  mobile sheet; visibility follows each Filament resource/page access check, never a duplicated rule.
- **FR-002**: The admin panel navigation adds a "Workspace" group generated from `Nav::items` (same role rules, same
  order), shown first.
- **FR-003**: The Filament theme and panel config use the §18 tokens and fonts; custom admin page views use the same
  visual vocabulary (no black 1px borders, no default black avatar).
- **FR-004**: Active-state highlighting works in both menus.
- **FR-005**: No authorisation change: hiding a link is cosmetic; every admin page keeps its own access checks.

## Success Criteria

- From any admin page a user reaches My work/Cases in one click and vice versa; admin pages look consistent with
  workspace pages (desktop + 375px, en + ar).
