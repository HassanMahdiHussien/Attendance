# Tasks: Dashboards

**Tests**: REQUIRED (Pest + Livewire). Gates (as sail): `docker compose exec -T -u sail laravel.test php artisan test`
· `… ./vendor/bin/pint --test`. Never run two test processes at once.

Research R7 (plan review resolutions + product owner decisions) overrides earlier sections.

## Run S
- [ ] T001 Migration: companies hotspot_min_cases (3), hotspot_window_days (90); composite indexes on cases (R7 B6); CompanySettings fields (company admin, validated, audited); CaseVisibility::aggregateFor (R7 A1); CaseAlertDispatcher cancelled-consultation fix (R7 B5)
- [ ] T002 CaseMetrics + DashboardFilters + Hotspots (R1–R4): alerts, counters, hotspots with trends, threshold alerts, drill-down queries — all from CaseVisibility, aggregate SQL
- [ ] T003 HR dashboard `/dashboard` + drill-down `/dashboard/cases` (filters in query string, alerts first, counters, hotspots with bars and trend arrows, threshold alerts, empty states, Arabic digits/Iraqi dates, mockup tokens); nav item "لوحة المتابعة"
- [ ] T004 Platform Admin companies overview (Filament platform, table + totals, links to the company resource)
- [ ] T005 HR-partner overview at `/home` (per partner company counters and top hotspots; case lists only with full_case_access) and reviewer summary on `/my-cases`
- [ ] T006 Tests (R6): visibility matrix per role and section incl. drill-down, counter = drill-down, deadline/alert edges, trends, threshold settings, empty state, bounded query count on ~2,000 cases
- [ ] T007 docs: ERD (company columns), WEIRDNESS if any non-obvious choice; orchestrator walkthrough; PR
