# ADR 0012: Ubuntu 26.04 and PHP 8.5 in production

**Status:** Accepted, 2026-10-16. Supersedes the operating system and PHP version in ADR 0010; the rest of ADR 0010 stands.

## Context

ADR 0010 put production on Ubuntu 24.04 with PHP 8.4 from the ondrej PPA and MySQL 8.4 from Oracle's apt repository. On 2026-10-16 local development moved off Docker to native WSL on Ubuntu 26.04, which ships PHP 8.5. Developing on 8.5 and running 8.4 in production would let code that only works on 8.5 pass every local test and fail on the server.

Ubuntu 26.04 carries everything the application needs in its own archive: `php8.5-*` and `mysql-server` 8.4. The ondrej PPA has no 26.04 build, and Oracle's repository is no longer needed. NodeSource `node_24.x` supports 26.04.

## Decision

The product owner chose Ubuntu 26.04 with PHP 8.5 for production as well, so development and production match.

- `deploy/provision.sh` and `deploy/backup-server.sh` require `VERSION_ID` 26.04.
- PHP 8.5 and MySQL 8.4 come from Ubuntu's archive. The ondrej PPA and the Oracle MySQL repository with its pinned key are dropped, and unattended upgrades rely on the Ubuntu security pocket for both. Node still comes from NodeSource.
- OPcache is built into PHP 8.5, so there is no separate opcache package.
- Ubuntu's MySQL has an AppArmor profile. The provisioning script adds a local rule for the MySQL temp directory on the encrypted volume; the data directory is reached through the existing `/var/lib/mysql` bind mount.
- Time sync uses chrony, which is 26.04's default. Installing systemd-timesyncd would remove chrony and the `ubuntu-minimal` metapackage.
- LUKS data volume, SSH, ufw, fail2ban, backups and restore drills are unchanged.

## Consequences

Development and production run the same PHP and MySQL, from the same source. Two third-party package sources and a signing key we had to track are gone. PHP and MySQL security fixes now come on Ubuntu's schedule, not the PPA's or Oracle's. The hosting provider must offer an Ubuntu 26.04 image; the scripts refuse to run on any other release. A 26.04 server has not been provisioned yet, so the first real provisioning run is also the first full test of these scripts.
