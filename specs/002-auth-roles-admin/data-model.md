# Data Model Delta: Sign-in, Roles and Admin Screens

Builds on `specs/001-foundation-schema/data-model.md` and `docs/ERD.md`. Only changes are listed.

## Enum changes
- `UserRole`: remove `company_legal_reviewer`. Data migration: existing rows → `reporter`,
  `is_active = false`, + permission `review_internal_regulation` (never escalates access).

## New / seeded rows
- spatie `permissions` (guard `web`): `review_internal_regulation`, `view_all_company_cases` —
  inserted by a migration (idempotent), removed on rollback.
- Users get permissions through spatie's `model_has_permissions`. Teams mode stays off.

## Column changes
- None required. `users.is_active`, `companies.is_active`, `companies.subscription_status`,
  `branch_user`, `company_hr_partner.full_case_access`, `committee_mandates.superseded_at`,
  `committee_pool_members.user_id` already exist.

## Rules enforced in code (with tests)
| rule | where |
|---|---|
| at most one active `is_general_manager` employee per company | `Employee` saving guard |
| add-ons only on company roles; `view_all_company_cases` only for hr_staff; `review_internal_regulation` on any company role | `UserAccountService` |
| a new mandate's `effective_from` must be later than the latest mandate's (no overlap, no backdating) | `CommitteeMandateService` |
| last active company admin cannot be deactivated or demoted | `UserAccountService` |
| new mandate supersedes the currently effective one at its `effective_from` | `CommitteeMandateService` |
| branch scope (`branch_user`) only for reporter / dept_manager | `UserAccountService` |
| pool member `user_id` must be a committee_member, hr_staff or company_admin of the same company (relaxed in feature 003 so HR alternates keep one account) | `CommitteePoolMember` saving guard |
| external reviewer must have `law_firm_partner_id`; HR-partner staff must have `hr_partner_id` | `User` saving guard |

## Config
- `config/displanery.php`: `idle_timeout_minutes` = `{default: 60, platform: 30}`, add-on permission
  names.
- `config/app.php`: `timezone` = `env('APP_TIMEZONE', 'Asia/Baghdad')`; `.env.example` gets
  `APP_TIMEZONE=Asia/Baghdad`.
