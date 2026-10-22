# Feature Specification: Sign-in, Roles and Admin Screens

**Feature Branch**: `002-auth-roles-admin`

**Created**: 2026-10-05

**Status**: Draft

**Input**: User description: "Feature 002 = CLAUDE.md build step 2: authentication for internal users,
roles and permissions per CLAUDE.md §8 (one main role per user + add-on permissions), and the
back-office admin screens: Platform Admin (companies, partners, platform users) and company setup
(branches, locations, employees, users and reporter accounts, committee mandates and pools, company
settings). Also: switch the application clock to Asia/Baghdad, and record the product decision that
termination letters require an HR confirmation that the employee is not on statutory leave or
documented sick absence."

## Clarifications

### Session 2026-10-05

- Q: How are roles modelled? → A: Each user has one main role (the existing `primary_role`).
  Access rules live in policy code. Two add-on abilities can be toggled per user by a company admin:
  "review internal regulation" (the company's own lawyer, CLAUDE.md §8/§23.4) and "see all company
  cases" (widening HR staff beyond their own cases). `company_legal_reviewer` therefore stops being a
  main role and becomes an add-on.
- Q: Application timezone? → A: Asia/Baghdad.
- Q: How does the system know an employee is not on leave before a termination letter (PLATFORM-PRD
  §3.7)? → A: In Phase 1, HR ticks a mandatory confirmation on the termination issuance screen and the
  system records who confirmed it and when. This feature records the decision only; the screen is
  part of the workflow feature.
- Q: Who at the partner law firm sees a consultation-requested case? → A: Every external reviewer of
  the law firm partnered with that company (firm-wide team). Whoever takes it up is recorded when
  the workflow feature builds the consultation screen.
- Q: What happens to existing `company_legal_reviewer` accounts? → A: (orchestrator decision; only dev
  data exists) They must not gain access. They become deactivated `reporter` accounts that keep the
  "review internal regulation" add-on, for a company admin to reassign.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Internal users sign in safely (Priority: P1)

The Platform Admin, company admins and HR staff (the roles that have screens in this feature) sign
in with email and password. Accounts for the other roles (department managers, reporters, committee
members, external legal reviewers, HR-partner staff) are created and managed now. They can sign in
once their own screens exist (workflow and dashboards features), and until then any sign-in attempt
is refused like a wrong password. Repeated wrong passwords are slowed
down; idle sessions end; a deactivated user, or any user of a deactivated company, cannot sign in.
Every sign-in, sign-out and failed attempt is recorded. Employees and the General Manager have no
login.

**Why this priority**: Nothing else in the system can be used safely until identity and session
handling are right; external reviewer accounts span every company and are high-value targets.

**Independent Test**: Create users of each role and status; sign in, fail repeatedly, idle past the
limit, deactivate the user / the company, and inspect the audit trail.

**Acceptance Scenarios**:

1. **Given** an active user of an active company, **When** they enter correct credentials, **Then**
   they land on the area their role allows, and a sign-in entry is recorded.
2. **Given** 5 failed attempts for one email within a minute, **When** a 6th is tried, **Then** it is
   refused with a wait message, and failed attempts are recorded without storing the password typed.
3. **Given** a deactivated user, or an active user whose company is deactivated, **When** they try to
   sign in or continue an existing session, **Then** access is refused.
4. **Given** a signed-in user idle longer than their role's limit (60 minutes; 30 minutes for
   Platform Admin, external legal reviewer and HR-partner staff), **When** they act again, **Then**
   they must sign in again.

---

### User Story 2 - The Platform Admin runs the platform (Priority: P1)

The service owner manages tenant companies (create, edit letterhead and languages, activate /
deactivate, subscription status), partner organisations (law firms, HR partners) and platform-level
accounts (other platform admins, external legal reviewers, HR-partner staff), and can enter any
company's setup area to help with onboarding.

**Why this priority**: No company can be onboarded without it.

