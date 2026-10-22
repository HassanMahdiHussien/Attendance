# Feature Specification: Automatic design audit

**Branch**: `053-design-audit` | **Created**: 2026-10-17 | **Status**: Approved

**Input**: Product owner (2026-10-17): headers differ between My work and Settings, Employees appears twice — "how can we check everything? I can't compare everything." Approved: build an automatic check first (+ the AI design reviewer), then one fix PR, then re-run.

## Requirements
- **FR-001** One local command (`npm run audit:design`) signs in as each seeded demo role, crawls every page reachable through links (GET only — never submits forms or clicks buttons), in Arabic and English, and writes a report.
- **FR-002** Sign-in without passwords: a local-only artisan command issues a session cookie for a seeded `@example.test` user (refuses outside `local`, refuses other emails), like the existing dev helper.
- **FR-003** Per page checks: HTTP status ≥ 400; browser console errors and page errors; horizontal overflow at 1280 and 375 px; brand mark present (`[data-brand-mark]`); header signature (brand + top-level menu labels/targets + search present) compared with the workspace header of the same role/locale — differences flagged; duplicate menu items (same label to different places, or same place under different labels, across top-level and dropdown menus); wrong-language UI text (Latin words in buttons/labels/table headers/menus/tabs on Arabic pages, Arabic letters in those elements on English pages; names, emails, codes and numbers ignored).
- **FR-004** Report: one self-contained HTML page (+ images): problems first (grouped by type, each linking to its screenshots), then a gallery by role → page with Arabic and English side by side, desktop and phone. Also `results.json`. Screenshots JPEG, compact.
- **FR-005** Safe: skips logout, locale switch, downloads (pdf, attachments, exports), Livewire endpoints, help images; caps pages per role; demo data only; output under `storage/app/design-audit/<timestamp>/` (git-ignored).
- **FR-006** AI design reviewer: a TesterArmy "designer" bug bash over the same roles (ChatGPT subscription), findings added to the report as a separate section.

## Success
The product owner reviews one report instead of comparing screens; re-running after fixes shows the flagged problems gone.
