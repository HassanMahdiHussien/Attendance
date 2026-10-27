# Plan: 513 one-step upload and action-first dashboard

Implementation goes through Codex in four steps, each gated (full suite, browser tests, Pint) and committed separately. Real sample files are used only for local checks in WSL, never committed and never uploaded to the demo site.

## Step 1: Upload page (FR-001 to FR-004, FR-013)

- `App\Services\Attendance\UploadDetector` (new): wraps the existing `TransactionFile`, `LeaveFile`, `LeaveBalanceFile` and employee import inspectors; returns kind, mapping (built-in or saved), suggested coverage/scope and the existing preview arrays. No new parsing rules.
- `App\Filament\Admin\Pages\Attendance\UploadCenter` (new page, Livewire file uploads, multiple): rows per file with summary sentence, inline decisions (mapping, coverage Change, already-imported), one Import button calling the existing `commit()` methods in FR order.
- Navigation: first item "Upload"; dashboard button. Old pages link to it.
- Tests: detection on anonymised fixtures for each kind; one-click import equals the old flow's batches; decisions block import; isolation.

## Step 2: Progress and results (FR-005 to FR-007, FR-012)

- Read model `UploadResults` (new, service): stages from batch events; counts from batches, processing runs, data issues and follow-up cases linked by batch id. Where a link from batch to processing run or case is missing, add it through the existing run/batch ids rather than recomputing.
- Results card component with links to filtered screens (existing query parameters on daily attendance, issues, leave requests).
- Recent uploads list (all kinds) on Upload page and dashboard; "Last data update" links to it.
- Tests: card counts equal records; links carry the right filters; polling endpoint isolation.

## Step 3: Dashboard (FR-008 to FR-010)

- Keep `HrDashboard` figures and services; change the view only: needs-action row, grouped sections, quiet zero tiles, compact filter bar, Export menu.
- Tests: needs-action numbers equal existing tile values for the same filters (agreement test); existing dashboard tests keep passing (texts may move, values not).

## Step 4: Employee page (FR-011) and final checks

- Same grouping; daily record as `.ui-table` (phone card layout already exists).
- Re-run the Impeccable detector on all screens (en/ar, desktop/phone) and the click-through crawler; screenshots to the product owner.
- Docs: help pages (en/ar) for Upload; ROADMAP row 513; WEIRDNESS entries for the fixed import order and the pre-filled coverage.

## Risks

- **Coverage pre-fill hides a real gap**: the sentence must state the period and branches, and the existing outage/coverage checks still run; a partial export (one branch) is shown as such.
- **Large files in one request**: inspection stays per file; uploads use Livewire temporary files as today (100 MB attendance limit).
- **Agreement**: the dashboard change is view-only; the agreement tests guard the numbers.
