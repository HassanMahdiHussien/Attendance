# Tasks: Western Digits Everywhere and a Managed Violation-Type List

- [ ] T001 Digits: `Ui::number`/`Ui::date` Western in every locale; remove `ArabicDigits` if unused; grep for any other client/server digit conversion.
- [ ] T002 Digits in documents: `DraftPrompt` instruction; normalise Arabic-Indic digits to Western when AI/human draft text is stored (never on locked documents); tests.
- [ ] T003 Migration + `ViolationType` model/factory + composite FKs on cases and violation_reports; CompositeForeignKeyTest map.
- [ ] T004 `Violation::name()` display helper; replace every `violation_type` display (FR-005 list); search (FR-009).
- [ ] T005 Forms: SubmitReport + CreateCase select with free-text fallback; report → case carries the id.
- [ ] T006 Mapping action on case page + inbox, `violation_type_mapped` event.
- [ ] T007 Filament ViolationTypeResource (company admin), deactivate-not-delete, audit log.
- [ ] T008 Hotspots/ReportBuilder grouping by id; repeat/duplicate checks; AI payload Arabic name.
- [ ] T009 Seeders: starter list + demo mapping.
- [ ] T010 Tests (plan "Tests"), translations, behaviour inventory, Pint, full suite.
