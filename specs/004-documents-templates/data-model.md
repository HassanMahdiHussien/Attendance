# Data Model Delta: Document Templates and PDF Generation

| change | columns | notes |
|---|---|---|
| `employees` add | `gender` string (male / female / unspecified), default `unspecified` | issuance requires male/female |
| new `company_letterheads` (T) | company_id, language_code, version, name, address_lines text, phone, email, footer_text, header_image_path null, created_by, created_at; unique(company, language, version) | immutable (model guard + trigger); latest = active |
| new `document_templates` (T) | company_id, document_type, language_code, version, blocks json, options json, created_by, created_at; unique(company, type, language, version) | immutable; none → code default |
| `documents` add | `render_snapshot` json null | set once at issuance; add to the locked-document trigger's protected columns |
| `companies` | existing `letterhead_*` single-language columns stay; the renderer reads `company_letterheads` (seed/backfill from the old columns into an `ar` version 1) | old columns deprecated in ERD |

Files: `storage/app/private/documents/...` (issued PDFs), `storage/app/private/letterheads/...`
(logos, header images). Nothing goes on the public disk.
