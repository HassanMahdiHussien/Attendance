# Production operations

## Preflight and first deployment

Rent a fresh Ubuntu 26.04 x86_64 application VPS (CX23 class with a 20 GB or larger separate data volume) and a small backup VPS at a different provider. Attach an SSH public key to each, keep console access, and point DNS at the application VPS. Use TLS_MODE=letsencrypt after DNS and ports 80/443 work. TLS_MODE=self-signed is for isolated smoke runs; rerun with Let's Encrypt before real use.

1. Copy the repository's deploy/ folder to the application VPS from your machine (scp -r deploy root@SERVER:/root/displanery-deploy) — the repository is private and the server has no GitHub key yet. Copy deploy/server.env.example to /root/displanery-server.env, fill its values and set mode 0600. DATA_DEVICE must be a stable /dev/disk/by-id path. Inspect lsblk -o NAME,TYPE,SIZE,MODEL,SERIAL,MOUNTPOINTS and wipefs -n on the device. On a blank device, set CONFIRM_FORMAT_DEVICE to the exact by-id path and CONFIRM_FORMAT_SERIAL to the exact lsblk -dno SERIAL value. Formatting is refused for a signature, partition, mount, swap, or root-chain device. An existing LUKS volume is reused only with its key.
2. Run sudo bash deploy/provision.sh. Review /var/log/displanery-provision.log. Add the printed GitHub public key as a read-only deploy key to the repo. Verify the GitHub host fingerprint independently and put its host key in /home/deploy/.ssh/known_hosts. Provisioning records the repo URL in /srv/displanery/repo-url.
3. On the backup VPS, copy deploy/backup-server.env.example to /root/displanery-backup-server.env with mode 0600. Put the printed backup public key in BACKUP_PUBLIC_KEY. Run sudo bash deploy/backup-server.sh. Copy its sha256:<hex> ED25519 fingerprint into BACKUP_SFTP_FINGERPRINT on the application VPS. It is SHA-256 over the raw host public key blob, not OpenSSH's base64 display fingerprint. The SFTP user is displanery-backup and the chroot-relative root is /displanery.
4. Edit /srv/displanery/shared/.env as deploy. Supply BACKUP_SFTP_HOST, fingerprint, SMTP settings (MAIL_SCHEME=smtp for port 587 or smtps for port 465), Healthchecks URLs, and ANTHROPIC_API_KEY. BACKUP_SFTP_PRIVATE_KEY=/home/deploy/.ssh/backup_ed25519 is a file path. Keep BACKUPS_ENABLED=true and DB_DRILL_DATABASE=displanery_restore_drill. Configure SPF, DKIM and DMARC with the SMTP provider. After deployment run php artisan ops:test-mail <address>. Deploys cache the configuration, so after any later .env edit run php artisan config:cache in /srv/displanery/current and sudo supervisorctl restart displanery-worker.
5. As deploy, run bash deploy/deploy.sh main from a checkout. Then run cd /srv/displanery/current, php artisan db:seed --class=LawsSeeder --force and php artisan app:create-platform-admin interactively. Demo seeders are forbidden in production.
6. Check systemctl status mysql php8.5-fpm nginx supervisor, supervisorctl status displanery-worker, the scheduler log in /srv/data/storage/logs, /up over valid HTTPS, and a real login. Run php artisan backup:run, verify an encrypted archive on the backup VPS and root vault after its cron, and run php artisan backup:restore-drill. Review the table/file summary and ping before onboarding real data.

Copy /root/.displanery/secrets, /root/.luks/displanery.key, and shared/.env into an off-server password manager with restricted access. APP_KEY and BACKUP_ARCHIVE_PASSWORD are essential; without the latter backup contents cannot be recovered. Never put them in logs or tickets. Encrypted ZIP entry path names remain visible. Monitor free space on both VPSs.

## Monitoring

Set UptimeRobot to HTTPS https://DOMAIN/up at five-minute intervals. Create these Healthchecks.io checks and copy their URLs into the .env keys:

| Check | Key | Period | Grace |
|---|---|---:|---:|
| Scheduler plus worker heartbeat | HEALTHCHECK_HEARTBEAT_URL | 5 minutes | 10 minutes |
| Encrypted backup | HEALTHCHECK_BACKUP_URL | 24 hours | 2 hours |
| Restore drill | HEALTHCHECK_RESTORE_DRILL_URL | 31 days | 1 day |
| Deadline alerts | HEALTHCHECK_DEADLINE_ALERTS_URL | 1 hour | 15 minutes |
| Failed queued jobs | HEALTHCHECK_QUEUE_FAILURES_URL | 1 hour | 15 minutes |
| Forwarded email intake | HEALTHCHECK_EMAIL_INTAKE_URL | 5 minutes | 10 minutes |

The app schedules a heartbeat dispatch every minute, deadline alerts hourly, backup:run at 01:00 Baghdad, backup:clean at 02:30, backup:monitor hourly, and backup:restore-drill on the first of each month at 03:30. ops:check-failures runs hourly. The backup monitor mails if the newest backup exceeds one day or storage exceeds 20 GB. Test alert recipients and failure notifications. The backup VPS vault copies working archives hourly; defaults retain daily copies for 35 days and monthly copies for 24 months.

## Updating and rollback

Run bash deploy/deploy.sh <tag-or-main> as deploy. It refuses migration while artisan backup:run is active. Without pending migrations it switches without a maintenance window. With pending migrations it puts the site in maintenance, waits up to 960 seconds for the worker, migrates, switches and restarts. A failed migration or post-switch health check leaves maintenance on. Inspect logs and fix forward or restore. Never reopen old code automatically after a migration may have changed the schema.

