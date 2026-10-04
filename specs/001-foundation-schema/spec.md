# Feature Specification: Platform Foundation and Core Data Model

**Feature Branch**: `001-foundation-schema`

**Created**: 2026-10-04

**Status**: Draft

**Input**: User description: "Feature 001 = CLAUDE.md build steps 0 + 1: project setup (application
skeleton, Arabic RTL shell, background job queue and scheduler wired) plus the complete core data
model for the discipline module (companies, branches with jurisdiction, employees, committee
mandates and pools, violation reports, cases, case events / audit log, documents with
immutability and versioning, signatures, and the rest of docs/ERD.md), with tenant isolation and
append-only audit enforced from the first migration and tested. Also reconcile the stale parts of
docs/ERD.md and docs/LAUNCH-CHECKLIST.md with CLAUDE.md."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Each company's data stays inside that company (Priority: P1)

A company's HR staff work with their own employees, branches, committee pool, reports, cases and
documents. Whatever they ask the system for, they only ever get their own company's records — even
if they guess another company's record identifier. The Platform Admin (the service owner) can see
every company's records on purpose.

**Why this priority**: The whole product is multi-tenant on a shared database. One leak of another
company's disciplinary records is a legal and commercial catastrophe, so isolation must exist before
any feature is built on top of the data.

**Independent Test**: Create two companies with data in every tenant-owned record type, act as a
user of company A, and confirm that listing, fetching by identifier, counting, and creating records
never touches company B's data; then act as Platform Admin and confirm both are visible.

**Acceptance Scenarios**:

1. **Given** companies A and B each with employees, cases and documents, **When** a company-A user
   lists or counts any of these, **Then** only company-A records are returned.
2. **Given** a company-B case identifier, **When** a company-A user fetches it directly, **Then** it
   is treated as not found.
3. **Given** a company-A user creating a new record, **When** it is saved, **Then** it is stamped
   with company A automatically, and an attempt to save it under company B is rejected.
4. **Given** a Platform Admin, **When** they list cases, **Then** cases of all companies are
   returned.
5. **Given** an action with no signed-in user (background job, scheduler, data import), **When** it
   reads tenant data, **Then** it must explicitly state which company (or all companies) it works
   on; it never silently reads across companies.

---

### User Story 2 - Legal records cannot be silently changed (Priority: P1)

Once a document is issued, its text, reference number and issue date are frozen forever. A
correction produces a new version that points back to the old one, records who made it and why,
and leaves the original intact. The case history and the security audit trail can only grow —
nothing in them can be edited or removed, not even when a case is archived or a company leaves.

**Why this priority**: These documents may be produced as evidence before a labour court. If any
issued text or log entry could be edited, the company's position collapses.

**Independent Test**: Issue a document, then attempt to change its text, reference number or issue
date and attempt to delete it; attempt to edit or delete case-history and audit entries; confirm
every attempt is refused and that a proper correction creates a linked new version.

**Acceptance Scenarios**:

1. **Given** an issued document, **When** anyone tries to change its body text in any language, its
   reference number or its issue date, **Then** the change is refused and the stored record is
   unchanged.
2. **Given** an issued document, **When** a correction is started with a reason, **Then** a new
   draft version is created (version number + 1) linked to the old one, and the correction (who,
   when, why) is recorded in the case history; **When** that new version is issued, **Then** the old
   one is marked superseded and points to the new one. An abandoned correction leaves the old
   document valid and untouched.
3. **Given** a draft document, **When** it is edited, **Then** the edit is allowed and no reference
   number is consumed.
4. **Given** a document being issued, **When** issuance completes, **Then** it receives the next
   reference number in its company's sequence for that year (or a number typed manually by the
   reviewer), and two issuances can never receive the same generated number.
5. **Given** any case-history, audit or AI-generation log entry, **When** anyone tries to edit or
   delete it, **Then** the attempt is refused.

---

### User Story 3 - The pilot company's real structure is ready to work with (Priority: P2)

The system starts with a realistic pilot company (anonymised from the real Al Waha Hilla
Administrative Order): one company, its Hilla branch in federal Iraq, a committee mandate (order
1097) whose pool has 5 HR alternates, 2 Legal alternates and 2 Labour Union alternates, a set of
employees (including the General Manager as a paper signer only), branch locations, and the three
built-in statutes registered with their jurisdictions (text import comes later). Every employee has
an employee number — the company's own where given, otherwise generated in sequence.

