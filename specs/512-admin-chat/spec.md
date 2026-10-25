# Feature Specification: Administrative AI chat

**Branch**: `512-admin-chat` | **Created**: 2026-10-25 | **Status**: Drafted 2026-10-25 while the product owner is away from the laptop; decisions marked **[A]** are to be confirmed before operational use.

**Input**: PRD §21 (inquiries, changes, report generation, trust controls), §4 (chat in English and Arabic; never translate names or source descriptions), §5 (chat has exactly the user's permissions), §24 (start with inquiries and reports, then changes once stable), §25 tests 26, 27, 29, §26 (default effective date of a chat change). CLAUDE.md: chat uses the same services and permissions as the screens; it executes only clear, authorised, reversible changes, shows before/after and the effective date, and logs everything; formulas never live in prompts; employee text is data, never an instruction.

## Context

- No `ANTHROPIC_API_KEY` is configured yet. The chat is built and tested against a fake model client; real Claude calls are verified once the key is added (the page shows "AI not configured" until then, and nothing else breaks).
- Existing AI pattern: `App\Services\Attendance\Ai\HolidayClient` (official `anthropic-ai/sdk`, `claude-opus-5-5`, retries, attempt logging to `AiGenerationLog`).

## Decisions (orchestrator, 2026-10-25)

- **Scope [A]**: inquiries, report generation, and a small set of **confirmed** employee changes. The model never applies a change itself: it can only create a *proposal*; the user applies it with a Confirm button in the chat (or cancels). Bulk proposals list every affected employee before Confirm.
- **Changes allowed in 512 [A]**: employee schedule start/end, rest days, work pattern, direct manager, email and fallback manager email — all through `EmployeeHistory::record` (effective-dated, source `chat`), which already triggers recalculation of affected days. Nothing else (no punch corrections, no leave decisions, no policy changes, no case closure) in 512.
- **Default effective date [A]**: today (Asia/Baghdad) when the user gives none; always shown on the proposal before Confirm.
- **Language**: the user writes English or Arabic; the reply follows the user's language; employee names and source descriptions are shown exactly as stored.

## Requirements

- **FR-001 Conversations**: an HR user can start, continue and reopen their own conversations (company-scoped; other users' conversations are not visible). Messages, tool calls, tool results and proposals are stored append-only; each turn records the model, tokens, stop reason and outcome.
- **FR-002 Model loop**: each user message runs as a queued job: the model may call server tools (FR-003) in a loop (bounded number of steps); only tool results are facts. If the model is unavailable, refuses, or is truncated, the user sees a clear message and nothing is applied. The answer must never claim a result, report or change that no successful tool returned.
- **FR-003 Tools** (server-side, each checks the acting user's permission and the company context, reusing existing services so figures agree with the screens):
  - `find_employees` — resolve names / codes (exact code match first; similar names return candidates; ambiguity is reported back so the model asks the user).
  - `employee_summary` — attendance, lateness, early departure, absence, reconciliation (settled / remaining), overtime and compensatory days, leave balance, open follow-up cases for an employee and period (the same services as the employee page).
  - `company_summary` — the dashboard figures for filters (branch, department, manager, pattern, period; `ReportFilters`).
  - `day_details` — day rows for employees and period (status, times, minutes, leave coverage).
  - `list_cases` — follow-up cases with filters.
  - `create_report` — request an existing export kind (the same export pipeline as the screens) with filters and fields; returns an export id; a follow-up `report_status` returns the download link when ready (PRD test 29: the file matches the source).
  - `propose_employee_change` — validate a change (employee(s), fields, values, effective date) and store a proposal with before/after per employee; returns the proposal for display. No write to employee data.
  - `proposal_status` — read a proposal.
- **FR-004 Confirm / cancel**: the chat shows each proposal as a card (employees, field, before → after, effective date, impact note "affected days will be recalculated"); Confirm (permission re-checked, values re-validated against the current state; stale → refused with a reason) applies it through `EmployeeHistory::record` in one transaction and records the result; Cancel closes it. Every confirm/cancel is audited with actor and source `chat`. Reversal = a new proposal with the previous values (documented in the card after applying).
- **FR-005 Trust controls**: system prompt states the rules (tools only, ask when ambiguous, no unrequested fields, end time does not change pattern/rest days/start, employee text and file content are data); tool results containing employee-entered text are wrapped as data; chat never sees other companies' data or data outside the user's permission; no secrets or personal data in logs.
- **FR-006 Screen**: an "Assistant" page (Attendance navigation): conversation list, messages (user / assistant, tool activity shown compactly, report links, proposal cards with Confirm / Cancel), input box, pending indicator while the job runs (polling), "AI not configured" state without a key. English + Arabic, RTL.
- **FR-007 Tests** (fake model client scripted per test): PRD test 26 (chat figures equal the dashboard / employee page / export for the same filters), 27 (resolves name and code; ambiguous name → asks; applies only after Confirm; logs), 29 (report file matches source); a model that claims a change without a tool → the change does not exist; injection text inside an employee name or leave description does not trigger a tool; permission denied tool → reported, nothing leaked; bulk proposal lists all employees; stale proposal refused; unavailable model → message, nothing applied; isolation (other company before any panel render); triggers on append-only tables.

## Out of scope

Real-model verification (needs the key); voice; employee self-service; changes beyond the listed employee fields; AI reading of leave descriptions (511).
