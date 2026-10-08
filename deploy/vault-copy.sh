#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
# shellcheck source=/dev/null
source /etc/displanery-vault.env
today="$(date -u +%F)"
daily="/srv/backup-vault/daily/$today"
install -d -m 0700 "$daily"
while IFS= read -r -d '' archive; do
    relative="${archive#/srv/backups/displanery/}"
    target="$daily/$relative"
    install -d -m 0700 "$(dirname "$target")"
    if [[ ! -e $target ]]; then
        cp -p -- "$archive" "$target"
    fi
done < <(find /srv/backups/displanery -type f -name '*.zip' -print0)
# The first vault run of a month keeps that day's complete independent snapshot.
month="$(date -u +%Y-%m)"
if [[ ! -d /srv/backup-vault/monthly/$month ]]; then
    cp -a -- "$daily" "/srv/backup-vault/monthly/$month"
else
    cp -an -- "$daily/." "/srv/backup-vault/monthly/$month/"
fi
find /srv/backup-vault/daily -mindepth 1 -maxdepth 1 -type d -mtime "+$VAULT_DAILY_DAYS" -exec rm -rf -- {} +
find /srv/backup-vault/monthly -mindepth 1 -maxdepth 1 -type d -mtime "+$((VAULT_MONTHLY_MONTHS * 31))" -exec rm -rf -- {} +