**Why this priority**: Later features (quorum, conflict-of-interest, documents) need real-shaped
data to be built and tested against from day one, as CLAUDE.md §10 requires.

**Independent Test**: Load the seed data into an empty system and confirm the counts and
relationships above, that no real personal names from the Administrative Order appear, and that
employees created without a number receive one.

**Acceptance Scenarios**:

1. **Given** an empty system, **When** the seed data is loaded, **Then** the pilot company, its
   branch (federal Iraq), mandate 1097 and its 9 pool members (5 HR / 2 Legal / 2 Union) exist.
2. **Given** an employee created without an employee number, **When** it is saved, **Then** it
   receives the next number in the company's sequence (e.g. `EMP-0001`), never blank; an employee
   created with the company's own number keeps it.
3. **Given** a new case, **When** it is created, **Then** it receives the next case number in its
   company's sequence, independent of document reference numbers.
4. **Given** the seed data, **When** inspected, **Then** Labor Law 37/2015 applies to federal Iraq,
   Labor Law 71/1987 to the Kurdistan Region, and Social Security Law 18/2023 to both.

---

### User Story 4 - A working Arabic-first application shell (Priority: P3)

The developer can start the full application locally with one command, open it in a browser and
see an Arabic, right-to-left page using the confirmed design tokens and fonts; background jobs are
processed by a worker and scheduled tasks run on a schedule; the automated test suite runs with one
command.

**Why this priority**: Every later feature depends on this, but on its own it delivers no business
behaviour, so it ranks below the data guarantees.

**Independent Test**: From a fresh clone, follow the quickstart, open the home page, dispatch a
test job and see it processed, and run the test suite to green.

**Acceptance Scenarios**:

1. **Given** a fresh clone and the documented prerequisites, **When** the developer follows the
   quickstart, **Then** the application, database, job worker and scheduler run locally.
2. **Given** the running app, **When** the home page is opened, **Then** it renders right-to-left
   in Arabic with the Tajawal interface font and the design tokens from CLAUDE.md §18.
3. **Given** the running app, **When** a background job is dispatched, **Then** the worker processes
   it without a web request waiting on it.

---

### Edge Cases

- Two documents in the same company issued at the same moment must not receive the same generated
  reference number; the sequence restarts each calendar year per company.
- A manually typed reference number that duplicates an existing one in the same company and year is
  rejected.
- A document that is drafted and later abandoned never consumes a reference number.
- Deleting a company, branch, employee or case that legal records depend on is refused (records are
  deactivated, not destroyed); company cancellation never deletes audit or case-history rows.
- A committee pool member with no system login is still a valid pool member; one with a login is
  linked to it.
- An alleged responsible person who is not an employee (e.g. a contractor) is stored as free text;
  one who is an employee is linked to the employee record.
- A user record for an employee is optional and at most one per employee (employees have no login in
  Phase 1 but will later).
- A branch whose jurisdiction changes later must not change the law version recorded on existing
  cases.
- A document's language versions: Arabic is always present and authoritative; a language that is not
  enabled for the company cannot be added.

## Requirements *(mandatory)*

### Functional Requirements

**Tenant isolation**

- **FR-001**: Every record that belongs to a company MUST be tied to exactly one company, either
  directly or through its parent record.
- **FR-002**: For any signed-in company user, every read, count and write of company-owned records
  MUST be limited to their company automatically, at the data-access layer, not only in screens.
- **FR-003**: New company-owned records MUST be stamped with the acting user's company; saving a
  record under a different company MUST be refused.
- **FR-004**: The Platform Admin MUST be able to read all companies' records through an explicit,
  intentional path. Other cross-company roles (External Legal Reviewer, HR Partner) are modelled
  (users with no company) but their access rules are deferred to the roles feature.
- **FR-005**: Background jobs, scheduled tasks and imports MUST declare the company scope they work
  in; unscoped cross-company reads outside that declared path MUST NOT happen.

**Legal record integrity**

- **FR-006**: An issued document's body (every language), reference number, issue date, type and
  version MUST be unchangeable; a delete of an issued document MUST be refused.