**Independent Test**: As Platform Admin, create a company with its first company admin, deactivate
and reactivate it, create a law firm with a reviewer account, and open that company's setup area.

**Acceptance Scenarios**:

1. **Given** the Platform Admin, **When** they create a company, **Then** Arabic and English are
   enabled and cannot be switched off, Kurdish is off by default, and the subscription status starts
   as trial.
2. **Given** a company, **When** the Platform Admin deactivates it, **Then** its users can no longer
   sign in, its data is kept, and the action is audit-logged.
3. **Given** the Platform Admin, **When** they create an external legal reviewer, **Then** the account
   belongs to a law-firm partner and to no company.
4. **Given** the Platform Admin, **When** they open a company's setup area, **Then** they see and
   manage that company's records only, and the company switch is explicit.
5. **Given** any non-platform user, **When** they try to reach the platform area by any URL, **Then**
   access is refused.

---

### User Story 3 - A company admin sets up their company (Priority: P1)

A company admin (HR manager) maintains branches (with jurisdiction), branch locations, employees
(the employee number is generated if left blank; one General Manager can be flagged), user accounts
for their company (HR staff, department managers, reporters, committee members) with branch scope
and add-on abilities, the committee Administrative Order with its pool of alternates per role, and
company settings (letterhead, extra languages, mandatory second review before issuance).

**Why this priority**: The case workflow needs this data to exist and be correct.

**Independent Test**: As a company admin of the pilot company, add a branch and location, add an
employee without a number, create a reporter scoped to one branch, register a new Administrative
Order and its pool, and toggle second review.

**Acceptance Scenarios**:

1. **Given** a company admin, **When** they add an employee without an employee number, **Then** the
   next `EMP-` number is assigned; with a number, it is kept; a duplicate number is refused.
2. **Given** a company admin, **When** they flag a second employee as General Manager, **Then** they
   are told only one General Manager can be flagged at a time and must unflag the current one.
3. **Given** a company admin, **When** they create a user, **Then** they can only pick company roles
   (never Platform Admin, external reviewer, HR-partner staff, or a General Manager role), can link
   the user to one of their employees, can scope reporters and department managers to branches, and
   can toggle the two add-on abilities; the action is audit-logged.
4. **Given** a company admin registering a new Administrative Order, **When** it is saved, **Then** the
   previous order is marked superseded from the new order's effective date, and both remain viewable.
5. **Given** a pool member, **When** they are added, **Then** they have a role (legal / HR / labour
   union), an optional branch, and optional links to an employee and to a committee-member login.
6. **Given** HR staff (not admin), **When** they use the setup area, **Then** they can manage
   employees, locations and reporter accounts, but not other users, company settings or the
   Administrative Order.

---

### User Story 4 - Everyone sees only what their role allows (Priority: P2)

Visibility rules from CLAUDE.md §8 are enforced in the data layer, not just hidden in screens: HR
staff see cases they opened or are assigned (or all company cases with the add-on); committee
members see only cases where they are an attendee; external legal reviewers see only cases where
consultation was requested, across companies; HR-partner staff see statistics only, unless a company
opted in to full case access; reporters see only the bare status of their own reports.

**Why this priority**: The case screens come later, but the rules must be fixed and tested now so
every later screen inherits them.

**Independent Test**: Seed cases across two companies with different openers, assignees,
attendees and consultation flags; ask each role's visibility rule which cases it may list and open.

**Acceptance Scenarios**:

1. **Given** HR staff without the add-on, **When** case visibility is evaluated, **Then** only cases
   they opened or are assigned to are visible; with the add-on, all their company's cases.
2. **Given** a committee member, **Then** only cases where their pool membership is recorded as
   attending are visible.
3. **Given** an external legal reviewer, **Then** only cases (any company) with a consultation request
   whose company is partnered with their law firm are visible.
