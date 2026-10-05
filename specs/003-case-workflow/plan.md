# Implementation Plan: Case Workflow Engine

**Branch**: `003-case-workflow` | **Date**: 2026-10-05 | **Spec**: [spec.md](./spec.md)

## Summary
A service-layer workflow engine: an explicit transition map with locked, logged transitions; domain
services for opening, sessions, approvals, hand-offs, consultation, appeals and leave confirmation;
pure deadline maths in Baghdad calendar days; a send-once scheduled alert command and appeal
auto-archive. No UI.

## Technical Context
**Language/Version**: PHP 8.4 · **Dependencies**: Laravel 13 (notifications, scheduler, queues), no new
packages · **Storage**: MySQL · **Testing**: Pest on MySQL, `travel()` for clocks ·
**Constraints**: fail-closed tenancy; transitions serialised; immutable logs; calendar days only ·
**Scale/Scope**: ~10 services, 1 command, 1 notification, ~4 migrations, ~10 test files

## Constitution Check
| Principle | Status | How |
|---|---|---|
| I. Tenant isolation | PASS | services use the tenant context and policies; the scheduler uses `runForCompany` per company |
| II. AI drafts, humans decide | PASS | AI stages have manual + cancel paths; no decision is automated (banners are visibility only) |
| III. Immutable & audited | PASS | every transition is a case event; locked-document trigger extended to the new columns |
| IV. Load-bearing tests | PASS | transition matrix, deadlines table, quorum / majority / conflict, approval gate, send-once alerts |
| V. Simple Laravel | PASS | plain map + services; no state-machine package |
| VI. Decisions stay decided | PASS | calendar days; request-only consultation; leave confirmation; firm-wide visibility |

## Structure
```text
app/Workflow/CaseTransitions.php
app/Services/Cases/{CaseWorkflow,CaseOpening,Deadlines,CaseInsights,CommitteeSessionService,
  DocumentApprovalService,HandOffService,ConsultationService,AppealService,TerminationLeaveConfirmation}.php
app/Exceptions/{IllegalTransition,TransitionBlocked}.php
app/Console/Commands/SendDeadlineAlerts.php, ArchiveExpiredAppeals.php   (scheduled in routes/console.php)
app/Notifications/CaseDeadlineAlert.php
app/Models/CaseAlert.php
database/migrations/… (laws.category, cases consultation/majority, documents leave confirmation + trigger, case_alerts, notifications)
tests/Feature/Workflow/{TransitionMatrixTest,EndToEndTest,DeadlinesTest,CommitteeSessionTest,ApprovalGateTest,
  IntakeChecksTest,HandOffTest,ConsultationAppealLeaveTest,AlertsTest,StatusWriteGuardTest}.php
```

## Delegation
Codex plan review (read-only), then run F (state machine, opening, deadlines, alerts, intake) and
run G (committee, approvals, hand-offs, consultation / appeal / leave, end-to-end). The orchestrator
runs the gates after each run.

## Complexity Tracking
None.
