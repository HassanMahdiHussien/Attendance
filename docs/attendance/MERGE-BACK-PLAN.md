# Merge-back plan: Attendance into Displanary

**Status:** Draft for the product owner, 2026-10-25. Implements the "merge back later" decision in ADR 0013.

## 1. Where things stand

Checked on 2026-10-25 against both repositories:

- **Displanary has not changed since the copy.** Its `main` is still `2d85ddb`, the commit Attendance was copied from. Attendance has 92 commits on top of it.
- **The spec-500 cleanup is six commits:** `44c683a` (move Displanary-only docs), `49ee19d` (remove the discipline module: 726 files, 38 migrations deleted), `46f267f` (constitution and wording), `6e9c924`, `18a4161` (Arabic platform name), `3e7e64b` (product renamed to Attendance).
- **The cleanup only deleted.** It added no "drop tables" migration, so Displanary's production database has nothing to lose.
- **Only the cleanup touched existing migrations.** The 11 original migrations it edited are restored exactly when it is undone. Every attendance schema change is in new migrations: 13 `*_attendance_*` files and 11 others (employee import, employee versions, leave, balances, overtime, search). On Displanary's database these run as ordinary new migrations.
- **A trial merge** (Attendance `main` at `e541ac5`, cleanup commits reverted in a throwaway clone) gives:

  | Reverted commit | Conflicting files |
  |---|---|
  | `49ee19d` remove discipline module | 31 |
  | `44c683a` move docs | 6 (docs, law PDFs, spec 500 tasks) |
  | `46f267f`, `3e7e64b`, `18a4161` | 1–2 each (FOUNDATION-CHANGES, WEIRDNESS, `lang/ar.json`) |
  | `6e9c924` | 0 |

  The 31 files are the shared seams expected by ADR 0013: navigation, routes, `AppServiceProvider`, the HR dashboard, home and employee pages, `EmployeeProfile`, global search, `lang/*`, the employee help guide, the tenancy, route and trigger test lists, and `ui-inventory.json`. Almost all are resolved by keeping both sides.
- Both apps already default to English (ADR 0014); Arabic stays per user.

## 2. Recommendation: merge the code before Attendance goes to production

Attendance has not been deployed yet. If it is merged first and then deployed **once, into Displanary's production**, the work is code-only:

- one database;
- one employee list;
- no data migration between two live systems.

If Attendance went live separately first, the merge would also need to move every attendance table into Displanary's database and remap company, employee, user and branch ids, with both systems changing meanwhile. That is the expensive path ADR 0013 warns about.

**Freeze point:** while the merge is in progress, avoid changes in Displanary's `main`. Today it would be a pure fast-forward.

## 3. Steps

1. **Merge branch.** In the Attendance repo, branch `displanary-merge` from `main` once the open PRs (#15, #16, #17) are merged. Revert the cleanup commits newest-first: `3e7e64b`, `18a4161`, `6e9c924`, `46f267f`, `49ee19d`, `44c683a`.
2. **Resolve the conflicts by keeping both modules:**
   - **Navigation:** Discipline and Attendance menus side by side, each shown only for its roles.
   - **Routes and service provider:** both registrations.
   - **Translations:** union of the keys. Where the same key changed (the product and Home labels), take the product owner's decision in §5.
   - **Test lists** (`IsolationTest`, `CompositeForeignKeyTest`, `TriggerSmokeTest`, `RouteSweepTest`, `ui-inventory.json`): union.
   - **Docs:** restore `docs/upstream/displanary/*` to their original places and keep the attendance docs under `docs/attendance/`.
3. **One employee page and one HR dashboard.** This is the reason for the merge.
   - The employee page shows the discipline sections and the attendance section (attendance tabs, statement).
   - The HR dashboard shows both sets of tiles under one filter bar.
   - Global search finds an employee once and opens that page.

   These are small view changes in the files that conflict anyway. Spec them as **spec 520 "unified employee page and dashboard"** so they are reviewed like any other spec.
4. **Apply `docs/upstream/FOUNDATION-CHANGES.md` line by line.** Each entry names a file, the change and why. After step 1 most are already present in the merge branch; the log is the checklist that nothing was lost in conflict resolution.
5. **Run both test suites together** in WSL: Displanary's discipline tests come back with the reverts. The full suite, browser tests and Pint must pass.
6. **Rehearse on a copy of production.**
   - Restore Displanary's latest encrypted backup into a staging database and run `php artisan migrate`. Only new migrations should run.
   - Import the HC employee file through the employee import. It matches on `employee_code` (unique per company in both apps), so existing Displanary employees are extended, not duplicated.
   - Check that discipline cases still open, and that attendance imports and calculations run on the real samples (the smoke checks used for specs 502–512).
7. **Land it in Displanary.** Displanary's `main` has not moved, so it is a fast-forward to `displanary-merge` (or a normal merge if it moved). Do it through a PR in `HassanMahdiHussien/Displanery` with the rehearsal results attached.
8. **Deploy** with the existing `deploy/` script, set `ANTHROPIC_API_KEY` and SMTP, and switch on attendance for the pilot company.
9. **Retire the Attendance repo** (archive it read-only). Further attendance specs continue in Displanary, numbered from 500 as now.

**Size:** steps 1–5 are about one to two working days of implementation plus review. Step 6 needs production backup access, so it is the product owner's slot.

## 4. Risks and how they are handled

| Risk | Handling |
|---|---|
| Displanary changes before the merge | Freeze point (§2). If it moved, merge its `main` into `displanary-merge` before step 5; the conflicts are the same seams. |
| A foundation fix lost in conflict resolution | `FOUNDATION-CHANGES.md` checklist (step 4) plus both test suites. |
| Production employees missing `employee_code` or with different codes than HC | Rehearsal (step 6) lists unmatched rows before anything is deployed. |
| Discipline roles and attendance roles overlap | `UserRole` keeps both sets after the revert. Check HR staff permissions in both menus during the rehearsal. |
| Migration order | On production, every Displanary migration has already run, so only the attendance migrations (dated 2026-10-22 onward) are new. On a fresh install, Displanary's two migrations dated 2026-10-31 and 2026-11-01 sort after the attendance ones. They alter `violation_reports` and `companies`, which attendance migrations do not depend on. `migrate:fresh` in step 5 proves the order. |

## 5. Decisions needed from the product owner

1. **Product name** after the merge: Displanary, Attendance, or a new name for the platform (affects `lang/*`, emails, brand).
2. **Is Displanary's pilot company the same company** whose attendance files we use? If yes, the HC import extends its employees (step 6). If no, attendance is enabled for a second company and nothing is shared.
3. **Timing:** merge before the first attendance deployment (recommended), or deploy Attendance on its own first and accept a later data migration.
4. **Who gets which menus:** whether HR staff see both modules by default or each module is switched on per company.
