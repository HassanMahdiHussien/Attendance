# Feature Specification: Live draft preview

**Branch**: `063-live-draft-preview` (stacked on `062-case-page-and-letter-polish`) | **Created**: 2026-10-21 | **Status**: Approved

**Input**: Product owner (2026-10-21): "when I edit the draft it doesn't reflect automatically; I want to see it immediately." Today the document editor's preview frame loads the *saved* draft (`documents.preview`) and says «احفظ المسودة لتحديث المعاينة».

## Requirements
- **FR-001 Preview follows typing**: in the document editors (defense letter, and the recommendations and decision letter editors where they show the same preview), the preview updates by itself about one second after the user stops typing in any language box, with no save.
  - Remove the «احفظ المسودة لتحديث المعاينة» hint.
  - A small, quiet «جارٍ تحديث المعاينة…» state may show while the frame reloads.
- **FR-002 Nothing is saved or logged by previewing**: unsaved text is never written to `document_contents`, never creates a version, and never adds case events or audit rows.
  - The editor holds the unsaved bodies in a short-lived server store (cache, about 30 minutes), under a random preview token bound to the user, the document and the company.
  - The preview route accepts that token and renders with those bodies in place of the saved ones, in memory only.
  - Saving, submitting and issuing keep their current behaviour.
- **FR-003 Same safety as today**:
  - Authorization stays the same (the `view` policy and tenant context).
  - A token from another user, document or company is ignored, and the saved draft renders.
  - Issued (locked) documents never take overrides.
  - The response keeps its CSP, sandbox and no-store headers.
  - Unsaved text is escaped and formatted exactly like saved text, through the same renderer path: no raw HTML.
  - Citation markers in unsaved text go through the same citation replacement. An invalid marker shows the existing "cannot preview" page rather than an error.
- **FR-004 Unsaved changes are visible**: while the text differs from the saved draft, the editor shows a quiet «تغييرات غير محفوظة» next to «حفظ المسودة». Leaving the page keeps the existing behaviour.
- **FR-005 Tests**:
  - The preview route uses the token's bodies.
  - The saved contents, the events and the audit rows are unchanged after previewing.
  - Wrong-user, wrong-document and wrong-company tokens are ignored.
  - A locked document ignores tokens.
  - Unsaved HTML is escaped.
  - The editor re-points the frame after the bodies change.
  - The behaviour inventory and translations (en + ar) are updated.
  - Pint and the full suite pass.
