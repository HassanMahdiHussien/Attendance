# Implementation Plan: View Files In the Site and Employee Case Cards

**Branch**: `024-inline-viewer-employee-cases` | **Spec**: [spec.md](spec.md)

## Design
- **Viewer component** `resources/views/components/ui/file-viewer.blade.php`: Alpine (already loaded by Livewire)
  overlay; items passed as a JSON list `{name, kind: pdf|image|other, view_url, download_url}`; triggers are plain
  buttons with `x-on:click` (`$dispatch('open-file', {list, index})`) so no Livewire round trip. One instance in the
  workspace layout listens for `open-file`. PDF → `<iframe>`; image → `<img>`; Esc/arrows; focus trap; on
  `matchMedia('(max-width: 767px)')` PDFs `window.open(view_url)`.
- **Item builder** `App\Support\Files\FileItem` (static `forAttachment`, `forDocument`) → the array above, so views
  never build URLs by hand.
- **Routes**: attachments keep `?preview=1`; add `documents.view` (`/documents/{document}/view`) in
  `DocumentOutputController` sharing the `pdf()` checks through a private method, returning
  `response()->file(..., inline)`. Headers: nosniff, private no-store, CSP `default-src 'none'; object-src 'self';
  plugin-types application/pdf; frame-ancestors 'self'` for PDFs (verify Chrome's viewer still renders), images keep the
  current CSP + `frame-ancestors 'self'`. Draft previews (`documents.preview`) unchanged.
- **Sites**: show-case (documents & versions, attachments → thumbnails grid for images, response scan, witness
  papers), signed-scan, confirm-document, review-consultation, reports inbox + my reports attachments, employee page.
  Replace the existing `<object>` embeds with the component where they are lists; keep the large inline scan on the
  scan-review screens (that is a review surface) but add the viewer button.
- **Employee page**: `EmployeeProfile::build` returns `cases` (all visible, newest first, with `violationType`,
  `location`, issued documents) + `counts`; view renders a card per case: number link, date, violation
  (`Violation::name`), location, `x-ui` stage dots or outcome badge, `Str::limit(description, 200)`, documents with
  View/Download. Keep `sections` structure for future modules.

## Tests
- Inline document route: owner 200 inline PDF with headers; other company 404; HR staff without access 403/404 as
  `pdf()`; integrity failure 409. Attachment preview already tested — extend to frame-ancestors header.
- `FileItem` kinds by mime.
- Employee page: open + closed cases listed with brief and violation name in viewer language; limited HR staff sees only
  visible ones.
- Behaviour inventory + translations; browser check desktop and 375px, both languages.
