#!/usr/bin/env bash
set -Eeuo pipefail
# Releases must be readable by nginx (public/); secrets live only in shared/.env (0600) and storage (0750).
umask 022

[[ $EUID -ne 0 && $(id -un) == deploy ]] || { echo "Run as deploy" >&2; exit 1; }
mountpoint -q /srv/data || { echo "Encrypted data volume is not mounted" >&2; exit 1; }
base=/srv/displanery
repo="$base/repo"
releases="$base/releases"
current="$base/current"
ref="${1:-main}"
[[ $ref =~ ^[A-Za-z0-9._/-]+$ && $ref != -* && $ref != *..* ]] || { echo "Invalid git ref" >&2; exit 1; }
[[ -f "$base/shared/.env" && -d "$base/shared/storage" ]] || { echo "Shared environment/storage missing" >&2; exit 1; }
# Load only the domain for the local TLS health probe.
domain="$(sed -n 's/^APP_URL=https:\/\///p' "$base/shared/.env" | head -1)"
[[ $domain =~ ^[A-Za-z0-9.-]+$ ]] || { echo "APP_URL must be https://<domain>" >&2; exit 1; }
curl_tls=()
if [[ $(cat "$base/tls-mode") == self-signed ]]; then curl_tls=(--insecure); fi
export GIT_SSH_COMMAND='ssh -i /home/deploy/.ssh/github_deploy -o IdentitiesOnly=yes'
if pgrep -f '[a]rtisan backup:run' >/dev/null; then
    echo "backup:run is active; refusing deploy"
    exit 1
fi
exec 9>"$base/deploy.lock"
flock -n 9 || { echo "Another deploy is active" >&2; exit 1; }
if [[ ! -d $repo ]]; then
    source_url="$(cat "$base/repo-url")"
    [[ -n $source_url ]] || { echo "Create $repo as a bare mirror from REPO_URL before deploying"; exit 1; }
    git clone --mirror "$source_url" "$repo"
fi
git -C "$repo" remote update --prune
sha="$(git -C "$repo" rev-parse --verify "$ref^{commit}")"
release="$releases/$(date -u +%Y%m%d%H%M%S)-${sha:0:12}"
mkdir -p "$release"
switched=0
maintenance=0
migration_started=0
cleanup() {
    code=$?
    trap - EXIT
    if (( code != 0 )); then
        if (( switched == 0 )); then rm -rf -- "$release"; fi
        if (( switched == 1 )); then
            (cd "$current" && php artisan down --retry=15) || true
        fi
        if (( migration_started == 1 || switched == 1 )); then
            echo "Maintenance remains ON. Fix forward or restore from backup: deploy/README.md"
        elif (( maintenance == 1 )) && [[ -L $current ]]; then
            (cd "$current" && php artisan up) || true
        fi
    fi
    exit "$code"
}
trap cleanup EXIT

git -C "$repo" archive "$sha" | tar -x -C "$release"
ln -s "$base/shared/.env" "$release/.env"
rm -rf "$release/storage"
ln -s "$base/shared/storage" "$release/storage"
cd "$release"
composer install --no-dev --classmap-authoritative --no-interaction --prefer-dist
PUPPETEER_SKIP_DOWNLOAD=1 npm ci
npm run build
php artisan optimize
php artisan filament:optimize
if [[ -L $current ]]; then
    if pgrep -f '[a]rtisan backup:run' >/dev/null; then
        echo "backup:run started during build; refusing deploy"
        exit 1
    fi
    if php artisan migrate:status --pending=10 --no-ansi >/dev/null; then
        pending=0
    else
        status=$?
        [[ $status -eq 10 ]] || { echo "Could not inspect migration status"; exit "$status"; }
        pending=1
    fi
else
    pending=1
fi
if (( pending == 1 )); then
    if [[ -L $current ]]; then (cd "$current" && php artisan down --retry=15); fi
    maintenance=1
    sudo supervisorctl stop displanery-worker || [[ ! -L $current ]]
    migration_started=1
    php artisan migrate --force
fi
ln -s "$release" "$base/current.new"
mv -Tf "$base/current.new" "$current"
switched=1
sudo systemctl reload php8.4-fpm
if (( pending == 1 )); then sudo supervisorctl start displanery-worker; fi
php artisan queue:restart
if (( maintenance == 1 )); then php artisan up; fi
curl "${curl_tls[@]}" --fail --silent --show-error --max-time 15 --resolve "$domain:443:127.0.0.1" "https://$domain/up" >/dev/null
maintenance=0
# Keep five newest releases; never remove the active target.
mapfile -t old < <(find "$releases" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' | sort -r | tail -n +6)
for name in "${old[@]}"; do
    [[ "$releases/$name" == "$(readlink -f "$current")" ]] || rm -rf -- "${releases:?}/$name"
done
echo "Deployed $sha"
