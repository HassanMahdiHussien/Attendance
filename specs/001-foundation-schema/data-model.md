# Data Model: Platform Foundation and Core Data Model

Conventions (apply to every table unless stated):
- `id` bigint PK; `created_at` / `updated_at` timestamps (append-only tables: `created_at` only).
- **T** = tenant-owned: has `company_id` FK (not null, `restrictOnDelete`, indexed), a
  `unique(company_id, id)` index, and the `BelongsToCompany` global scope (research R3). Children copy
  `company_id` from the parent named in *parent* and refuse a mismatch.
- **Every FK from a T table to another T table is composite** `(company_id, x_id) → x(company_id, id)`
  so cross-company links are impossible at DB level (R3).
- All FKs `restrictOnDelete` unless noted. Enum-like columns are `string` + PHP backed enum (R7).
- **AO** = append-only: model guard + MySQL triggers refuse UPDATE and DELETE (R4).
- No personal data the AI must never see (national ID, salary, phone) is stored for employees in
  this phase.

## Platform-level (not tenant-scoped)

### companies
| column | type | notes |
|---|---|---|
| name | string | |
| letterhead_logo_path | string null | local disk path |
| letterhead_address / letterhead_phone / letterhead_email | string null | |
| enabled_languages | json | default `["ar","en"]`; `ar` and `en` can never be removed (model validation) |
| has_internal_regulation | bool | default false |
| internal_regulation_file_path | string null | |
| requires_second_review | bool | default false — PRD 24.3 optional stricter issuance |
| subscription_status | string | trial | active | suspended | cancelled — default trial; Platform Admin view (§8) |
| is_active | bool | default true — Platform Admin managed |

### hr_partners, law_firm_partners
`name`, `contact_email`. Pivots: `company_hr_partner(company_id, hr_partner_id,
full_case_access bool default false)`, `company_law_firm_partner(company_id, law_firm_partner_id)`
(composite PKs).

### users
| column | type | notes |
|---|---|---|
| company_id | FK null | null for platform_admin, external_legal_reviewer, hr_partner_staff |
| employee_id | FK null, **unique** | one-to-one, nullable (§19); must belong to same company |
| name, email (unique), password, remember_token | | standard |
| primary_role | string enum `UserRole` | platform_admin, company_admin, hr_staff, dept_manager, reporter, committee_member, company_legal_reviewer, external_legal_reviewer, hr_partner_staff — **no general_manager** |
| law_firm_partner_id / hr_partner_id | FK null | |
| is_active | bool | |
Invariant (model saving): company roles ⇒ `company_id` not null; platform roles ⇒ `company_id` null.
Pivot **branch_user** `(company_id, user_id, branch_id)` — branch scope for reporters / department
managers (§8); enforcement is feature 002.
Not behind the throwing tenant scope (R3).

## Organisation (T)

### branches (T)
`name`, `address` null, `jurisdiction` enum `Jurisdiction` (federal_iraq | kurdistan_region),
`is_active`.

### locations (T, parent: branch)
`branch_id`, `name`, `is_active`. Unique (branch_id, name).

### employees (T, parent: branch)
| column | type | notes |
|---|---|---|
| branch_id | FK | |
| employee_code | string | unique (company_id, employee_code); generated `EMP-0001` via sequence when blank (FR-023) |
| name, department, position | string | |
| hire_date | date | |
| email | string null | CSV import optional column |
| is_general_manager | bool | paper signer only — no login role |
| is_active | bool | |

## Committee (T)

### committee_mandates (T)
`order_number` string, `order_date` date, `effective_from` date, `superseded_at` timestamp null,
`source_file_path` string null. Helper: `inEffectOn(date)`.

### committee_pool_members (T, parent: mandate)
`mandate_id`, `branch_id` FK null (null = company-wide), `role_type` enum `PoolRole`
(legal | hr | labor_union), `person_name`, `employee_id` FK null, `user_id` FK null.

## Intake & cases (T)

### violation_reports (T, parent: branch)
`branch_id`, `reporter_user_id` FK null, `source` (reporter | email_forward), `employee_id` FK null
(reported employee — needed for 48-hour duplicate detection), `location_id` null,
`place_freetext` null, `occurred_at` datetime, `violation_type` string,
`alleged_responsible_employee_id` FK null, `alleged_responsible_person_freetext` null, `description`
text, `status` (received | under_review | converted_to_case | dismissed), `case_id` FK null.

### report_witnesses (T, parent: violation_report) / case_witnesses (T, parent: case)
`violation_report_id` / `case_id`, `name`, `employee_id` FK null.