- **FR-007**: A correction to an issued document MUST create a new draft version linked to the
  previous one and record actor and reason in the case history; the previous version becomes
  superseded (pointing to the new one) only when the new version is issued.
- **FR-007b**: Issuance MUST require text in every language enabled for the company, record the
  confirming user, require a second reviewer (different person) when the company enables it, and
  for the recommendations document require three valid approvals (legal, HR, labour union).
- **FR-007c**: Once a document is issued, no language version or citation may be added, changed or
  removed.
- **FR-008**: Document reference numbers MUST be assigned only at issuance, sequential per company
  per calendar year, unique within company + year, and MAY be overridden by a manually entered
  value at issuance; generated numbers skip values already taken manually.
- **FR-009**: Case numbers MUST be assigned at case creation, sequential per company, and displayed
  separately from document reference numbers.
- **FR-010**: Case-history entries, the system audit log and AI-generation log entries MUST be
  append-only: no edit and no delete through the application, ever.
- **FR-011**: Every case-history entry and audit entry MUST store actor (empty for system/AI),
  timestamp, event type and structured details.
- **FR-012**: Each document MUST record whether it was drafted by AI or by a human, and store its
  text as one entry per language, with Arabic marked authoritative.

**Data model coverage** (the complete discipline schema per CLAUDE.md and the reconciled ERD)

- **FR-013**: The system MUST store companies (letterhead details, enabled languages — Arabic and
  English always on, active flag, internal-regulation flag, issuance second-review setting), branches
  (with jurisdiction: federal Iraq or Kurdistan Region), branch locations, and employees (company
  employee number, name, department, position, hire date, branch, General-Manager flag, active flag).
- **FR-014**: The system MUST store committee mandates (order number, order date, effective from,
  superseded at, source file) and their pool members (role: legal / HR / labour union; optional
  branch; optional link to an employee and to a user login).
- **FR-015**: The system MUST store violation reports (source: reporter or email forward, branch,
  location or free-text place, time, violation type, alleged responsible person as employee link or
  free text, description, witnesses, status visible to the reporter, link to the case it became).
- **FR-016**: The system MUST store cases with every field in CLAUDE.md §3 and §7 (status from the
  §7 state list, employee, branch, location, violation details, alleged responsible person, law
  version at opening, consultation request who/when, defense-letter issue time, include-prior-record
  flag, opened by), case witnesses, case links (prior record / related / duplicate merge), committee
  session attendance with per-attendee vote and chair flag, and appeal tracking.
- **FR-017**: The system MUST store documents (type: defense letter / recommendations / decision
  letter; status; version; supersession link; reference number; issue time; drafted-by; file path),
  document language contents, document approvals (role category, decision, required comment on
  rejection, languages present at approval), and signatures (signer, role, status signed / refused
  to sign / delivery refused, two witnesses, attempt place and time, presence-check result).
- **FR-018**: The system MUST store laws (statute or internal regulation, owning company for
  regulations, source file, effective from, repealed at, approval), law-to-jurisdiction
  applicability (many-to-many), versioned legal units (article number, clause path, official text,
  source page, two extractions, verification status, tags), and citations from documents to the
  exact legal-unit version.
- **FR-019**: The system MUST store users with an optional company (empty for Platform Admin,
  External Legal Reviewer and HR Partner staff), a primary role from the CLAUDE.md §8 list (no
  General Manager role), an optional one-to-one employee link, and optional partner affiliation (law
  firm or HR partner).
- **FR-020**: There MUST NOT be a separate employee-violations table; an employee's history is
  their prior cases.
- **FR-021**: Records that legal documents depend on MUST NOT be hard-deleted; they are deactivated.
- **FR-021b**: A record MUST NOT reference another company's records through any link (employee,
  branch, location, mandate, case, document, user), enforced by the database where possible.
- **FR-021c**: The schema MUST hold what later role rules need: case assignee, user branch scope,
  company subscription status.

**Seed data**

- **FR-022**: The system MUST provide seed data for the anonymised pilot company as described in
  User Story 3, plus a Platform Admin account and one company HR admin account for local use, with
  credentials kept out of version control except in the example environment file.
- **FR-023**: Employees saved without a company employee number MUST receive a generated
  per-company sequential number.

**Application shell**

