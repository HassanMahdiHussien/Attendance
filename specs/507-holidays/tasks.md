# Tasks: Official holidays

**Input**: [spec.md](spec.md), [plan.md](plan.md) — "Revisions after Codex plan review" overrides earlier plan text. Each step ends green in WSL (Unit, Feature, Browser, Pint).

## Step 1 — Holidays without AI
- [x] T001 Migrations: holiday sources (immutable file fields), holidays (nullable current version), versions (append-only, unique version), day `holiday_ids`; composite FKs; FK-safe down()
- [x] T002 `Holidays` service (manual create, approve, edit, withdraw, reject; atomic versions; audit) and `HolidayCalendar` (scope semantics revision 4, historical values)
- [x] T003 Assessor: holiday / holiday_worked / rest_day_worked (revision 2); leave counting methods and distribution with holidays (revision 3); reprocessing chain (revision 1)
- [x] T004 Reporting, filters, drill-downs, exports, labels, employee view, review queue (revision 6); translations
- [x] T005 Tests: PRD §25 25 (manual path), Al-Waha vs Sardkar scope, holiday worked, rest day worked, night shift on a holiday, leave skipping + counted_holiday, edit/withdraw reprocessing with differences, overlaps, isolation, triggers

## Step 2 — AI reading
- [x] T006 SDK probe test (v0.55 beta create with document block + outputConfig json_schema + fallbacks) against a fake transport; `.eml`/`.msg` parser probes with fixtures
- [x] T007 `HolidayReader` (prompt, schema, validation), `ReadHolidaySourceJob`, sources upload, limits, hash reuse, daily cap, logging (revision 7/8)
- [x] T008 Tests: refusal, invalid JSON, unavailable key, cap, hash reuse, prompt-injection text, logging without content

## Step 3 — Screens
- [x] T009 Holidays list, upload, review with all source parts beside the form, history; routes + downloads; UI inventory, route sweep, translations; Livewire + browser tests

## Step 4 — Verification (orchestrator)
- [x] T010 Sovereignty Day 2026 on the smoke DB (manual approval for Al-Waha; AI reading if a key is configured), reassess, report; docs; PR
