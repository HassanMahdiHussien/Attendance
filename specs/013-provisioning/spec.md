# Feature Specification: Server Provisioning, Backups and Monitoring

**Feature Branch**: `013-provisioning`

**Created**: 2026-10-08

**Status**: Draft

**Input**: CLAUDE.md §21 (plain VPS set up by script, no panel), §10c (encryption in transit and at rest, auth
hardening, uptime + backup-failure + business alerts), §20 (queue worker, scheduler, Node + Chromium), ADR 0006
(private VPS, encrypted backup on a different provider), LAUNCH-CHECKLIST (backups verified restorable, monitoring).

## Clarifications

### Session 2026-10-08

- Q: How does the app run in production? → A: Native Ubuntu 24.04 — nginx, PHP-FPM 8.4, MySQL 8.4, Node, headless
  Chrome, poppler/Tesseract, supervisor for the queue worker, cron for the scheduler. No Docker in production.
- Q: Backup destination? → A: A second small VPS at a different provider, reached over SFTP with a key.
- Q: Backup tool? → A: spatie/laravel-backup + league/flysystem-sftp-v3 (approved): database + private files, archive
  encrypted with AES-256, old backups cleaned, failure mail.
- Q: Monitoring? → A: UptimeRobot checks the website; Healthchecks.io receives pings from the scheduler/queue heartbeat,
  every successful backup and every restore drill, and alerts when a ping stops arriving.
- Q: Encryption at rest? → A: Database and files on a separate LUKS-encrypted volume unlocked automatically at boot
  with a key file on the server (protects detached/recycled disks and leaked snapshots; documented limit: not a running
  server compromise).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Build the production server from scratch with one command (Priority: P1)

The developer rents an Ubuntu 24.04 VPS and a volume, adds an SSH key and points DNS at it, fills a small settings
file, and runs one script as root. The script installs and configures everything; running it again changes nothing
that is already right. The developer then runs the deploy script to put the application live over HTTPS.

**Acceptance Scenarios**:
1. **Given** a fresh server with the settings file filled, **When** the provision script runs, **Then** the site
   answers over HTTPS with a valid certificate, HTTP redirects to HTTPS, the queue worker and the scheduler run, and the
   health URL reports OK.
2. **Given** an already provisioned server, **When** the script runs again, **Then** it finishes without errors and
   without overwriting secrets (database password, app key, LUKS key, `.env`).
3. **Given** a missing or invalid setting (domain, volume device, admin e-mail), **Then** the script stops before
   changing anything and names the problem.

### User Story 2 - Deploy a new version safely (Priority: P1)

The developer runs the deploy script (on the server, as the deploy user) for a git tag or `main`. It installs
dependencies, builds assets, runs migrations, refreshes caches and restarts the worker. A failure leaves the previous
version serving.

**Acceptance Scenarios**:
1. **Given** a new commit on `main`, **When** deploy runs, **Then** the new release is live, migrations ran once, and
   queued jobs continue on the new code.
2. **Given** a failing `composer install` or build, **Then** the live site keeps serving the previous release.
3. **Given** a failing migration, **Then** deploy stops, the site leaves maintenance mode on the previous release, and
   the developer is told to restore or fix forward (no automatic down-migrations).

### User Story 3 - Encrypted daily backup to another provider, proven restorable (Priority: P1)

Every night the database and the private files are archived, encrypted, and sent to the backup server. Old backups
are cleaned by a retention rule. Once a month (and on demand) a restore drill downloads the newest backup, decrypts
it, loads it into a scratch database and compares it with the live data; success and failure are reported.

**Acceptance Scenarios**:
1. **Given** a normal night, **Then** a new encrypted archive exists on the backup server, it cannot be opened without
   the backup password, and a success ping reaches Healthchecks.
2. **Given** the backup server is unreachable, **Then** the backup fails, a failure e-mail goes to the developer, and
   no success ping is sent (Healthchecks alerts after its grace period).
3. **Given** the restore drill, **Then** it reports table row counts and file counts matching the live system at the
   time of the backup (within rows created since), drops the scratch database, and pings Healthchecks only on success.
4. **Given** a backup older than 26 hours as the newest one, **Then** the daily backup monitor reports it unhealthy.

### User Story 4 - Know when something is down (Priority: P1)

**Acceptance Scenarios**:
1. **Given** the scheduler or the queue worker stops, **Then** the heartbeat ping stops and Healthchecks alerts the
   developer within its grace period.
2. **Given** the website is down, **Then** UptimeRobot alerts the developer.
3. **Given** a case within 48 hours of its 15-day deadline without a decision, **Then** the existing deadline alert
   (feature 003) is sent even if nobody opens the dashboard — this feature only ensures the scheduler and mail that
   carry it are running and monitored.

### Edge Cases

- A ping URL is not configured (local, tests): nothing is sent and nothing fails.
- Healthchecks is unreachable: the ping fails quietly (short timeout); the backup or job itself is not failed by it.
- Automatic security updates need a reboot: reboot at 04:00 Baghdad time; the encrypted volume unlocks and services
  start in order (MySQL after the volume is mounted).
- The volume is not mounted at boot: MySQL and the app do not start against an empty directory.
- Disk almost full on the server or the backup server: the backup monitor flags the storage limit.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: One idempotent provisioning script in the repo builds the production server from a fresh Ubuntu 24.04
  install, driven by a settings file that holds no secrets in git.
- **FR-002**: Hardening: key-only SSH, no root password login, firewall allowing only SSH/HTTP/HTTPS, fail2ban,
  automatic security updates with a scheduled reboot window, a non-root deploy user.
- **FR-003**: HTTPS everywhere with automatic certificate renewal; HTTP redirects to HTTPS; security headers.
- **FR-004**: The MySQL data directory and the application's private file storage live on a LUKS-encrypted volume
  unlocked at boot by a key file; services depend on the mount.
- **FR-005**: The queue worker runs under a supervisor (restarted on failure and after deploy); the scheduler runs from
  one cron entry.
- **FR-006**: A deploy script releases a git ref atomically (new release directory, shared `.env` and storage, symlink
  switch) and keeps the previous releases for manual rollback.
- **FR-007**: Nightly encrypted backup (database + private files) to the SFTP backup server, with retention and a
  daily health monitor; failure e-mail to a configured address.
- **FR-008**: A restore drill command verifies the newest backup end to end without touching the live database; it
  runs monthly from the scheduler and on demand.
- **FR-009**: Heartbeat, backup and restore-drill success are pinged to configurable Healthchecks URLs; an unset URL
  disables the ping.
- **FR-010**: A script prepares the backup server: an SFTP-only, chrooted user with key login, firewall, nothing else.
- **FR-011**: A runbook documents the developer's steps (accounts, DNS, keys, settings, first deploy, UptimeRobot and
  Healthchecks setup, restore procedure, rollback).

## Success Criteria *(mandatory)*

- **SC-001**: A fresh server reaches a working HTTPS login page with one provision run plus one deploy run.
- **SC-002**: The restore drill succeeds against a real backup (proven locally against an SFTP container).
- **SC-003**: Stopping the scheduler, the worker or the backup produces an alert without anyone opening the app.

## Assumptions

- The developer creates the provider accounts, pays, adds the SSH key and sets DNS (CLAUDE.md §21); nothing in this
  feature touches a real server until the developer asks.
- Mail (SMTP) credentials for alerts are supplied by the developer in the server `.env`.
- Single server; no load balancer, no Redis (database queue per CLAUDE.md §20).
