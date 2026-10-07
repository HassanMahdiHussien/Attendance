# Data Model Delta: AI Reading of Scans

| change | columns | notes |
|---|---|---|
| new `response_transcriptions` (T) | id, company_id, case_id, attachment_id, origin (ai / ai_checked / human), text longtext, illegible bool, ai_generation_log_id null, created_by null, created_at | append-only (model guard + insert-only trigger); composite FKs to cases, attachments |
| `cases` add | `employee_response_origin` string null (ai_checked / human) | set on confirm with the existing text/checked_by/checked_at |
| new `signature_presence_suggestions` (T) | id, company_id, case_id, document_id, attachment_id, results json ({attendance_id: {result, reason}}), ai_generation_log_id, created_at | append-only; latest per attachment used |
| `signatures` add | `ai_suggestion` string null (appears_filled / appears_empty / unclear) | protected like the other signature columns |
| `cases` ai_request_* (008) | reused with `ai_request_document_type` = `response_transcription` / `signature_presence` | one AI request per case at a time; no status change |
