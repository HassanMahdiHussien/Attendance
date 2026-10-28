# Feature Specification: One-step upload and an action-first dashboard

**Branch**: `513-upload-and-dashboard` | **Created**: 2026-10-27 | **Status**: Approved 2026-10-28 (answers below).

**Input**: Product owner, 2026-10-26: "this system does not connect to attendance devices directly, so it gets data from uploading the leave report and the attendance report (like the examples we shared), so uploading must be easy and you can see it directly." Design audit 2026-10-27 (Impeccable detector + review): the dashboard and employee page give 48 / 28 equal tiles with no priority; actions look like plain links; export sits above the results.

## Context

- Uploads are the **only** way data enters the system. HR uploads, in practice: the device transaction export (`Transaction_YYYYMMDDhhmmss.xlsx/csv`), the company leave report (`IRAQ All Leave REPORT-….xlsx`), the annual leave balance report, and the HC employee list. Holiday announcements (PDF/image/email) have their own reading flow and stay where they are.
- Today each kind has its own page in a different menu, and each takes 4–5 clicks: choose file → Continue → (column matching for a new layout) → dates / branches or scope → Preview → read a long text list → Import → progress lines with **no results and no links**.
- The import services already detect most of what HR now types by hand: `TransactionFile`/`LeaveFile`/`LeaveBalanceFile` recognise their headers and suggest mappings; saved mappings are keyed by header signature; `TransactionImport::preview` returns `suggested_coverage`; `LeaveImport::preview` returns `suggested_scope` (snapshot time from the file name, first and last dates). Imports and recalculation run as queued jobs and write batch events.
- Processing is not instant (a full real leave report ≈ 7 minutes after the spec-504 performance work), so "see it directly" means live progress and a results card the moment it finishes, not a synchronous import.

## Decisions (orchestrator proposal) **[A]**

- **One Upload page** (`Upload` as the first item in the top navigation and a button on the HR dashboard). The four existing upload pages remain reachable from their menus for now (advanced use) but link to the new page; removal is a later decision.
- **Several files at once**: HR may drop the attendance and leave reports together. Files are processed in a fixed order: employee list → leave balance → leave report → attendance, because later steps read earlier data. Each file is its own batch (idempotency unchanged).
- **No preview click for a recognised file**: when the kind, the column mapping (built-in or saved) and the coverage/scope are all known and nothing needs a decision, the page shows a one-sentence summary per file and a single **Import** button. A file that needs a decision (unknown layout, new devices, unknown codes above a threshold, coverage that overlaps an earlier file differently, already-imported file) shows that decision inline on its row; everything else stays one click.
- **Coverage stays explicit but pre-filled**: the summary sentence states the period and branches the file is treated as complete for ("Attendance 1–24 Oct · Hilla, Karbala · 3,161 punches"), with a **Change** link. This protects the rule *incomplete is not absent*; HR never has to type dates for a normal export.

## Requirements

### Upload

- **FR-001 Detect**: on drop/choose, each file is inspected (server-side, size limits as today) and classified as attendance transactions, leave report, leave balance, employee list or unknown, using the existing `inspect()` header rules and file-name patterns. Unknown → a clear message listing the accepted reports. Real file contents are never logged.
- **FR-002 Map**: built-in or saved mapping applies automatically; column matching appears only for an unseen layout (existing mapping UI, inline), and is saved for next time as today.
- **FR-003 Summarise**: per file, one plain sentence with the key counts the existing previews already compute (rows, employees, period, branches, new/changed/cancelled leave, new devices, unknown codes, row errors) and at most three sample problems; a "details" disclosure shows the existing full preview.
- **FR-004 Import**: one button imports all ready files in the FR order; it commits through the existing `commit()` calls with the existing preview tokens (same idempotency and audit), then queues the existing jobs.
- **FR-005 Progress**: one progress bar per file with the current stage in words (reading → cleaning punches → calculating days → reconciling leave → follow-up cases), from batch events; the page can be left and reopened.
- **FR-006 Results card** when a file finishes: what changed, computed from the batch and processing-run records (no new calculations): punches added / duplicates skipped; days calculated; new absences, missing punches, lateness and early-departure minutes in the covered period; leave requests new / changed / cancelled; new data issues; follow-up cases opened. Each line links to the matching filtered screen (daily attendance for the covered dates and branches, data issues for this batch, leave requests for this batch, affected employees).
- **FR-007 Recent uploads**: the last 10 uploads (all kinds) with status and a link to their results card, on the Upload page and on the dashboard.

### Dashboard and employee page

- **FR-008 Needs action first**: the HR dashboard opens with a "Needs your action" row of 6 cards for the chosen filters: uncovered absences, missing punches, follow-up cases waiting for HR, leave waiting for approval, unreconciled minutes, and Data to fix (actionable issue types only; see answers). Each card links to the filtered list. Zero cards collapse into one quiet "nothing waiting" line.
- **FR-009 Grouped figures**: the remaining tiles are grouped under Attendance, Leave and reconciliation, Overtime and rest days, Data quality; tiles with zero are de-emphasised (not removed, so filters stay explainable). Same figures and services as today (CLAUDE.md agreement rule: dashboard, profile, reports and chat agree).
- **FR-010 Filters and export**: filters in a compact bar with the primary Apply button; export moves into a secondary "Export" button/menu next to the results instead of a card above them.
- **FR-011 Employee page**: same grouping and needs-action row scoped to the employee; the daily record becomes a table (date, status, first in / last out, late, early, short, overtime, reconciled, link to day detail); phone width keeps the card layout already used by `.ui-table`.
- **FR-012 Last data update**: "Last data update" shows the newest upload per kind and links to Recent uploads.

### General

- **FR-013** English and Arabic, RTL, phone width (390 px) without horizontal scroll; amber only for the one primary action per screen (DESIGN.md).
- **FR-014 Tests**: classification of the four sample layouts (anonymised fixtures only, never the real files); one-click import produces the same batches as the current multi-step flow; a file needing a decision cannot be imported without it; coverage defaults equal the existing suggestions; results card counts equal the batch/processing records; dashboard needs-action figures equal the existing tiles for the same filters; tenant isolation for the new page, polling endpoint and results links; browser test for drop → import → results in en/ar at desktop and phone.

## Out of scope

Direct device integration; new calculation rules; removing the old upload pages; holiday announcement upload; email/SMTP notifications.

## Product-owner answers (2026-10-28)

Accepted the orchestrator's recommendations:

1. **Old upload pages**: removed from the menus, still reachable through an "Advanced upload" link on the Upload page; delete them later if unused.
2. **Several files at once**: fixed order employee list → leave balance → leave report → attendance.
3. **Needs-action cards**: uncovered absences, missing punches, cases waiting for HR, leave waiting for approval, unreconciled minutes, and **"Data to fix"**, which counts only issues HR can act on (unknown employee codes, new devices, unmapped leave types, security work patterns to review), not informational issues such as compensation leave above entitlement.
4. **Unknown employee codes**: never block; import, show the count in the file's summary sentence and list them under "Data to fix". Exception: when more than half of an attendance file's codes are unknown, ask "Is this the right file?" before importing.
