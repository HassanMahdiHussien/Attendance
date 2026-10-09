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

## Plan review resolutions

- **Blocker — issued PDF access and integrity:** A separate implementation of `documents.view` could drift from `documents.pdf` and expose a cross-tenant file or an altered PDF. Resolve both through one controller method that performs the existing policy, ready-state, path-prefix, existence and SHA-256 checks before choosing inline or attachment disposition. Keep the existing status codes.
- **Blocker — framing and MIME safety:** The planned PDF `object-src`/`plugin-types` CSP is unnecessary for an iframe loading a browser PDF viewer and can interfere with it. Use `frame-ancestors 'self'` with `default-src 'none'` on inline PDFs and previews, `X-Content-Type-Options: nosniff`, `Cache-Control: private, no-store`, and `X-Frame-Options: SAMEORIGIN`. Keep attachment preview's existing same-parent authorization and three-MIME allowlist. The deployed nginx SAMEORIGIN header agrees with this policy.
- **Should fix — draft previews:** `documents.preview` is HTML, not a downloadable PDF. Preserve the existing sandboxed issuance/editor review iframe. Where a draft is listed, its View action opens that authorized HTML preview in the viewer; no Download is offered until issuance. The viewer treats draft HTML as a sandboxed preview and uses the PDF mobile new-tab behavior.
- **Should fix — employee visibility and history:** The current profile removes its first open case from history and limits documents to the newest version of each type. Render all visible non-draft cases newest first, including every open case, and all issued ready PDF versions for each card. Keep `CaseVisibility`, the partial-visibility notice and counters unchanged; eager-load violation type and location.
- **Should fix — existing list reach:** Reporter inbox email/report attachments and case creation linked attachments use literal URLs; my-reports currently lists no files. Add the viewer to every existing authorized file list. The current attachment route does not authorize a reporter to open report attachments, so my-reports cannot acquire file controls without an access-policy change; leave that page's bare-status contract intact. Unsupported MIME types retain Download only.
- **Should fix — accessible interaction:** Give the overlay a dialog label, focus trap and focus restoration, disable background scrolling, close on Escape, and navigate with arrows only while open. Previous/Next follow displayed list order (RTL controls remain visually directional). Mobile PDF opens a new tab from the click gesture.
- **Decision where docs are silent:** Use a compact reusable file-action component for each list and one viewer instance in the workspace layout. Group a list's viewable items for navigation; no new audit event or package.
