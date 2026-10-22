# Feature Specification: UI consistency fixes from the design audit

**Branch**: `054-ui-consistency-fixes` | **Created**: 2026-10-18 | **Status**: Approved ("fix them")

**Input**: Design audit report (automatic crawl of 488 pages + AI designer review), 11 confirmed problems. Report: https://claude.ai/artifact/WK3AWrwFwNGAJq8gKt78xJ

## Requirements (one per problem)
- **FR-01 One header everywhere.** The admin panel (`/admin/...`) and platform panel (`/platform/...`) render the **same header as the workspace** (brand + company subtitle, the role's top menu from `Nav::items`, the Settings dropdown from `AdminNav::items` with the current page highlighted, search, account menu with Help/language/logout) and the same phone bottom bar / More sheet. Filament's own top bar is not shown. A user with more than one company keeps a way to switch company (in the account menu). The platform admin gets a "Platform" dropdown (dashboard, law mismatches, companies, HR partners, law firms, legal library, platform users) in the same header. Same height, width, spacing and fonts on every page.
- **FR-02 One "Employees".** Remove Employees from the Settings menu and from the admin panel navigation; the workspace Employees page (`/employees`) shows **Add employee**, **Import CSV** and per-row **Edit** for users allowed to manage employees, linking to the existing admin screens. The admin employee screens keep working by URL and from those buttons.
- **FR-03 No forbidden links.** The case page's "Cases board" link shows only to users who can open the board; others get a link to their own list (My cases), or none.
- **FR-04 Distinct column names** on the Cases board: number column «رقم الحالة» / "Case number", status column keeps «الحالة» / "Status" (no two columns with the same label). Check other tables for the same clash.
- **FR-05 Reporter navigation.** A reporter (and dept manager) sees one entry for their reports, not two that open the same list.
- **FR-06 Archived cases look closed.** An archived case's stage rail, departures strip and due-date table show it as closed/archived, not "awaiting decision" or "appeal not started". Confirm the bug first.
- **FR-07 No English in Arabic admin screens.** Add Arabic for Filament component strings that show in English (seen: "Clear selection", "Remove"); sweep the Filament components the app uses.
- **FR-08 Statistics "ISO" column.** Translate/explain the header (e.g. "Date (YYYY-MM-DD)" / «التاريخ (سنة-شهر-يوم)») or remove it if redundant; both languages, screen and exports.
- **FR-09 Activity list.** Identical consecutive activity lines (same event, case, actor, minute) are merged into one line with a count.
- **FR-10 Reporter report rows.** Rows in My reports are not styled as links when they open nothing; they show number, date and status plainly.
- **FR-11 Help wording.** Rewrite the FAQ sentence about closing a recommendation-only case (ar + en) to say it closes after the signed recommendations are uploaded and any requested consultation is finished.
- **FR-12 Tests** for FR-01..05 and FR-09 at least (header identical across workspace/admin/platform per role; Employees appears once; case page link visibility; board headers distinct; reporter nav; activity merge). Existing suite, translation coverage, behaviour inventory and route sweep stay green.

## Success
Re-running `npm run audit:design` shows no header-signature, duplicate-menu or forbidden-link problems, and the 11 items are gone.
