# Data Model Delta: Legal Library Import

| change | columns | notes |
|---|---|---|
| `laws` add | `import_mode` string (auto/text/ocr) default auto, `supersedes_law_id` FK laws null, `source_sha256` char(64) null | Law 37 seeded `ocr` |
| new `legal_import_runs` | id, law_id FK, company_id null (= law's), source_path, source_sha256, page_from, page_to, mode, state (queued/reading/ai_pending/splitting/completed/failed/cancelled), counts json (pages, units, verified_auto, mismatch, failed_pages), started_by FK users, error text null, started_at, finished_at null, timestamps | one active (queued/reading/splitting) run per law, enforced under a law row lock |
| new `legal_import_pages` | id, run_id FK, company_id null, page_number, image_path, image_sha256, text_status (pending/done/failed), text_method (text/ocr) null, text longtext null, ai_status (pending/done/failed/unavailable), ai_text longtext null, ai_illegible bool, attempts_text, attempts_ai, last_error text null, timestamps; unique(run_id, page_number) | resumable per page |
| `legal_units` status | add `verified_text`, `excluded` (string column; enum class updated) | immutable set = verified_auto, verified_human, verified_text |
| `legal_units` add | `import_run_id` FK null | provenance |
| new `legal_unit_approvals` (T) | id, company_id, legal_unit_id, approved_by FK users, created_at; unique(legal_unit_id); composite FK (company_id, legal_unit_id) | append-only (model guard + trigger) |
| triggers | recreate `immutable_legal_units_update/delete` to include `verified_text`; add insert-only triggers on `legal_unit_approvals` | down() restores previous definitions |

Rules:
- `legal_units.company_id` = law's company (null for statutes); regulation units and approvals carry the
  company and go through `LegalLibraryScope` / `CompanyScope`.
- Citable statute unit: status in verified set, not superseded, law not repealed for the case date, law
  applies to the case jurisdiction. Citable regulation unit: same + approval row + same company.
- Page images: private disk `legal-library/{law_id}/pages/{n}.png`; regulation PDFs:
  `legal-library/companies/{company_id}/{law_id}/...`.
- Audit actions: `legal_import.started|completed|failed`, `legal_unit.verified|corrected|excluded|approved`,
  `law.created|updated|repealed|jurisdictions_changed`.
