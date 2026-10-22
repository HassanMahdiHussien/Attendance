# Feature Specification: Design System, Sidebar Layout and Mobile

**Feature Branch**: `017-design-system`

**Created**: 2026-10-08

**Status**: Draft

**Input**: Product owner, 2026-10-08: "make UI more professional and more useful" — chose a consistent design system,
smarter home, faster case work and mobile-friendly; approved the mockup `docs/design/ui-refresh-mockup.html` as shown.
This feature is the design system + layout + mobile part; the role home (018) and search/next-step (019) follow.

## Clarifications

### Session 2026-10-08

- Q: Visual direction? → A: The approved mockup: CLAUDE.md §18 tokens and fonts, a left sidebar (right in Arabic) with
  counts, a slim top bar, cards with soft borders, pill badges, tables for lists, rounded-2xl buttons with the indigo
  shadow; on phones one column and a bottom tab bar.
- Q: Scope? → A: Every workspace screen (cases, case page and its sub-screens, reports, committee, employees,
  dashboard, statistics, login) rebuilt on shared components, in both languages. Filament admin panels keep Filament's
  layout but get the brand colours/fonts and a translated brand name. No new features: the global search box and the
  "my work" home arrive in 019/018 — 017 does not show placeholders for them.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - One consistent, professional look (Priority: P1)

Any staff user sees the same layout everywhere: sidebar with the sections their role may open (active item
highlighted, badge counts for the reports inbox and pending approvals), page header with title, subtitle and primary
action, content in cards, lists as tables or card rows, consistent badges for every status, consistent empty states
("No cases yet" + next action) and success/error toasts after actions.

**Acceptance Scenarios**:
1. **Given** any workspace screen, **Then** it uses the shared layout and components (no screen keeps its own
   one-off header/nav markup).
2. **Given** a status anywhere (case stage, document state, deadline, report state), **Then** it renders through the
   one badge component with the same colour meaning (red urgent, amber pending, green done, indigo active, gray
   neutral).
3. **Given** a list with no rows, **Then** an empty state explains why and offers the relevant action.
4. **Given** the document confirmation/issuance screen, **Then** it keeps its distinct look (gray document card, Amiri)
   per §18.

### User Story 2 - Works on a phone (Priority: P1)

**Acceptance Scenarios**:
1. **Given** a 375 px wide screen, **Then** every workspace screen has no horizontal page scroll, readable text,
   tap targets ≥ 40 px, the sidebar replaced by a bottom tab bar (+ "More" sheet), and tables collapse into card rows.
2. **Given** a long form (new case, hand-off, committee session), **Then** fields stack in one column with labels above.

### User Story 3 - Both languages look right (Priority: P1)

**Acceptance Scenarios**:
1. **Given** Arabic, **Then** the sidebar is on the right, icons that imply direction flip, and spacing mirrors.
2. **Given** the admin panels in Arabic, **Then** the brand reads "نظام الانضباط" (in English "Displanery").

## Requirements *(mandatory)*

- **FR-001**: Shared Blade components for layout, sidebar/nav, bottom tab bar, page header, card, stat tile, badge,
  button (primary/secondary/danger/ghost), table + responsive card rows, form field/input/select/textarea/file,
  empty state, alert, toast, tabs, stepper (5-stage).
- **FR-002**: All workspace views rebuilt on them; behaviour, routes, Livewire actions and permissions unchanged.
- **FR-003**: Responsive down to 360 px; direction-neutral in both languages.
- **FR-004**: Filament panels themed (colours, Tajawal, brand name translated, favicon/logo mark).
- **FR-005**: Accessibility basics: landmarks, focus rings, labels on inputs, `aria-current` on the active nav item,
  colour contrast ≥ 4.5:1 for text.

## Success Criteria *(mandatory)*

- **SC-001**: Side-by-side with the approved mockup, the cases list, case page and nav match its look.
- **SC-002**: All existing tests pass (behaviour unchanged); new component tests pass.
- **SC-003**: Screenshots of every workspace screen at 1280 px and 375 px, in English and Arabic, show no overflow or
  broken layout.

## Assumptions

- Icons: inline SVG set embedded in a Blade icon component (no new package); the mockup's emoji are placeholders.
