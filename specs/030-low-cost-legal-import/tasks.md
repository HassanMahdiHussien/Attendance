# Tasks: Low-Cost Legal Library Import

- [ ] T001 Config: reading model default Sonnet 5.5 + reading effort (low) used by ClaudePageReader.
- [ ] T002 Migration + models: provenance columns (ai_source, reading_mode, batch_id, transcription_*, secondary_source_*).
- [ ] T003 Batch reading: BatchPageReader, PollLegalReadingBatches scheduled job, result mapping, logging, cancel.
- [ ] T004 Transcription upload: validation, fills reading A with provenance, audit.
- [ ] T005 Secondary text: extraction, WordLigatureRepair, UnitBuilder article-level path, no API reading when present.
- [ ] T006 Library export/import commands, manifest with hashes, LawsSeeder integration.
- [ ] T007 Platform UI: reading mode choice, upload actions, source badges, batch state; translations.
- [ ] T008 Tests (plan), behaviour inventory, Pint, full suite.