4. **Given** HR-partner staff, **Then** no case details are visible unless the case's company is
   partnered with their HR partner and opted in to full access.
5. **Given** a reporter, **Then** no case is visible; only their own reports' status is.
6. **Given** a user of company A of any role, **Then** no record of company B is visible.

### Edge Cases

- A user whose role changes keeps no leftover abilities from the old role; add-ons that make no
  sense for the new role are removed.
- A company admin cannot deactivate or demote themselves if they are the company's last active admin.
- Deactivating a user ends their open sessions.
- An external reviewer's law-firm partnership with a company is removed → that company's cases
  disappear from their view immediately.
- Platform Admin switching between companies never mixes records of two companies on one screen.
- Committee members and reporters have no access to the setup area at all.
- The pool can contain people who are not employees (name only) and people without logins.
- Times shown and stored for legal events use Asia/Baghdad; a document issued at 00:30 on
  1 January Baghdad time gets the new year's reference sequence.

## Requirements *(mandatory)*

### Functional Requirements

**Authentication & sessions**

- **FR-001**: Internal users with an area in this feature (Platform Admin, company admin, HR staff)
  MUST sign in with email + password. Other roles' sign-in attempts are refused with the same generic
  message as a wrong password, so roles are not revealed. Employees and the General Manager have no
  login.
- **FR-002**: Sign-in MUST be throttled: after 5 failed attempts for one email (trimmed, lower-cased)
  within a minute, every further attempt for that email, even with the correct password, is refused
  until the window passes; per-IP limiting also applies. A refused-because-of-role or inactive
  attempt counts as a failure. Unknown email, wrong password, inactive account and wrong panel MUST
  produce the identical public response.
- **FR-003**: Idle sessions MUST expire after 60 minutes, or 30 minutes for Platform Admin, external
  legal reviewers and HR-partner staff.
- **FR-004**: Deactivated users, and users of deactivated companies, MUST be refused at sign-in and
  on every subsequent authenticated request of any kind (panel page, Livewire update, any later
  route); deactivation MUST end existing sessions.
- **FR-005**: Sign-in, sign-out and failed sign-in MUST be written to the audit log (email attempted,
  IP, outcome; never the password).

**Roles & add-ons**

- **FR-006**: Each user MUST have exactly one main role. Company roles: company admin, HR staff,
  department manager, reporter, committee member. Platform roles: Platform Admin, external legal
  reviewer, HR-partner staff. No General Manager role.
- **FR-007**: Two add-on abilities MUST be assignable to company users by a company admin: "review
  internal regulation" (any company role) and "see all company cases" (HR staff only).
  `company_legal_reviewer` stops being a main role; existing rows MUST be migrated without gaining
  access (deactivated reporter + "review internal regulation" add-on).
- **FR-008**: Access decisions MUST be made in policy classes / query scopes, never only by hiding UI.

**Platform area (Platform Admin only)**

- **FR-009**: Manage companies: name, letterhead (logo, address, phone, email), enabled languages
  (Arabic + English locked on; others optional, Kurdish off by default), active flag, subscription
  status (trial / active / suspended / cancelled), second-review setting.
- **FR-010**: Create a company together with its first company admin account.
- **FR-011**: Manage law-firm partners and HR partners, their company partnerships (HR partner
  "full case access" opt-in per company), and platform accounts (Platform Admin, external legal
  reviewer tied to a law firm, HR-partner staff tied to an HR partner).
- **FR-012**: Enter any company's setup area through an explicit company switch.

**Company setup area (company admin; HR staff limited per FR-018)**

- **FR-013**: Manage branches (name, address, jurisdiction federal Iraq / Kurdistan Region, active)
  and their locations.
- **FR-014**: Manage employees (number, name, department, position, hire date, branch, email,
  General Manager flag — at most one active per company — active flag); never hard-delete.
- **FR-015**: Manage company users (company roles only), link to an employee (one-to-one), branch
  scope for reporters and department managers, add-on abilities, activate / deactivate; the company's
  last active company admin cannot be deactivated or demoted.
