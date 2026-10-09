# Feature Specification: Company Setup Checklist Before the First Case

**Feature Branch**: `020-setup-gate`

**Created**: 2026-10-09

**Status**: Draft

**Input**: CLAUDE.md §23.5 "Company onboarding (first-run, required before the first case)" — gap found in the
pilot-readiness audit (2026-10-08): today a company can open cases before its letterhead, committee order or
internal-regulation answer exist; a missing committee order is only caught at the committee session.

## Clarifications

### Session 2026-10-09

- Q: What must be complete before the first case? → A: (1) company letterhead with name and logo or header image;
  (2) at least one branch, every branch with a jurisdiction; (3) a committee Administrative Order in effect today with
  at least one active pool member in each role (legal, HR, labour union) for every branch (company-wide or
  branch-specific members); (4) enabled document languages (Arabic and English always; optional others); (5) the
  question "Do you have a Ministry-certified internal regulation?" answered — if yes, the regulation uploaded and sent
  to the company lawyer (approval itself is not required to open cases; until approved, only the law is cited).
- Q: Existing companies? → A: Companies that already have cases are treated as answered (derived from their current
  regulation flag) so nothing in use is blocked; every other rule still applies to new cases.
- Q: Who sees the checklist? → A: The company admin (and Platform Admin inside the company) on the admin dashboard
  and on the home page while incomplete; HR staff trying to open a case see what is missing and who must fix it.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - The admin sees what is left (Priority: P1)

A new company's admin logs in: the admin dashboard and the "My work" home show "Finish setting up your company" with
each item, its state (done / missing, with the reason) and a link to the screen that fixes it. When everything is
done, the checklist disappears.

### User Story 2 - No case opens on an incomplete setup (Priority: P1)

**Acceptance Scenarios**:
1. **Given** a company without a committee order, **When** HR tries to open a case (draft → open), **Then** it is
   refused with a translated message naming the missing items and saying the company admin must complete setup.
2. **Given** complete setup, **Then** opening works as today.
3. **Given** a company that already had cases before this feature, **Then** it keeps working (answer derived).
4. **Given** a draft case, **Then** drafting and reports are not blocked — only opening (which starts the legal
   procedure).

## Requirements *(mandatory)*

- **FR-001**: One `CompanySetup` service is the single source of the checklist (items, state, reason, fix link).
- **FR-002**: `CaseOpening::open` refuses when the checklist is incomplete (before any other change).
- **FR-003**: Checklist widget on the Filament admin dashboard and a card on the company admin's home.
- **FR-004**: A recorded answer to the regulation question (yes/no, who, when) — editable by the company admin in
  company settings; "yes" links to the regulation upload.
- **FR-005**: Translated (en/ar), design-system components; tests per item, gate, tenant isolation, existing
  companies.

## Success Criteria *(mandatory)*

- **SC-001**: No case can be opened in a company whose checklist is incomplete (automated tests per item).
- **SC-002**: The pilot company in dev keeps working after the migration.

## Assumptions

- "In effect today" uses Asia/Baghdad dates and the existing mandate scope.
