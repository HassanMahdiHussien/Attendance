# Implementation Plan: Server Provisioning, Backups and Monitoring

**Branch**: `013-provisioning` | **Date**: 2026-10-08 | **Spec**: [spec.md](./spec.md)

## Summary
Two halves. **Application side** (PHP, tested in Pest): spatie/laravel-backup configured for an encrypted SFTP
destination, Healthchecks pings for heartbeat/backup/restore drill, a restore-drill command that proves the newest
backup restores, a production-safe command to create the first Platform Admin, schedule entries. **Server side**
(bash in `deploy/`): an idempotent provision script for Ubuntu 24.04, a release-based deploy script, a backup-server
script, config templates and a runbook.

## Technical Context
Laravel 13 · PHP 8.4 · MySQL 8.4 · new packages (approved): `spatie/laravel-backup`, `league/flysystem-sftp-v3` ·
Ubuntu 24.04 LTS · nginx · PHP-FPM 8.4 (ondrej/php PPA — Ubuntu 24.04 ships 8.3) · MySQL 8.4 LTS (official MySQL APT
repository — Ubuntu ships 8.0; dev and triggers are on 8.4) · Node 24 (NodeSource, matches dev) · Chrome for Testing
headless shell (as in `docker/8.4/Dockerfile`) · poppler-utils, tesseract-ocr(-ara) · supervisor · certbot · ufw ·
fail2ban · unattended-upgrades · cryptsetup (LUKS2).

## Research (decisions)

### Application side
- **R1 Backup config** (`config/backup.php`, published and trimmed): name `displanery`; sources = the database
  connection + `storage/app/private`, excluding `legal-library/previews`, `report-exports` and other regenerable or
  expiring folders, plus `livewire-tmp`; destination disks from `BACKUP_DISKS` (comma list, default `local` for dev;
  production `backup-sftp`); archive password `BACKUP_ARCHIVE_PASSWORD`, encryption AES-256 — **refuse to run a
  backup when the password is empty outside local/testing** (a plain archive must never leave the server); mysqldump
  with single transaction, `--no-tablespaces`, `--routines`, triggers included (default) and gzip compression;
  retention: all 7 days, daily 30 days, weekly 8 weeks, monthly 6 months, max 20 GB; monitor: newest backup ≤ 1 day
  (+ grace via schedule time), storage ≤ 20 GB; notifications by mail to `BACKUP_NOTIFY_EMAIL` (failures and unhealthy
  only — no success mail).
- **R2 SFTP disk** `backup-sftp` in `config/filesystems.php`: host, port, username, private key path, root, **pinned
  host fingerprint** (`BACKUP_SFTP_FINGERPRINT`, required when the disk is used — no trust-on-first-use), timeout 30 s.
- **R3 Health pings**: `App\Support\HealthPing::success(string $check)` / `failure(string $check, string $reason)`;
  URLs from `config('displanery.healthchecks.<check>')` (`HEALTHCHECK_HEARTBEAT_URL`, `_BACKUP_URL`,
  `_RESTORE_DRILL_URL`); empty → no request; HTTP timeout 5 s, one retry, any exception caught and logged at warning
  without the URL (it is a secret token). Failure pings post to `<url>/fail` with a short reason body (no personal
  data). Heartbeat: `HeartbeatJob` pings (proves cron **and** the queue worker). Backup: listeners on
  `BackupWasSuccessful` → success and `BackupHasFailed` → failure.
- **R4 Restore drill** `php artisan backup:restore-drill` (scheduled monthly, 1st day 03:30, and runnable on demand):
  1. newest backup on the first configured destination (spatie `BackupDestination`) — none → fail;
  2. download to a private temp dir (0700), open with `ZipArchive` + the archive password; also assert it **cannot**
     be read without the password when a password is configured;
  3. scratch database `<db>_restore_drill` (configurable; refuse if it equals the live database name); drop if exists,
     create, import the dump with the `mysql` client using a temporary `--defaults-extra-file` (0600) for credentials
     — never on the command line;
  4. checks: every live table exists in the drill database; for every table not in the volatile list (`sessions`,
     `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`) the drill row count is reported next to the live
     count; append-only tables (`case_events`, `audit_log`, `ai_generation_logs`) must contain every live row id
     created at least 10 minutes before the backup's timestamp (compare max id and count below that id); every
     `documents` file path / attachment path stored in the drill database whose row was created ≥ 10 minutes before
     the backup exists in the archive's files;
  5. always clean up (drop the scratch database, delete temp files) in `finally`;
  6. print a summary table; success → ping `restore_drill`; failure → failure ping, error exit code, and a mail to
     `BACKUP_NOTIFY_EMAIL`. Logged to the application log (no data values).
