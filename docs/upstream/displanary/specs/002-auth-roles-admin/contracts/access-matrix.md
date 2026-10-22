# Contract: Access matrix (feature 002)

Every cell is enforced by a policy / panel check and covered by a Pest test (SC-001).
✔ = allowed, ✖ = refused (403 or hidden + refused on direct URL), — = not applicable.

## Panel access (`canAccessPanel`)

| role | /platform | /admin (own company) | /admin (other company) |
|---|---|---|---|
| platform_admin | ✔ | ✔ (any company via switch) | ✔ |
| company_admin | ✖ | ✔ | ✖ |
| hr_staff | ✖ | ✔ | ✖ |
| dept_manager, reporter, committee_member | ✖ (sign-in refused) | ✖ | ✖ |
| external_legal_reviewer, hr_partner_staff | ✖ (sign-in refused) | ✖ | ✖ |
| any inactive user / user of inactive company | ✖ | ✖ | ✖ |

## Platform panel resources (platform_admin only)

| resource | list/view | create | edit | activate/deactivate | delete |
|---|---|---|---|---|---|
| Companies (+ first admin on create) | ✔ | ✔ | ✔ | ✔ | ✖ never |
| Law-firm partners (+ company partnerships) | ✔ | ✔ | ✔ | — | ✖ |
| HR partners (+ partnerships, full_case_access) | ✔ | ✔ | ✔ | — | ✖ |
| Platform users (platform_admin, external_legal_reviewer, hr_partner_staff) | ✔ | ✔ | ✔ | ✔ | ✖ |

## Admin (company) panel resources

| resource | company_admin | hr_staff | platform_admin (in tenant) |
|---|---|---|---|
| Branches | CRUD (no delete; deactivate) | view | as company_admin |
| Locations | CRUD (no delete; deactivate) | CRUD (no delete; deactivate) | as company_admin |
| Employees | CRUD (no delete; deactivate) | CRUD (no delete; deactivate) | as company_admin |
| Users — company_admin / hr_staff / committee_member | manage | ✖ | as company_admin |
| Users — reporter / dept_manager | manage | manage | as company_admin |
| Add-on abilities | manage | ✖ | as company_admin |
| Administrative Orders + pool | manage | view | as company_admin |
| Company settings (letterhead, languages, second review) | edit | ✖ | as company_admin |

No resource ever offers hard delete of a company, branch, location, employee or user.

## Case visibility (`CaseVisibility::for`)

| role | visible cases |
|---|---|
| platform_admin | all companies |
| company_admin | own company |
| hr_staff | own company, opened_by = me OR assigned_hr = me; all own company with `view_all_company_cases` |
| committee_member | own company, attended (pool member.user_id = me) |
| dept_manager, reporter | none |
| external_legal_reviewer | any company partnered with my law firm, consultation requested (firm-wide, confirmed by product owner) |
| hr_partner_staff | any company partnered with my HR partner with full_case_access |

## Request types covered by deactivation / idle tests (SC-005)
panel GET, Livewire update POST (table action, form save), direct record URL, a plain authenticated web route.

## Audit events
`auth.login`, `auth.logout`, `auth.failed`, `<model>.created|updated|deleted` for audited models (before/after,
secrets excluded), `user.addons_changed`, `user.deactivated` (with sessions purged count),
`mandate.registered`, `auth.throttled`, `partnership.attached|detached|updated`.
