# Database ERD — Disciplinary Process Automation Service

This is the reference schema for Claude Code Session 1 (migrations + models). Mermaid renders natively on GitHub/GitLab and most markdown viewers.

```mermaid
erDiagram
    COMPANIES ||--o{ BRANCHES : has
    COMPANIES ||--o{ EMPLOYEES : has
    COMPANIES ||--o{ COMMITTEE_MANDATES : has
    COMPANIES ||--o{ CASES : has
    COMPANIES ||--o{ COMPANY_LEGAL_EXCERPTS : "internal regulation"
    COMPANIES }o--o{ HR_PARTNERS : "onboarded via"
    COMPANIES }o--o{ LAW_FIRM_PARTNERS : "partnered with"

    BRANCHES ||--o{ EMPLOYEES : has
    BRANCHES ||--o{ CASES : "scoped to"

    COMMITTEE_MANDATES ||--o{ COMMITTEE_POOL : defines
    COMMITTEE_POOL }o--o| BRANCHES : "scoped to (nullable)"

    DOCUMENTS ||--o{ DOCUMENT_CONTENTS : "one row per language"
    DOCUMENTS ||--o{ DOCUMENT_APPROVALS : "recommendations doc only — 3 required"
    USERS ||--o{ DOCUMENT_APPROVALS : "approves as a role category"
    VIOLATION_REPORTS }o--o| CASES : "HR converts to"
    USERS ||--o{ VIOLATION_REPORTS : "reporter submits"
    BRANCHES ||--o{ LOCATIONS : "has"
    LOCATIONS ||--o{ CASES : "where it happened"
    CASES ||--o{ CASE_LINKS : "linked to prior/related"
    LAWS ||--o{ LEGAL_UNITS : "split into"
    LAWS ||--o{ LAW_JURISDICTIONS : "applies in"
    LEGAL_UNITS ||--o{ CITATIONS : "cited by"
    DOCUMENTS ||--o{ CITATIONS : "contains"
    EMPLOYEES ||--o{ CASES : "subject of"
    EMPLOYEES ||--o{ CASES : "alleged responsible person in (nullable, pattern detection)"

    CASES ||--o{ CASE_EVENTS : logs
    CASES ||--o{ DOCUMENTS : produces
    CASES ||--o{ COMMITTEE_SESSION_ATTENDANCE : records
    CASES ||--o| APPEAL_TRACKING : "may have"
    CASES }o--|| USERS : "opened by"

    DOCUMENTS ||--o{ SIGNATURES : requires
    DOCUMENTS ||--o| DOCUMENTS : "superseded by (versioning)"

    COMMITTEE_SESSION_ATTENDANCE }o--|| COMMITTEE_POOL : "attendee is"

    USERS }o--o{ COMPANIES : "access via"
    USERS ||--o{ CASE_EVENTS : performs
    USERS }o--o| LAW_FIRM_PARTNERS : "external reviewer from"
    USERS }o--o| HR_PARTNERS : "staff of"

    LEGAL_ARTICLES ||--o{ DOCUMENTS : "cited in (via case)"

    COMPANIES {
        id PK
        string name
        bool has_internal_regulation
        string internal_regulation_file
        string letterhead_logo
        string letterhead_address
        string letterhead_phone
        string letterhead_email
        json enabled_languages "always includes ar + en; extra languages (ku...) optional — CLAUDE.md Section 3"
        bool is_active "managed by Platform Admin — Section 8"
        timestamp created_at
    }

    BRANCHES {
        id PK
        id company_id FK
        string name
        string address
        enum jurisdiction "federal_iraq | kurdistan_region — CLAUDE.md Section 19"
    }

    EMPLOYEES {
        id PK
        id company_id FK
        id branch_id FK
        string employee_code "company-provided, or auto-generated if none (CLAUDE.md Section 5)"
        string name
        string department
        string position
        date hire_date
        timestamp created_at
    }

    COMMITTEE_MANDATES {
        id PK
        id company_id FK
        string order_number
        date order_date
        date effective_from
        timestamp superseded_at "nullable"
        string source_file
    }

    COMMITTEE_POOL {
        id PK
        id mandate_id FK
        id branch_id FK "nullable — company-wide if null"
        string person_name
        enum role_type "legal | hr | labor_union"
        id linked_user_id FK "nullable, if they have a login"
    }

    CASES {
        id PK
        id company_id FK
        id branch_id FK
        id employee_id FK
        string case_number "per-company sequential, assigned at creation"
        id law_version_id FK "LAWS row in force when opened — kept even if branch jurisdiction changes"
        id opened_by FK "users.id"
        enum status "see Section 7 state machine in CLAUDE.md"
        id location_id FK "nullable — from the branch list, enables hotspots"
        string violation_place_freetext "fallback only"
        datetime violation_time
        string violation_type
        bigint alleged_responsible_employee_id FK "nullable — links to EMPLOYEES when the responsible person is in the system, enables cross-case pattern detection (CLAUDE.md Section 3)"
        string alleged_responsible_person_freetext "nullable — fallback only when the person isn't a known employee (e.g. external contractor)"
        bool is_sensitive "true only when someone on the case requests external legal consultation"
        id legal_review_requested_by FK "nullable — committee member who asked for external review"
        timestamp legal_review_requested_at "nullable"
        timestamp defense_letter_issued_at "nullable — anchors 15-day clock"
        timestamp created_at
    }

    CASE_EVENTS {
        id PK
        id case_id FK
        id actor_user_id FK "nullable if system/AI-generated"
        string event_type
        text notes
        json payload
        timestamp created_at
    }

    DOCUMENTS {
        id PK
        id case_id FK
        enum document_type "defense_letter | recommendations | decision_letter"
        string reference_number "العدد — assigned at issuance, per-company/per-year sequential, manually overridable"
        enum drafted_by "ai | human — human = manual fallback path, CLAUDE.md Section 3bb"
        int version
        %% body text lives in DOCUMENT_CONTENTS, one row per language
        string pdf_file_path
        enum status "draft | ready_for_review | issued | superseded"
        timestamp issued_at "nullable"
        id superseded_by FK "nullable, self-reference"
    }

    DOCUMENT_CONTENTS {
        id PK
        id document_id FK
        string language_code "ar | en | ku | ... — extensible, CLAUDE.md Section 3"
        text body "immutable once the parent document is issued"
        bool is_authoritative "true for ar — Arabic governs if renderings disagree"
    }

    DOCUMENT_APPROVALS {
        id PK
        id document_id FK
        id approver_user_id FK
        enum role_category "legal | hr | labor_union — one approval needed per category"
        enum decision "approved | rejected"
        text comment "required when rejected; approver cannot edit the text"
        json languages_present_at_approval "which language versions existed when they approved"
        timestamp decided_at
    }

    SIGNATURES {
        id PK
        id document_id FK
        string signer_name
        string signer_role
        enum status "signed | refused_to_sign | delivery_refused"
        string witness_1_name "nullable"
        string witness_2_name "nullable"
        string attempt_location "nullable"
        timestamp signed_at "nullable"
        timestamp attempted_at "nullable"
    }

    LOCATIONS {
        id PK
        id branch_id FK
        string name "production line, warehouse, gate... — CLAUDE.md 24.5"
        bool active
    }

    CASE_LINKS {
        id PK
        id case_id FK
        id linked_case_id FK
        enum link_type "prior_record | related | duplicate_merge"
        timestamp created_at
    }

    VIOLATION_REPORTS {
        id PK
        id company_id FK
        id branch_id FK
        id reporter_user_id FK "nullable — null when it came from an email forward"
        enum source "reporter | email_forward"
        id location_id FK "nullable — from the branch list"
        string place_freetext "fallback only"
        datetime occurred_at
        string violation_type
        id alleged_responsible_employee_id FK "nullable"
        string alleged_responsible_person_freetext "nullable"
        text description
        enum status "received | under_review | converted_to_case | dismissed — the reporter sees only this"
        id case_id FK "nullable — set when HR converts it"
        timestamp created_at
    }

    COMMITTEE_SESSION_ATTENDANCE {
        id PK
        id case_id FK
        id committee_pool_id FK
        bool is_chair
        enum vote "approve | reject | abstain"
        timestamp attended_at
    }

    APPEAL_TRACKING {
        id PK
        id case_id FK
        date window_start
        date window_end
        bool appeal_filed
        date appeal_filed_at "nullable"
        text appeal_outcome "nullable"
        id logged_by FK "users.id"
    }

    LAWS {
        id PK
        string name "e.g. Labor Law 37/2015"
        enum kind "statute | internal_regulation"
        %% jurisdictions via LAW_JURISDICTIONS (many-to-many); statutes editable by platform_admin only
        id company_id FK "only for internal_regulation"
        string source_file
        date effective_from
        date repealed_at "nullable — a replacement law is a new row"
        enum approval "approved | pending — internal regulation needs the company lawyer"
        id approved_by FK "nullable"
    }

    LAW_JURISDICTIONS {
        id law_id FK
        enum jurisdiction "federal_iraq | kurdistan_region"
    }

    LEGAL_UNITS {
        id PK
        id law_id FK
        int version "a correction = new version row, never an overwrite"
        id superseded_by FK "nullable"
        string article_number
        string clause_path "e.g. 43/ثانياً/ز"
        text official_text "the only text ever printed in a document"
        int source_page
        text extraction_a "text layer or OCR"
        text extraction_b "AI vision reading"
        enum status "unverified | mismatch | verified_auto | verified_human — only verified_* is citable"
        id verified_by FK "nullable"
        json tags "violation types, for retrieval"
    }

    CITATIONS {
        id PK
        id document_id FK
        id legal_unit_id FK "the exact version used"
        text claim_sentence
        enum relevance_check "supports | partial | does_not_support — CLAUDE.md 23.3"
        text relevance_reason
        bool overridden
        id overridden_by FK "nullable"
    }

    USERS {
        id PK
        string name
        string email
        string password_hash
        enum primary_role "platform_admin | hr_staff | company_admin | dept_manager | reporter | external_legal_reviewer | hr_partner_staff — no general_manager: they are a paper signer only, CLAUDE.md Section 3"
        timestamp created_at
    }

    HR_PARTNERS {
        id PK
        string name
        string contact_email
    }

    LAW_FIRM_PARTNERS {
        id PK
        string name
        string contact_email
    }

    AUDIT_LOGS {
        id PK
        id actor_user_id FK "nullable"
        id company_id FK "nullable"
        string action
        string entity_type
        id entity_id
        string ip_address
        timestamp created_at
    }
```

## Notes on the model

- **Multi-tenancy:** every core table carries `company_id` (directly or via a parent — e.g. `cases` has it directly, `documents` inherits it via `case_id`). Every query must filter by `company_id`.
- **Committee is a pool, not fixed people** (per the real Administrative Order — see CLAUDE.md Section 4): `committee_pool` is many-to-many against a mandate, and `committee_session_attendance` records who actually showed up to a specific case's session, with an individual vote each — this is what quorum/majority-vote logic reads from, not `committee_pool` directly.
- **Documents are immutable + versioned:** `documents.content_snapshot` is never edited after `status = issued`. A correction creates a new row with an incremented `version` and sets `superseded_by` on the old row.
- **`AUDIT_LOGS` is system-wide**, separate from `CASE_EVENTS` (which is case-specific narrative/history shown in the case UI). `AUDIT_LOGS` is the compliance/security trail (who logged in, who viewed what, admin actions) — see the new Audit & Security section added to `CLAUDE.md`.
- **`LEGAL_ARTICLES`** is the RAG-style constraint table for the AI (Section 5/26 in the PRD) — the AI may only cite article numbers that exist here; `is_general = true` rows are visible to every tenant, `COMPANY_LEGAL_EXCERPTS` rows are visible only to their own `company_id`.
