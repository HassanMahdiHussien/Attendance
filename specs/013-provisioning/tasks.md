# Tasks: Server Provisioning, Backups and Monitoring

**Tests**: REQUIRED. Gates (as sail): `docker compose exec -T -u sail laravel.test php artisan test` ·
`… ./vendor/bin/pint --test` · shellcheck on `deploy/**/*.sh`. Never run two test processes at once.

plan.md review resolutions (when added) override earlier sections.

## Run V — application side
- [x] T001 Install `spatie/laravel-backup` + `league/flysystem-sftp-v3` (orchestrator runs composer); publish and trim
  `config/backup.php` per R1; `backup-sftp` disk per R2; `.env.example` keys (empty values)
- [x] T002 `HealthPing` + heartbeat/backup/deadline-alert pings, `ops:check-failures`, `ops:test-mail` (R3, R15 B6)
- [x] T003 `backup:restore-drill` command (R4 as amended by R15 A1–A3) + schedule entries (R5, R15 B7/nits)
- [x] T004 `app:create-platform-admin` + production guard on demo seeders (R6)
- [x] T005 Tests: backup refuses without password outside local/testing; archive is encrypted (cannot be opened
  without the password); pings (unset URL → no request; timeout/500 swallowed; URL never logged; heartbeat job pings;
  backup success/failure events ping); restore drill end to end against a real `backup:run` on a local disk
  (success; missing table → fail; missing or altered referenced file → fail; append-only rows missing → fail;
  restored triggers present and rejecting updates; DEFINER stripped; wrong password → fail; refuses a database name
  equal to the live one or not ending `_restore_drill`; refuses an existing scratch database without the marker;
  scratch database always dropped); `ops:check-failures` pings; schedule registered only when backups enabled;
  create-platform-admin (creates, refuses second without flag, password rules, audit has no password); demo seeders
  refuse in production

## Run W — server side
- [ ] T006 `deploy/provision.sh` + `lib/` + templates + `server.env.example` (R8–R10)
- [ ] T007 `deploy/deploy.sh` + `rollback.sh` (R11)
- [ ] T008 `deploy/backup-server.sh` (R12)
- [ ] T009 `deploy/README.md` runbook (R13); WEIRDNESS lines (LUKS auto-unlock limit; backups refuse without
  password; restore drill uses a scratch database); ADR for the production runtime/backups if warranted
- [ ] T010 Verification (R14): shellcheck clean; container smoke run; local SFTP end-to-end backup + restore drill;
  PR
