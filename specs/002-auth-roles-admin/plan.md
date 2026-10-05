# Implementation Plan: Sign-in, Roles and Admin Screens

**Branch**: `002-auth-roles-admin` | **Date**: 2026-10-05 | **Spec**: [spec.md](./spec.md)

## Summary

This feature adds sign-in for the roles that have screens, the role + add-on access model,
data-layer case visibility for every role, two Filament v5 panels (platform and company setup with
company switching) in an Arabic RTL theme, audit logging of auth and admin changes, the Asia/Baghdad
clock, and the recorded leave-confirmation decision.

## Technical Context

**Language/Version**: PHP 8.4 · **Primary Dependencies**: Laravel 13, Filament 5, Livewire 4,
spatie/laravel-permission 8 (all installed; no new packages expected) · **Storage**: MySQL 8.4 ·
**Testing**: Pest 5 + Livewire/Filament test helpers on MySQL · **Target Platform**: Sail locally ·
**Project Type**: web monolith · **Constraints**: fail-closed tenancy must hold inside Filament;
no hard deletes; Arabic RTL · **Scale/Scope**: 2 panels, ~9 resources, 3 services, 3 middleware,
~8 policies, ~8 test files

## Constitution Check

| Principle | Status | How |
|---|---|---|
| I. Tenant isolation | PASS | Filament tenant mapped into `TenantContext`; cross-company only in `CaseVisibility` (grep test); access matrix tests |
| II. AI drafts, humans decide | n/a | no AI in this feature |
| III. Immutable & audited | PASS | `Audited` trait + auth listeners; no hard deletes offered |
| IV. Tests for load-bearing logic | PASS | access matrix (SC-001), visibility (SC-002), deactivation (SC-005), timezone year |
| V. Simple Laravel, thin UI | PASS | services for onboarding / accounts / mandates; resources call services |
| VI. Decisions stay decided | PASS | role model from clarification; leave decision recorded in CLAUDE.md + WEIRDNESS |
| Stack / approvals | PASS | no new packages; flag if `make:filament-theme` needs any |

## Project Structure

```text
app/
├── Filament/
│   ├── Auth/Login.php                     # per-email limiter
│   ├── Platform/Resources/                # CompanyResource, LawFirmPartnerResource, HrPartnerResource, PlatformUserResource
│   └── Admin/Resources/                   # BranchResource, LocationResource, EmployeeResource, UserResource,
│       └── Pages/CompanySettings.php      # CommitteeMandateResource (+ pool relation manager)
├── Http/Middleware/                       # EnsureAccountIsActive, EnforceIdleTimeout, SetTenantFromFilament
├── Http/Responses/LoginResponse.php
├── Listeners/AuditAuthEvents.php
├── Models/Concerns/Audited.php
├── Policies/                              # Company, Branch, Location, Employee, User, CommitteeMandate, CommitteePoolMember, LawFirmPartner, HrPartner, DisciplinaryCase
├── Providers/Filament/{Platform,Admin}PanelProvider.php
├── Services/{CompanyOnboardingService,UserAccountService,CommitteeMandateService}.php
└── Support/Access/{CaseVisibility,ViolationReportVisibility}.php
config/displanery.php
database/migrations/…_remove_company_legal_reviewer_role.php, …_seed_addon_permissions.php
resources/css/filament/theme.css (Filament theme)
tests/Feature/Auth/, tests/Feature/Access/, tests/Feature/Admin/, tests/Feature/Platform/, tests/Feature/Timezone/
CLAUDE.md §3, WEIRDNESS.md (leave decision), docs/ERD.md (role enum)
```

## Delegation plan

The plan is cross-checked by Codex (read-only). Implementation then runs in two Codex runs:
**D** = foundation (timezone, role migration, permissions, config, middleware, login, listeners,
Audited trait, policies, visibility, services, panels skeleton + access tests), and **E** = Filament
resources and pages + screen tests + docs. Codex is sandboxed without Docker, so the orchestrator
runs the gates after each run and sends fix briefs.

## Complexity Tracking

None.
