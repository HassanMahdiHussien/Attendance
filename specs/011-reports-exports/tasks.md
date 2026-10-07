# Tasks: Reports, Exports and the Employee Page

**Tests**: REQUIRED (Pest + Livewire). Gates (as sail): `docker compose exec -T -u sail laravel.test php artisan test`
· `… ./vendor/bin/pint --test`. Never run two test processes at once.

## Setup (orchestrator)
- [x] T001 composer `openspout/openspout` ^4.32 (approved)

Research R7 (plan review resolutions) overrides earlier sections.

## Run T
- [x] T002 Migration report_exports (tenant FKs, working down()); model; register in tenancy test registries
- [x] T003 EmployeeProfile + `/employees` search + `/employees/{employee}` page (R2); links from case page, board, drill-down
- [x] T004 ReportBuilder (periodic, employee, compliance, hotspots) on CaseMetrics/Hotspots/Deadlines (R1, R4)
- [x] T005 ReportExporter + GenerateReportExport job + PDF renderer (Blade + PdfGenerator, letterhead, row cap) + Excel renderer (OpenSpout, RTL, streamed) + owner-only download route + prune command/schedule + audit (R3)
- [x] T006 `/statistics` page: report tabs, filters, preview, export buttons, "صادراتي" with polling (R5); nav item
- [x] T007 Tests (R6)
- [x] T008 docs: ERD (report_exports), WEIRDNESS if needed; orchestrator walkthrough; PR
