# Data Model Delta: Document Templates and PDF Generation

| change | columns | notes |
|---|---|---|
| `employees` add | `gender` string (male / female / unspecified), default `unspecified` | issuance requires male/female |
| new `company_letterheads` (T) | company_id, language_code, version, name, address_lines text, phone, email, footer_text, header_image_path null, created_by, created_at; unique(company, language, version) | immutable (model guard + trigger); latest = active |
| new `document_templates` (T) | company_id, document_type, language_code, version, blocks json, options json, created_by, created_at; unique(company, type, language, version) | immutable; none → code default |
| `documents` add | `render_snapshot` json null, `rendered_html` longtext null, `pdf_status` (pending/ready/failed) null, `pdf_error` text null, `pdf_sha256` char(64) null | snapshot/html set once at issuance (protected); pdf_* may go null→value once and status pending/failed→ready only (trigger) |
| `document_contents` add | `secondary_body` text null | recommendations' committee recommendations; protected when locked |
| `company_letterheads` add | `logo_path`, `logo_sha256`, `header_image_sha256`, `needs_review` bool | logo bound to the version; content-addressed files |
| `companies` | existing `letterhead_*` columns stay but are deprecated; backfill creates `ar` and `en` version 1 (en flagged needs_review) and copies the logo into the versioned store | ERD marks them deprecated |

Files: `storage/app/private/documents/...` (issued PDFs), `storage/app/private/letterheads/...`
(logos, header images). Nothing goes on the public disk.