Migrations must be expand-then-contract: add compatible fields, deploy code that can read both shapes, backfill, then remove fields after no retained release needs them. bash deploy/rollback.sh switches to the previous code without down-migrations. It refuses if the database has migrations unknown to that release. --force bypasses only that guard and requires an explicit schema review. Keep a backup before risky migrations. Five releases are retained.

## Full-server-loss restore

1. Rebuild an Ubuntu 26.04 VPS and encrypted volume. Recover APP_KEY, BACKUP_ARCHIVE_PASSWORD and other secrets from off-server custody into shared/.env. Deploy the matching application ref. Keep the site in maintenance and DNS away from it until checks pass.
2. Retrieve the newest encrypted archive from the backup server working area or root vault. Check timestamp, size and SHA-256. Copy it to a private directory on /srv/data (mode 0700). Decrypt with BACKUP_ARCHIVE_PASSWORD and extract the gzip-compressed SQL dump and private files.
3. Import into an empty displanery database as the application DB account. Filter SQL DEFINER clauses before import, just as backup:restore-drill does. Replace database.sql.gz below with the extracted gzip SQL dump's actual path. The pipeline is: gzip -dc database.sql.gz | perl -pe 's/DEFINER\s*=\s*(?:\x60[^\x60]*\x60|\x27[^\x27]*\x27|[^\s@]+)\s*@\s*(?:\x60[^\x60]*\x60|\x27[^\x27]*\x27|[^\s*]+)\s*//ig' | mysql --defaults-extra-file=/srv/data/tmp/restore-my.cnf displanery . Make restore-my.cnf mode 0600 with client host, user and password, and remove it immediately after import. Never place the password on the command line. Keep log_bin_trust_function_creators=1. Verify imported triggers exist and reject forbidden mutations.
4. Restore files to their original relative paths under /srv/data/storage/app/private and restore repository law files where present. Include document PDFs, scans, law source files and page images, logo/letterhead and regulation uploads. Set ownership to deploy. Run php artisan migrate:status and php artisan backup:restore-drill against a separate fresh backup; verify file hashes, /up, queue, scheduler, SMTP and a real login before clearing maintenance and switching DNS.
5. Record the backup timestamp and result. Target RPO is at most 24 hours of work from the last nightly backup; target RTO is the same day. Both require a verified archive, saved decryption password and working provider access.

The LUKS key and .env are on the root disk for automatic boot. LUKS protects a detached or recycled data volume and volume-only snapshots. It does not protect a running-server compromise or theft of both disks. Swap, MySQL temp, PHP upload temp and backup temp are on the encrypted volume. AI calls send selected case text to Anthropic outside the VPS; do not claim all data stays there.

## Forwarded email intake

Create one operator-controlled IMAP mailbox and set `INTAKE_IMAP_HOST`, `INTAKE_IMAP_PORT`,
`INTAKE_IMAP_ENCRYPTION` (`ssl` or `tls`), `INTAKE_IMAP_USERNAME`, `INTAKE_IMAP_PASSWORD`,
and `INTAKE_IMAP_FOLDER` in the server environment. Keep the credentials with operators only.
The receiving mail host must prepend its own `Authentication-Results` header. Hosts that do not
add that header cannot be used. Send a test message and read its raw headers at the mail host;
set `INTAKE_TRUSTED_AUTHSERV_ID` to the topmost header's authserv-id. Run
`php artisan email-intake:inspect-latest` from `/srv/displanery/current` to check
that topmost id and the DMARC/SPF/DKIM verdict without printing mail content. Send a deliberately
forged message with a lower, same-id `Authentication-Results` header and verify that it is discarded.

Create or allow the app to create `Processed` and `Discarded` folders (override with
`INTAKE_PROCESSED_FOLDER` and `INTAKE_DISCARDED_FOLDER`). Set `INTAKE_ENABLED=true` only after
the test succeeds. The scheduler polls every five minutes. Processed messages are deleted after
`INTAKE_PROCESSED_DAYS` (default 30), discarded messages after `INTAKE_DISCARDED_DAYS` (default 7).
Set `HEALTHCHECK_EMAIL_INTAKE_URL` for the intake ping. A failed ping contains counts only;
inspect the application log for connection, read or move failure categories.

## Demo server (laptop, demo data only)

A spare Ubuntu 26.04 laptop serves `https://demo.hasnmahdi.com` for demonstrations. It holds only the seeded demo
company and accounts, never real employee data, and is not the production server (home power and internet,
no encrypted volume, no off-site backup).

- One-time: the laptop owner runs `sudo bash ~/displanery/deploy/dev-setup.sh` (runtime, nginx, MySQL, systemd
  queue and scheduler) and installs the Cloudflare Tunnel connector from the Zero Trust dashboard; the tunnel's
  public hostname points at `http://localhost:80`.
- Deploy or update from WSL: `bash deploy/demo-deploy.sh home@<laptop-ip>` (`DEMO_REF=<branch>` for another ref).
  Only committed files travel (`git archive`). The first run writes the laptop's `.env` with new random secrets;
  the demo login password is `SEED_DEFAULT_PASSWORD` in that file.
- The app trusts forwarded headers only from `127.0.0.1`/`::1` (the tunnel), so links are `https://` behind the
  tunnel while a server facing the internet directly cannot be spoofed.
