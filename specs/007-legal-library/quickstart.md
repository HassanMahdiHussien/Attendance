# Quickstart: Legal Library Import

Prerequisites: image rebuilt with poppler-utils + tesseract (Arabic); `composer install` with
`anthropic-ai/sdk`; queue worker running (`docker compose up -d` starts `queue`).

1. Gates (as sail): `php artisan test`, `./vendor/bin/pint --test`.
2. Tool check: `docker compose exec -T laravel.test sh -c 'pdftotext -v; tesseract --list-langs | grep ara'`.
3. Without an AI key: Platform Admin → Laws → Labor Law No. 71 of 1987 → Import (mode auto). Expect the run
   to finish the first reading, state "AI reading pending", zero verified units, a clear message.
4. With `ANTHROPIC_API_KEY` set: "Retry AI reading" → units appear; some verified automatically, the rest
   in the mismatch queue. Open the queue: both readings + page image; resolve one → citable.
5. Correct a verified unit → version 2; version 1 still listed in history.
6. Company admin uploads a small `.docx` regulation → units "verified from text source", not citable;
   reviewer approves → citable for that company only.
7. Law 37/2015 import uses OCR (mode ocr); Law 18/2023 import with the page range from the gazette.