### cases (T, parent: branch)
| column | type | notes |
|---|---|---|
| branch_id, employee_id | FK | employee = subject of the case |
| case_number | unsigned int | unique (company_id, case_number); sequence `case`; displayed zero-padded |
| violation_report_id | FK null | the report it came from |
| status | string enum `CaseStatus` | values listed below, default `draft` |
| jurisdiction_at_opening | enum `Jurisdiction` | snapshot of branch jurisdiction |
| law_version_id | FK null → laws | the `laws` row (a law *version*: a replacement law is a new row, never an edit) in force for `jurisdiction_at_opening` when the case left `draft`; required for every status after `draft`; never changed afterwards (§23.5) |
| opened_by_user_id | FK → users | |
| assigned_hr_user_id | FK null → users | HR staff default visibility = assigned or opened (§8) |
| location_id | FK null | |
| violation_place_freetext | string null | fallback |
| violation_time | datetime | |
| violation_type | string | |
| description | text null | HR-entered facts |
| alleged_responsible_employee_id | FK null | pattern detection |
| alleged_responsible_person_freetext | string null | |
| include_prior_record | bool | default false — defense letter mentions priors only if ticked |
| is_sensitive | bool | true only when consultation requested |
| legal_review_requested_by / _at | FK null / timestamp null | |
| defense_letter_issued_at | timestamp null | **anchors 3-day and 15-day clocks** |
| committee_mandate_id | FK null | mandate in effect on defense_letter_issued_at |
| employee_response_text | text null | AI draft transcription, HR-checked |
| employee_response_checked_by / _at | FK null / timestamp null | |
| committee_session_notes | text null | typed minutes |
| committee_session_held_at | timestamp null | |
| outcome | enum null | recommendation_only | warning | termination |
| defense_acceptance | enum null | accepted_fully | accepted_partially | rejected |
| decision_issued_at | timestamp null | anchors 30-day appeal window |
| closed_at | timestamp null | |
`CaseStatus` values (CLAUDE.md §7, verbatim, in order): draft, open, ai_drafting_defense_letter,
defense_letter_citation_check, defense_letter_ready_for_review, defense_letter_confirmed,
defense_letter_issued, awaiting_employee_response, response_received, response_refused_to_sign,
response_delivery_refused, response_scanned_uploaded, ai_ingested_response, committee_session_held,
ai_drafting_recommendations, recommendations_citation_check, recommendations_awaiting_approvals,
recommendations_confirmed, recommendations_printed, awaiting_committee_signatures,
signed_recommendations_uploaded, ai_verify_and_close, decision_letter_citation_check,
awaiting_external_legal_consultation, decision_letter_ready_for_review, decision_issued,
awaiting_appeal_window, appeal_pending, appeal_resolved, archived_final.
Transition rules are the workflow feature; this feature only stores the value.

Model refuses delete. Laravel model class name `DisciplinaryCase` (`Case` is a PHP reserved word),
table `cases`.

### case_events (T, AO, parent: case)
`case_id`, `actor_user_id` null, `event_type` string, `notes` text null, `payload` json null,
`created_at`.

### case_links (T, parent: case)
`case_id`, `linked_case_id`, `link_type` (prior_record | related | duplicate_merge), `created_at`.
Unique (case_id, linked_case_id, link_type); linked case must be same company.

### committee_session_attendances (T, parent: case)
`case_id`, `committee_pool_member_id`, `is_chair` bool, `vote` null (approve | reject | abstain),
`attended_at`. Unique (case_id, committee_pool_member_id).

### appeal_trackings (T, parent: case)
`case_id` unique, `window_start` date, `window_end` date, `appeal_filed` bool,
`appeal_filed_at` date null, `appeal_outcome` text null, `logged_by` FK null.

### attachments (T, AO)
Polymorphic `attachable_type/attachable_id` (violation_report | case | document), `kind`
(report_evidence | employee_response_scan | signed_document_scan | delivery_proof | regulation_source
| other), `disk` default local, `path`, `original_name`, `mime_type`, `size_bytes`,
`uploaded_by` FK null, `created_at`. Files are evidence: no update, no delete.

## Documents (T)

