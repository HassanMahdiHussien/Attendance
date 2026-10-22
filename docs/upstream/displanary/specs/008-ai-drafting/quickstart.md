# Quickstart: AI Drafting

1. Gates (as sail): `php artisan test`, `./vendor/bin/pint --test`.
2. Without a key: open a case → "صياغة بالذكاء الاصطناعي" → the case returns to open with "AI unavailable — write
   it manually or try again"; the manual editor works; nothing is saved.
3. Local demo without a key: `php artisan ai:demo-fake --on` binds the fake client for the local environment only
   (refused in production) so the screens can be walked: draft appears in Arabic + English with markers rendered,
   notes panel, relevance badges on the confirmation screen, override with reason.
4. With `ANTHROPIC_API_KEY`: one real defense-letter draft for a demo case; check `ai_generation_logs` (input has
   no personal identifiers), the draft wording, the relevance results.
5. Recommendations: record a session with minutes and outcome "warning" → AI draft recommends a warning only.
6. Decision letter: after the signed scan → AI draft of the reasons; leave confirmation still required for
   termination.
