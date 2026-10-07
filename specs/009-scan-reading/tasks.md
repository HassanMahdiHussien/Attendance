# Tasks: AI Reading of Scans

**Tests**: REQUIRED (Pest + Livewire, fake Claude client, no network). Gates (as sail):
`docker compose exec -T -u sail laravel.test php artisan test` · `… ./vendor/bin/pint --test`.
Never run two test processes at once.

## Run R
- [ ] T001 Migrations per data-model.md (append-only triggers with working down(); composite FKs; register in tenancy test registries); models
- [ ] T002 ClaudeClient content blocks + ScanBlocks (image/PDF base64 blocks per SDK keys, downscale 2000 px, ≤ 20 PDF pages via PdfPages::count, 10 MB) + config `displanery.ai.reading_model`
- [ ] T003 ResponseReading (request with token, confirm, correct with reason) + TranscribeResponseScan job; PayloadBuilder adds the confirmed response (masked) for recommendations; DraftPrompt line
- [ ] T004 SignaturePresence split (uploadSignedScan + recordPresence with suggestion id; recordSignedScan kept as wrapper) + SuggestSignaturePresence job + PresenceWording helper; signatures.ai_suggestion
- [ ] T005 Screens: case page response panel (request, polling, review side by side, confirm, manual entry, correct), committee screens show the labelled text, SignedScan two-step with pre-filled suggestions; Arabic timeline labels for new events
- [ ] T006 Tests (R6) incl. wording scan over views/notifications/responses and authorisation per role
- [ ] T007 docs: ERD, WEIRDNESS (transcription is never used before HR confirms; presence suggestions never claim identity; scans are sent whole because reading needs them), orchestrator walkthrough, PR
