# Data Model Delta: Case Workflow Engine

New migrations only (feature 001/002 migrations untouched).

| change | columns | notes |
|---|---|---|
| `laws` add | `category` string (labor / social_security / regulation) | backfill by name/number; index |
| `cases` add | `legal_review_completed_at` timestamp null, `legal_review_completed_by` FK users null, `legal_review_note` text null, `session_majority_reached` bool default false | consultation + vote result |
| `documents` add | `leave_confirmed_by` FK users null, `leave_confirmed_at` timestamp null | termination guard. Must be added to the locked-document trigger's protected columns (recreate the trigger in a new migration) |
| new `case_alerts` (T) | company_id, case_id, alert_type, stage, status (pending/sent/failed), attempts, last_error, sent_at null; unique(case_id, alert_type, stage); composite FK to cases | outbox, retryable (R10) |
| `document_approvals` add | `committee_pool_member_id` (composite FK) | eligibility snapshot (R10) |
| new `case_conflict_clearances` (T) | company_id, case_id, committee_pool_member_id, reason, cleared_by, created_at; append-only | name-only conflict clearance (R10) |
| `cases` trigger | BEFORE UPDATE: status change requires `@displanery_workflow_transition = 1` | status write boundary (R10) |
| new `notifications` | Laravel default | database notifications |

Rules:
- `defense_letter_issued_at` and `decision_issued_at` are set only when null (first issuance).
- `case_conflict_clearances` is append-only (model guard).
- The trigger migration recreates the documents trigger with the new protected columns and adds the cases status trigger; `down()` restores the previous definitions.
- Config `displanery.deadlines` = `{response_days: 3, procedure_days: 15, appeal_days: 30,
  approaching_hours: 48, appeal_ending_days: 3}`.