### documents (T, parent: case)
| column | type | notes |
|---|---|---|
| case_id | FK | |
| document_type | enum | defense_letter | recommendations | decision_letter |
| version | unsigned int | unique (case_id, document_type, version) |
| previous_version_id | FK null self | set on corrections |
| superseded_by_id | FK null self | set when the next version is issued |
| correction_reason | text null | required when previous_version_id set |
| status | enum | draft | ready_for_review | issued | superseded |
| is_locked | bool | true at issuance; never back to false |
| reference_number | string null | assigned at issuance only |
| reference_year | smallint null | year of issuance; unique (company_id, reference_year, reference_number) |
| reference_is_manual | bool | |
| issued_at / issued_by | timestamp null / FK null | issued_by = the confirming user |
| second_reviewed_by / second_reviewed_at | FK null / timestamp null | required before issuance when company `requires_second_review`; must differ from issued_by |
| drafted_by | enum | ai | human |
| penalty_type | enum null | warning | termination (decision letter only) |
| pdf_file_path | string null | may be set once after issuance |
| created_by | FK null | |
Locked ⇒ only status issued→superseded, superseded_by_id null→value, pdf_file_path null→value
(R4). Delete always refused once locked; drafts can be deleted only by the issuance/correction
services.

### document_contents (T, parent: document)
`document_id`, `language_code` string, `body` longText, `is_authoritative` bool (true for `ar`).
Unique (document_id, language_code). Language must be in the company's `enabled_languages`. No
insert/update/delete when parent locked (model + trigger).

### document_approvals (T, parent: document)
`document_id`, `approver_user_id`, `role_category` (legal | hr | labor_union), `decision`
(approved | rejected), `comment` null (required when rejected), `languages_present` json,
`decided_at`, `invalidated_at` null (set when an HR edit resets approvals — rows are never
deleted). Only `invalidated_at` may change, once.

### signatures (T, parent: case)
`case_id`, `document_id` FK null (null for the response-submission hand-off), `purpose`
(defense_letter_receipt | response_submission | decision_letter_receipt | committee_signature |
union_signature | general_manager_signature), `signer_name`, `signer_role`, `employee_id` null,
`committee_pool_member_id` null, `status` (pending | signed | refused_to_sign | delivery_refused),
`witness_1_name` / `witness_2_name` null (both required when refused / delivery refused),
`attempt_location` null, `attempted_by_name` null, `attempted_at` / `signed_at` null,
`presence_check` null (box_filled | box_appears_empty) + `presence_checked_at` — wording: never
"verified"/"authentic".

## Legal library

### laws (scope: statutes global, regulations own-company)
`company_id` FK null (only for internal_regulation), `kind` (statute | internal_regulation), `name`,
`number` string null, `year` smallint null, `source_file_path`, `effective_from` date null,
`repealed_at` date null, `approval_status` (approved | pending), `approved_by` FK null,
`approved_at` null.

### law_jurisdictions
`law_id`, `jurisdiction` — composite PK.

### legal_units (scope via company_id copied from law)
`law_id`, `company_id` null, `version` unsigned int, `previous_version_id` / `superseded_by_id` null,
`article_number` string, `clause_path` string null, `official_text` text, `source_page` int null,
`extraction_a` / `extraction_b` text null, `status` (unverified | mismatch | verified_auto |
verified_human), `verified_by` / `verified_at` null, `tags` json null. Once `verified_*`, the whole
row is frozen except `superseded_by_id` null→value (trigger + model); a correction is a new version
row. Only `verified_*` is citable.

### citations (T, parent: document)
`document_id`, `legal_unit_id`, `claim_sentence` text, `relevance_check` null (supports | partial |
does_not_support), `relevance_reason` null, `overridden` bool, `overridden_by` / `overridden_at`
null. No insert/update/delete once the parent document is locked (trigger). `legal_unit_id` must be
a `verified_*` unit of a statute or of the same company's regulation (model validation).

## Numbering & logs

### company_sequences (T)
`sequence_key` (case | employee_code | document_reference), `year` smallint default 0,
`next_value` unsigned int. Unique (company_id, sequence_key, year). Row-locked increments (R5).

### audit_logs (AO, platform-level)
`actor_user_id` null, `company_id` null, `action`, `entity_type` null, `entity_id` null,
`ip_address` null, `metadata` json null, `created_at`.

### ai_generation_logs (AO)
`company_id` null, `case_id` null, `document_id` null, `actor_user_id` null, `touchpoint` string,
`model` string, `input` longText (json), `output` longText null, `status` (succeeded | failed |
rejected_format), `error` text null, `input_tokens` / `output_tokens` / `duration_ms` int null,
`created_at`.

## Explicitly absent
- No `employee_violations` table (WEIRDNESS) — history = prior `cases`.
- No `general_manager` user role.
- No holiday calendar.
