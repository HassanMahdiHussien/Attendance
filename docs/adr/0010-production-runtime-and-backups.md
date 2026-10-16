# 0010 — Native production runtime and independent encrypted backups

**Status:** Accepted. Operating system and PHP version superseded by ADR 0012 (Ubuntu 26.04, PHP 8.5).

## Context

The production application needs PHP-FPM, MySQL 8.4 triggers, a supervised database queue worker, the Laravel scheduler, Node and headless Chrome for PDFs. Uploaded legal evidence must stay on a private VPS as decided in ADR 0006. A single VPS can fail or have its backup credential stolen.

## Decision

Run the application natively on Ubuntu 24.04 with nginx, PHP-FPM 8.4, MySQL 8.4, supervisor and cron. Use a separate LUKS2 data volume with automatic key-file unlock and mount dependencies. Back up the database and private files nightly with spatie/laravel-backup, AES-256 archive encryption and SFTP to a VPS at another provider. Keep a root-owned vault copy beyond the SFTP user's reach. Verify restoration with a dedicated scratch MySQL account and monthly drill. Use Healthchecks.io for scheduled work and UptimeRobot for HTTPS reachability.

## Consequences

The deployment scripts can rebuild the native stack without Docker or a hosting panel. Independent vault copies limit damage from a compromised production SFTP credential. The operator must retain the archive password, APP_KEY and LUKS key off-server and test restoration. LUKS auto-unlock protects a separated data volume, not a running server or both disks together. Healthchecks and UptimeRobot are external monitors and require account setup. AI requests still send selected case text outside the VPS, as described in CLAUDE.md §20.