- **R5 Schedule** (Asia/Baghdad, `->when(config('displanery.backups.enabled'))`, `BACKUPS_ENABLED` false by default
  so dev machines do not back up nightly): `backup:clean` 01:00, `backup:run` 01:30, `backup:monitor` 09:00, restore
  drill monthly. `withoutOverlapping()` on each.
- **R6 First admin**: `php artisan app:create-platform-admin` — asks name, e-mail and password (hidden, confirmed,
  ≥ 12 chars); refuses if any Platform Admin exists unless `--force-additional`; audit entry without the password.
  Production seeding is `LawsSeeder` only (documented); demo seeders refuse to run when `app()->isProduction()`.
- **R7 Production hardening in config, env-driven only**: `SESSION_SECURE_COOKIE`, `SESSION_ENCRYPT`, trusted proxy
  not needed (nginx on the same host), `LOG_STACK=daily` with 14-day retention. No code changes beyond env defaults.

### Server side (`deploy/`)
- **R8 Layout**: `/srv/displanery/{releases/<UTC timestamp>-<sha>, current -> releases/…, shared/.env, repo}`;
  encrypted volume mounted at `/srv/data` holding `mysql/` (datadir) and `storage/` (Laravel storage, symlinked into
  every release). Deploy user `deploy` owns the app; nginx (`www-data`) reads only `public/`; PHP-FPM pool, queue
  worker and scheduler run as `deploy`.
