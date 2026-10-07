# Data Model Delta: AI Drafting

| change | columns | notes |
|---|---|---|
| `laws` add | `name_en` string null | seeded for built-in laws; Platform Admin editable |
| `citations` add | `override_reason` text null | set with overridden/by/at; protected when the document is locked (extend the citations trigger's protected columns) |
| `citations` add | `claim_hash` char(64) null; unique(document_id, legal_unit_id, claim_hash) | one row per claim (R12 A5) |
| `cases` add | `ai_request_id` uuid null, `ai_request_document_type` null, `ai_request_from_status` null, `ai_request_started_at` null | drafting request token (R12 A3) |
| recommendations template | new required block `outcome_sentence` with `{outcome}` (ar + en defaults) | operative recommendation from data (R12 A4) |
| new `ai_draft_notes` (T) | id, company_id, case_id, document_id, ai_generation_log_id, notes json (array of strings), created_at | append-only (model guard + trigger); composite FKs (company_id, case_id), (company_id, document_id) |
| new `legal_unit_topics` | id, legal_unit_id FK, topic string, created_by, created_at; unique(legal_unit_id, topic) | statutes only (company_id null) for now; deleting a topic row is allowed (not evidence) and audited |

Notes:
- Verified legal units are immutable (007 trigger protects `tags`), so topic tags cannot be written into
  `legal_units.tags`; they live in `legal_unit_topics` and apply to every version via `legal_unit_id` of the
  current version (UnitCorrection copies topics to the new version).
- No new case statuses; existing AI drafting edges are used (research R6).
- Audit actions: `ai.draft_requested|draft_saved|draft_failed`, `citation.relevance_checked|overridden|refreshed`,
  `legal_unit.topics_changed`, `law.updated` (name_en).