- **FR-016**: Manage Administrative Orders (number, date, effective from, uploaded scan) — a new order
  MUST have an effective date later than the latest existing order's and supersedes it from that
  date (a future-dated order leaves the current one in force until then; backdating before the latest
  order is refused, so effective periods never overlap) — and each order's pool members (role, branch
  or company-wide, name, optional employee link, optional committee-member login link).
- **FR-017**: Manage company settings: letterhead, optional extra languages, mandatory second review.
- **FR-018**: HR staff MAY manage employees, locations and reporter / department-manager accounts;
  only company admins manage other users, settings, branches and Administrative Orders.
- **FR-019**: Reporters, department managers and committee members MUST NOT access the setup area.

**Visibility rules (data layer)**

- **FR-020**: A single case-visibility rule per role MUST implement User Story 4 and be used by every
  future case query and policy.
- **FR-021**: External legal reviewers and HR-partner staff MUST have no default company context;
  their cross-company reads happen only through the visibility rule (fail closed otherwise).

**Audit**

- **FR-022**: Creating, editing, activating / deactivating users and companies, role and add-on
  changes, partnership changes and Administrative Order changes MUST be written to the audit log with
  actor and before/after values (no secrets).

**Cross-cutting**

- **FR-023**: The application clock MUST be Asia/Baghdad; legal-event timestamps and the
  reference-number year follow it.
- **FR-024**: Admin screens MUST be Arabic, right-to-left, using the CLAUDE.md §18 tokens and Tajawal.
- **FR-025**: CLAUDE.md §3 and WEIRDNESS.md MUST record the termination / leave decision (HR
  confirmation checkbox, recorded who and when) for the workflow feature to implement.

### Key Entities

- **User** (existing): main role, company or none, optional employee link, partner link, active,
  add-on abilities, branch scope.
- **Add-on ability**: named permission toggled per company user.
- **Company / Branch / Location / Employee / Committee Mandate / Pool Member / Partners** (existing,
  from feature 001) — now managed through screens.
- **Audit log entry** (existing): actor, action, entity, before/after metadata, IP.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: For every role, automated checks prove the exact set of setup screens and actions it can
  and cannot reach (100% of role × screen combinations covered).
- **SC-002**: For every role, automated checks prove the case-visibility rule returns exactly the
  expected cases on a mixed two-company dataset (0 extra, 0 missing).
- **SC-001b**: Every allowed / refused cell of contracts/access-matrix.md is tested through direct URL
  and action calls, not only navigation visibility, including record IDs of another company.
- **SC-003**: Timed manual walkthrough (quickstart V10), from opening the create-company form to the
  last pool member saved: the Platform Admin can onboard a new company with its first admin, one branch and one
  Administrative Order with a full pool in under 15 minutes.
- **SC-004**: 100% of user / company / role / add-on / partnership / mandate changes produce an audit
  entry with actor and before/after values.
- **SC-005**: Deactivated users and users of deactivated companies are refused on 100% of attempts,
  including already-open sessions.

## Assumptions

- Back-office screens use Filament v5 (ADR 0009) with Arabic RTL theme; the case workflow screens
  are not part of this feature.
- Two separate admin areas: a platform area for the Platform Admin and a company setup area with
  company switching (Filament tenancy, tenant = company, mapped into the existing tenant context).
- Password reset by email uses the framework default; email delivery is configured per environment.
- Committee-member single-use invite links (CLAUDE.md §8) need a case to point at, so they belong
  to the workflow feature; here committee members are ordinary accounts.
- CSV bulk employee import is a separate later feature (build step 7).
- Legal-library screens (law import, mismatch queue, regulation approval) are a separate later
  feature (CLAUDE.md §22–23).
- Dashboards for external reviewers and HR partners come with the dashboards feature; here only
  their accounts and visibility rules exist.