- **R9 Settings**: `deploy/server.env.example` → copied to `/root/displanery-server.env` (never in git): `DOMAIN`,
  `ADMIN_EMAIL` (Let's Encrypt + alerts), `DATA_DEVICE` (e.g. `/dev/disk/by-id/scsi-0HC_Volume_…`),
  `CONFIRM_FORMAT_DEVICE` (must equal `DATA_DEVICE` to allow formatting an unformatted device — the script never
  formats a device that already has a filesystem or LUKS header), `REPO_URL` (SSH URL), `TLS_MODE`
  (`letsencrypt` | `self-signed` — self-signed for smoke tests and before DNS propagates), `SSH_PORT` (default 22),
  `SWAP_SIZE`.
- **R10 Provision steps** (each idempotent, guarded by checks, `set -Eeuo pipefail`, logs to
  `/var/log/displanery-provision.log`): validate settings → apt base + timezone UTC system / app timezone via `.env` →
  deploy user (sudo only for `supervisorctl`/`systemctl reload php8.4-fpm` via a sudoers drop-in) → SSH hardening
  (drop-in `sshd_config.d/`: no password auth, no root password login (`prohibit-password`), refuse to apply if the
  deploy user or root has no authorized key) → ufw (SSH, 80, 443) → fail2ban (sshd jail) → unattended-upgrades
  (security origins, auto reboot 04:00 Asia/Baghdad = 01:00 UTC) → swap → LUKS volume: key file
  `/root/.luks/displanery.key` (0400, 4096 random bytes) created once, `luksFormat` only per R9 guard, `crypttab` +
  `fstab` (`/srv/data`, ext4, `nofail` **not** used), systemd drop-ins `RequiresMountsFor=/srv/data` for mysql,
  php8.4-fpm, supervisor → MySQL 8.4 from the MySQL APT repo (pinned signing key), datadir `/srv/data/mysql`
  (initialised once), `bind-address=127.0.0.1`, `log_bin_trust_function_creators=1`, buffer pool 1 GB, app user +
  password generated once (stored `/root/.displanery/secrets`, 0600), grants on `displanery` and
  `displanery_restore_drill` → PHP 8.4 + extensions matching the dev image, FPM pool as `deploy`, upload/post 60 MB,
  `expose_php=Off`, opcache → nginx site (HTTP→HTTPS, HSTS, nosniff, frame SAMEORIGIN, referrer policy,
  `client_max_body_size 60m`, deny dotfiles, PHP only via `index.php`) → certbot (webroot, renew timer, reload hook) or
  self-signed → Node 24, Chrome headless shell, fonts (`fonts-noto-core fonts-hosny-amiri fonts-dejavu-core`),
  poppler, Tesseract Arabic, `mysql-client`, composer → shared `.env` written once from
  `deploy/templates/env.production` with generated `APP_KEY`, DB password, `BACKUP_ARCHIVE_PASSWORD`; placeholders for
  mail, SFTP, Healthchecks and `ANTHROPIC_API_KEY` → backup SSH key for `deploy` (`~/.ssh/backup_ed25519`) and a
  GitHub read-only deploy key (`~/.ssh/github_deploy`) created once; public halves printed at the end → supervisor
  program `displanery-worker` (`queue:work database --sleep=3 --tries=3 --timeout=900 --max-time=3600`; queue
  `retry_after` 960 must stay above it) → cron `schedule:run` for `deploy` → final summary: what the developer must do
  next (add deploy key to GitHub, add backup key to the backup server, copy secrets file off the server into a
  password manager — **without `BACKUP_ARCHIVE_PASSWORD` the backups are useless**, fill `.env`, run deploy).
- **R11 Deploy** (`deploy/deploy.sh [ref]`, as `deploy`): fetch into a bare mirror, export the ref into a new release
  dir; link `.env` and `storage`; `composer install --no-dev --classmap-authoritative --no-interaction`; `npm ci` +
  `npm run build` (puppeteer stays: Browsershot needs it; `PUPPETEER_SKIP_DOWNLOAD=1`, Chrome comes from the system
  path); `php artisan storage:link` is not used (private files only); then `artisan down --retry=15` on the current
  release → `migrate --force` with the new release → `optimize` + `filament:optimize` → symlink swap (atomic `mv -T`)
  → reload PHP-FPM → `queue:restart` → `up`. Any failure before the swap: delete the new release, current keeps
  serving. Migration failure: `up` on the old release and exit non-zero with the restore runbook pointer. Keep the 5
  newest releases. `deploy/rollback.sh` switches `current` to the previous release (no migrations rolled back; says
  so).
- **R12 Backup server** (`deploy/backup-server.sh`, as root on the second VPS): user `displanery-backup`, chroot
  `/srv/backups` (root-owned) with writable `/srv/backups/displanery`, `Match User` → `ForceCommand internal-sftp`,
  no forwarding/TTY, key only; ufw SSH only; fail2ban; unattended-upgrades; prints the host key fingerprint in the
  form the SFTP disk expects (R2).
- **R13 Runbook** `deploy/README.md`: accounts, sizing (CX23 + 20 GB volume; backup VPS at a different provider),
  DNS, first provision, secrets custody, first deploy, `LawsSeeder` + `app:create-platform-admin`, UptimeRobot (HTTPS
  `/up`, 5 min), Healthchecks checks (heartbeat 5 min period / 10 min grace; backup 1 day / 2 h; restore drill 31
  days / 1 day), restore procedure (full server loss), rollback, updating, known limits (LUKS auto-unlock protects
  detached disks/snapshots, not a running-server compromise; AI calls leave the server — CLAUDE.md §20).
- **R14 Verification**: shellcheck (docker image `koalaman/shellcheck:stable`) on every script; a smoke run of
  provision (`TLS_MODE=self-signed`, loop-device volume) + deploy in a privileged systemd Ubuntu 24.04 container where
  feasible, container-incompatible steps listed; local end-to-end backup → SFTP container (`atmoz/sftp`) → restore
  drill.

## Constitution Check
| Principle | Status | How |
|---|---|---|
| I. Tenancy | PASS | no query changes; restore drill reads raw tables only in a scratch database, outside tenant flows |
| II. AI boundary | PASS | no AI |
| III. Immutable / append-only | PASS | backups include triggers; restore drill verifies append-only tables are complete |
| IV. Tests | PASS | Pest for backup config, pings, drill, admin command; shellcheck + smoke runs for scripts |
| V. Thin UI | PASS | no UI |
| VI. Decided | PASS | runtime, backup target/tool, monitoring, encryption chosen by product owner |

## Structure
```text
config/backup.php · config/filesystems.php (backup-sftp) · config/displanery.php (healthchecks, backups)
app/Support/HealthPing.php · app/Listeners/{PingBackupSucceeded,PingBackupFailed}.php (or closures in a provider)
app/Console/Commands/{BackupRestoreDrill,CreatePlatformAdmin}.php · app/Jobs/HeartbeatJob.php · routes/console.php
database/seeders/* (production guard) · .env.example · tests/Feature/Operations/*.php
deploy/{provision.sh, deploy.sh, rollback.sh, backup-server.sh, server.env.example, README.md, lib/*.sh}
deploy/templates/{nginx-site.conf, php-fpm-pool.conf, php.ini, mysql.cnf, supervisor-worker.conf, env.production,
  sshd-hardening.conf, fail2ban-jail.local, unattended-upgrades.conf, sudoers-deploy}
```

## Delegation
Codex plan review → run V (application side) → my gates + local SFTP end-to-end → run W (server side) → shellcheck
+ container smoke run → PR.

## R15 Plan review resolutions (Codex review, 2026-10-08) — override R1–R14 where they differ

### Blockers
- **A1 Isolated drill account**: the drill uses its own connection `restore_drill` (`DB_DRILL_USERNAME`,
  `DB_DRILL_PASSWORD`, `DB_DRILL_DATABASE`), a MySQL user that has privileges **only** on the scratch database
  (provision grants `ALL ON displanery_restore_drill.*` and nothing else). The database name must match
  `/^[a-z0-9_]+_restore_drill$/` and differ from the live one. Preflight: `SHOW GRANTS` of the drill account must not
  mention the live database or `*.*` — enforced when `app()->isProduction()` (tests run as the shared `testing%` user
  and skip it, said in WEIRDNESS). The drill creates the database plus a marker table `_restore_drill_marker`; at
  start an existing scratch database is dropped only if it holds that marker, otherwise the drill refuses. Provision
  self-checks that the drill account is denied on the live database.
- **A2 Checks against the restored data itself, not timestamps** (`audit_logs` is the real table name):
  (a) every live table exists in the restored database and the restored `migrations` rows are a prefix of live's;
  (b) the triggers present on live exist in the restored database (by name) and a forbidden `UPDATE`/`DELETE` on one
  append-only table and on an issued document row is rejected there; (c) append-only tables (`case_events`,
  `audit_logs`, `ai_generation_logs`): with M = max restored id, the live count of rows with id ≤ M equals the
  restored count (append-only ⇒ exact); (d) **files**: every durable file path referenced by the restored database —
  document PDFs, case attachments/scans, legal law source files and page images, letterhead/logo images,
  internal-regulation uploads (Codex lists the columns from the migrations) — exists in the archive and matches the
  stored sha256 where a hash column exists; (e) other tables: counts reported for information only. DB-first ordering
  is guaranteed by spatie (`BackupJob::createBackupManifest` dumps databases before listing files) and the app writes
  files before the row that references them, so (d) holds for any consistent backup; the drill catches violations.
- **A3 Dump/restore privileges and DEFINER**: the backup dumps with the app account (it has `ALL` on the live
  database, which includes `TRIGGER`, `SHOW VIEW`, `LOCK TABLES`); dump options `--single-transaction
  --no-tablespaces --routines --triggers --set-gtid-purged=OFF`. The drill streams the dump through a filter that
  removes `DEFINER=…` clauses before import, so restored triggers belong to the importing account;
  `log_bin_trust_function_creators=1` stays in `mysql.cnf`. The runbook's full-server restore imports as the app
  account with the same filter. (b) proves the triggers work after restore.
- **A4 Migrations are not reversible by switching code**: deploy checks `migrate:status --pending`. No pending
  migrations → no maintenance window: switch, reload, `queue:restart`. Pending migrations → `down`, `supervisorctl
  stop displanery-worker` (supervisor `stopwaitsecs=960` lets the running job finish), migrate with the new release,
  switch, reload, start worker, `up`. A failed migration leaves the site **in maintenance** with a message: fix forward
  or restore from backup (runbook); it never reopens old code against a changed schema. Migrations must be
  expand-then-contract (runbook + WEIRDNESS). `rollback.sh` refuses when the database has migrations the previous
  release does not know, unless `--force` with a warning.
- **A5 Format guard**: formatting requires all of: `DATA_DEVICE` given as a `/dev/disk/by-id/` path; the resolved
  device is `TYPE=disk` with no partitions/children, not mounted, not swap, not in the root filesystem's device chain,
  no signature (`wipefs -n` empty, no LUKS header); `CONFIRM_FORMAT_DEVICE` equals the by-id path **and**
  `CONFIRM_FORMAT_SERIAL` equals `lsblk -dno SERIAL` (the script prints size/model/serial). Any check that cannot be
  evaluated → stop. An existing LUKS device our key opens → reuse; one it cannot open → stop.

### Should-fix
- **B1** consistency: covered by A2. Deploy refuses to migrate while the backup's schedule mutex is held; backups run
  at 01:00.
- **B2** `encryption => 'aes256'` explicitly; outside local/testing the backup refuses a password shorter than 32
  characters; tests prove a real entry cannot be extracted without the password and can with it. Runbook: entry names
  inside the zip are not secret (storage paths, no names of people); `APP_KEY` and the archive password are both kept
  off-server (password manager).
- **B3** honest boundary in WEIRDNESS/runbook: the LUKS key and `.env` are on the root disk, so the protection is for
  the data volume obtained without the root disk (detached/recycled volume, volume snapshot). Swap file **on the
  encrypted volume**; MySQL `tmpdir`, PHP `upload_tmp_dir`, Laravel/backup temp dirs on the volume (`/srv/data/tmp`).
- **B4** MySQL data via a **bind mount** `/srv/data/mysql → /var/lib/mysql` (fstab,
  `x-systemd.requires-mounts-for=/srv/data`) instead of moving `datadir` — no AppArmor or config change; created
  before MySQL is installed so data lives on the volume from the first start. Drop-ins `RequiresMountsFor=` for mysql,
  php8.4-fpm, supervisor; the scheduler cron line is wrapped in `mountpoint -q /srv/data &&`. Missing volume → these
  fail closed. The smoke run boots once without the volume to show it.
- **B5** deploy uses `trap` before the switch; after the switch a health check (`curl --resolve $DOMAIN:443:127.0.0.1
  https://$DOMAIN/up`); failure keeps maintenance on and prints the recovery procedure.
- **B6** extra Healthchecks checks: `deadline_alerts` (success after each hourly `cases:send-deadline-alerts`, failure
  ping on exception) and `queue_failures` (hourly `ops:check-failures`: success when no job failed in the last hour,
  failure ping with the count and job class names otherwise — never payloads). Mail: runbook requires an SMTP
  provider with SPF/DKIM/DMARC and `php artisan ops:test-mail <address>` sends a plain test message.
- **B7** one rule: Healthchecks backup period 24 h + grace 2 h (alert at 26 h); `backup:monitor` hourly with
  `maximum_age_in_days = 1` as the second line (mail). Spec updated.
- **B8** application log files `daily`, 30 days (not the legal record — `audit_logs`/`case_events` live in the
  database and in every backup). Time: `systemd-timesyncd` enabled; provision fails if `NTPSynchronized` is not `yes`
  within 60 s. **Deletion protection**: the backup server keeps a root-owned `vault` the SFTP user cannot reach; a root
  cron copies each new archive there and prunes by its own rule (daily 35 days, monthly 24 months — default, set in
  the backup-server settings). The production credential can delete only its working copies.
- **Nits**: spec story 1 says provision **plus** first deploy; runbook has an exact preflight + first-deploy sequence;
  `backup:run` 01:00 then `backup:clean` 02:30.
- **Product-owner questions (defaults taken, reported)**: recovery point = last nightly backup (≤ 24 h of lost work);
  recovery time = same day (rebuild by script + restore).
