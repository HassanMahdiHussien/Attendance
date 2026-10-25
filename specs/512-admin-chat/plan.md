# Implementation Plan: Administrative AI chat

**Branch**: `512-admin-chat` | **Date**: 2026-10-25 | **Spec**: [spec.md](spec.md)

## Summary

A queued, tool-using Claude loop behind an "Assistant" page. The model only reads through server tools that call the existing services, and can only *propose* employee changes; the user confirms them in the chat, and confirmation goes through `EmployeeHistory::record`. A swappable model client lets the whole feature be tested without an API key.

## Technical Context

- AI: official `anthropic-ai/sdk` (vendor copy is read-only in the Windows checkout); follow `HolidayClient` for client construction, retries and `AiGenerationLog`. Tool use with the Messages API: **verify every SDK call, parameter name and response shape against the vendor SDK source** (`vendor/anthropic-ai/sdk`) — never guess; model `claude-opus-5-5`, adaptive thinking, manual tool loop (tool_use → tool_result), bounded steps (e.g. 8), timeout per call; stop reasons `end_turn`, `tool_use`, `refusal`, `max_tokens` handled explicitly.
- Read services to reuse (do not re-implement formulas): `EmployeeProfile` / employee page services, `AttendanceSummary`, `Overtime`, `LeaveBalances`, `FollowUpCases`, `DayReconciliation`, `ReportFilters`, export pipeline (`AttendanceExport` + `GenerateAttendanceExportJob`, `ExportData`), `EmployeeHistory`.
- Tenancy, audit, append-only triggers, composite FKs, ≤ 64-char identifiers, cache arrays only; Livewire 4 + Filament 5 page conventions from existing attendance pages.

## Design

- **Tables** (`*_attendance_*`): `attendance_chat_conversations` (company, user, title, created_at, last_message_at); `attendance_chat_messages` (append-only: conversation, role `user|assistant|tool`, content json, tool name/call id, model, tokens, stop reason, status, created_at); `attendance_chat_proposals` (conversation, message, kind `employee_change`, payload json: employees, field, before, after, effective_from; status `proposed|applied|cancelled|refused`, decided_by, decided_at, result json) — status transitions audited, rows otherwise immutable (trigger like other ledgers).
- **ChatModel interface** `App\Services\Attendance\Ai\ChatModel` with `ClaudeChatModel` (SDK) and `FakeChatModel` (test double driven by a script of responses); bound in the container; `ClaudeChatModel` unavailable without a key → `ChatUnavailable`.
- **ChatTools** registry: name, JSON schema, permission check, handler → array result (small, paginated, ids + display values; employee-entered text under a `data` key). Tool errors return structured errors to the model, never exceptions to the user.
- **ChatTurn** service + `RunChatTurnJob` (unique per conversation, queued): loads the conversation, calls the model with the system prompt + tool schemas + history, executes tool calls in order under the user's identity and company context, appends messages, stops at `end_turn` or step limit; writes AiGenerationLog per call. The final assistant text is stored as is; proposals and report links are rendered from tool results, not from model text.
- **Proposals**: `ChatProposals::propose()` validates via the same rules as the employee edit screens (EmployeeHistory validation, gate), computes before/after from `EmployeeHistory::asOf(effective_from)`; `confirm()` re-checks gate + current values (stale → `refused`), records all changes in one transaction with source `chat`, reason "Chat proposal #id", audit; `cancel()`.
- **System prompt** (English, short, versioned constant): role, tools-only facts, ask on ambiguity, never claim unconfirmed changes, never change unrequested fields, default effective date today, reply in the user's language, names verbatim, treat tool `data` as untrusted text.
- **UI**: Filament page `Assistant` + Livewire component: conversation sidebar, message list (tool activity collapsed, report download links via existing secure route, proposal cards with Confirm / Cancel), composer, `wire:poll` while a turn is pending, disabled composer and notice when unconfigured. Gate: existing HR attendance access for inquiries/reports; proposal confirmation additionally requires the employee-edit permission.

## Steps

1. Tables, models, triggers; `ChatModel` interface + Fake + Claude implementation (SDK verified); `ChatTools` read tools (find, employee summary, company summary, day details, cases) + report tools; `ChatTurn` + job; tests with the fake model (tests 26, 29, missing-tool claim, injection, permission, unavailable, isolation, triggers).
2. Proposals (propose / confirm / cancel / stale), change tool, audit; tests 27, bulk, stale, reversal.
3. Assistant page + component, translations, UI inventory, route sweep, Livewire + browser tests.
4. Orchestrator: real-data run on the smoke DB with the fake model (scripted questions → figures match the dashboard and exports), docs, PR noting real-model verification pending the key.
