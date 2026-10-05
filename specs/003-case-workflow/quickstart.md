# Quickstart & Validation: Feature 003

| # | Scenario | Command | Expected |
|---|---|---|---|
| V1 | Gates | `docker compose exec -T laravel.test php artisan test` · `… ./vendor/bin/pint --test` | green |
| V2 | End-to-end | `… php artisan test --filter=EndToEndTest` | warning path and no-penalty path complete with full history |
| V3 | Alerts once | `… php artisan cases:send-deadline-alerts` twice | second run sends 0; `case_alerts` rows unique |
| V4 | Scheduler | `docker compose logs scheduler` | alert + archive commands scheduled hourly |
| V5 | Mail | `storage/logs/laravel.log` (log mailer) | alert mail rendered in Arabic |
