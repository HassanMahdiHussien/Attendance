# Tasks: Administrative AI chat

**Input**: [spec.md](spec.md), [plan.md](plan.md) — any "Revisions after Codex plan review" section overrides earlier plan text. Each step ends green in WSL (Unit, Feature, Browser, Pint).

## Step 1 — Loop and read tools
- [ ] T001 Tables (conversations, messages, proposals), models, composite FKs, triggers
- [ ] T002 `ChatModel` interface, `FakeChatModel`, `ClaudeChatModel` (SDK calls verified against vendor source), `ChatTools` read + report tools, `ChatTurn` + `RunChatTurnJob`
- [ ] T003 Tests: PRD 26 and 29, claim without tool, injection in employee text, permission denied, model unavailable / refusal / truncation, step limit, isolation, triggers

## Step 2 — Confirmed changes
- [ ] T004 `ChatProposals` (propose / confirm / cancel / stale), `propose_employee_change` + `proposal_status` tools, audit
- [ ] T005 Tests: PRD 27 (resolve, ambiguity asks, apply only on Confirm, log), bulk proposal, stale refused, reversal proposal, end time alone changes nothing else

## Step 3 — Screen
- [ ] T006 Assistant page + component (conversations, messages, report links, proposal cards, polling, unconfigured state), translations, UI inventory, route sweep, Livewire + browser tests

## Step 4 — Verification (orchestrator)
- [ ] T007 Smoke-DB run with the fake model, docs, PR