- **FR-024**: The application MUST run locally in containers with one start command, including the
  database, a background job worker and the scheduler.
- **FR-025**: The base page layout MUST be Arabic, right-to-left, using the CLAUDE.md §18 tokens and
  fonts (Tajawal for interface, Amiri reserved for document previews, JetBrains Mono for reference
  numbers).
- **FR-026**: The automated test suite and code formatter MUST each run with one command and pass.

**Documentation**

- **FR-027**: `docs/ERD.md` MUST be updated to match CLAUDE.md and the implemented schema (remove the
  obsolete legal-articles / company-excerpts / content-snapshot references, add the missing tables
  and columns); `docs/LAUNCH-CHECKLIST.md` MUST mark the two items CLAUDE.md has already resolved
  (sensitive-case trigger = request-only; leave does not pause deadlines).

### Key Entities

- **Company**: a tenant; letterhead, enabled languages, active flag, regulation flag, settings.
- **Branch**: a company site with a legal jurisdiction; has locations, employees, cases.
- **Location**: a named place within a branch (production line, warehouse, gate) for hotspots.
- **Employee**: a person employed by a company; may later get a user login; may be General Manager.
- **User**: a login; belongs to one company or to none (platform-level roles).
- **Committee Mandate / Pool Member**: the versioned Administrative Order and its alternates per role.
- **Violation Report**: a submission by a reporter or an email forward; becomes a case via HR.
- **Case**: one disciplinary procedure for one employee; owns events, documents, attendance,
  witnesses, links, appeal tracking.
- **Case Event**: append-only case history entry.
- **Document / Document Content / Document Approval / Signature**: versioned legal documents, their
  per-language text, Doc A approvals, and paper hand-off records.
- **Law / Law Jurisdiction / Legal Unit / Citation**: the legal library and what each document cites.
- **Audit Log / AI Generation Log**: append-only system-wide trails.
- **Sequence counter**: per-company (and per-year for references) numbering for cases, documents,
  employees.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: For every company-owned record type, an automated check proves 0 records of another
  company are returned or modifiable by a company user (100% of record types covered).
- **SC-002**: 100% of attempts to modify or delete an issued document or any log entry are refused
  in automated checks.
- **SC-003**: Issuing 50 documents concurrently in one company yields 50 distinct consecutive
  reference numbers with no gaps from abandoned drafts.
- **SC-004**: A developer starting from a fresh clone has the app, worker and scheduler running and
  the test suite green within 15 minutes following the quickstart (excluding image download time).
- **SC-005**: Loading the seed data produces exactly the pilot structure in User Story 3, with zero
  real personal names from the Administrative Order.
- **SC-005b**: The invariant test matrix in contracts/domain-services.md is fully implemented and
  green (every protected path tested through each listed access route).
- **SC-006**: Every entity and field listed in CLAUDE.md for the discipline module has a home in the
  schema, verified by a review of the reconciled ERD against CLAUDE.md.

## Assumptions

- Login screens, roles and permissions, Filament admin screens and the case workflow (state
  transitions, deadline math, quorum, duplicate and conflict checks) are later features; this
  feature only creates the data structures and the integrity guarantees they rely on.
- Laravel's latest stable release is used (CLAUDE.md's "latest LTS" predates Laravel dropping LTS
  labels). The product owner approved Filament v5 + Livewire 4 instead of v4 (ADR 0009).
- Termination-during-leave protection (PLATFORM-PRD §3.7) is out of scope here and is an open
  question for the workflow feature (no leave data exists in Phase 1).
- The packages named in CLAUDE.md §9 step 0 (Livewire, Filament, spatie/laravel-permission, Pint) are
  installed in this feature, each with the product owner's approval at install time; Browsershot and
  the AI client are installed later when their features start.
- Committee pool members link optionally to an employee record (needed for the conflict-of-interest
  check later); the person's name is still stored so a pool member who is not an employee works.
- Witnesses are modelled as their own records (on reports and cases), each optionally linked to an
  employee, because the conflict-of-interest rule must compare them with pool members.
- The pilot company's seed names are invented; only the structure (counts per role, order number
  1097, branch Hilla, federal Iraq) comes from the real Administrative Order.
- Law text import (Section 23 pipeline) is a later feature; seed data registers the three laws and
  their jurisdictions only.
