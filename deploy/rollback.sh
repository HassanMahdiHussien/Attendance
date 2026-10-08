#!/usr/bin/env bash
set -Eeuo pipefail
[[ $(id -un) == deploy ]] || { echo "Run as deploy" >&2; exit 1; }
[[ ${1:-} == "" || ${1:-} == --force ]] || { echo "Usage: rollback.sh [--force]" >&2; exit 1; }
mountpoint -q /srv/data || { echo "Data volume not mounted" >&2; exit 1; }
base=/srv/displanery
current="$base/current"
[[ -L $current ]] || { echo "No current release" >&2; exit 1; }
exec 9>"$base/deploy.lock"
flock -n 9 || { echo "Another deploy is active" >&2; exit 1; }
active="$(readlink -f "$current")"
mapfile -t candidates < <(find "$base/releases" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' | sort -r)
previous=
seen_active=0
for name in "${candidates[@]}"; do
    if [[ "$base/releases/$name" == "$active" ]]; then seen_active=1; continue; fi
    if (( seen_active == 1 )); then previous="$base/releases/$name"; break; fi
done
[[ -n $previous ]] || { echo "No previous release" >&2; exit 1; }
# Query through the app's connection; compare database migration names with prior files.
# shellcheck disable=SC2016 # PHP source, not shell expansions.
unknown="$(cd "$active" && php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$known = array_map(fn($p) => basename($p, ".php"), glob($argv[1]."/database/migrations/*.php"));
$ran = Illuminate\Support\Facades\DB::table("migrations")->pluck("migration")->all();
echo implode(",", array_diff($ran, $known));
' "$previous")"
if [[ -n $unknown && ${1:-} != --force ]]; then
    echo "Previous code lacks applied migrations: $unknown"
    echo "Refusing rollback. Use --force only after verifying schema compatibility."
    exit 1
fi
if [[ -n $unknown ]]; then echo "WARNING: previous code does not know migrations: $unknown"; fi
if pgrep -f '^(/usr/bin/)?php[0-9.]* (/[^ ]*/)?artisan backup:run' >/dev/null; then echo "Backup running; refusing rollback"; exit 1; fi
(cd "$active" && php artisan down --retry=15)
sudo supervisorctl stop displanery-worker
ln -s "$previous" "$base/current.new"
mv -Tf "$base/current.new" "$current"
sudo systemctl reload php8.4-fpm
sudo supervisorctl start displanery-worker
(cd "$previous" && php artisan queue:restart)
domain="$(sed -n 's/^APP_URL=https:\/\///p' "$base/shared/.env" | head -1)"
curl_tls=()
if [[ $(cat "$base/tls-mode") == self-signed ]]; then curl_tls=(--insecure); fi
(cd "$previous" && php artisan up)
if ! curl "${curl_tls[@]}" --fail --silent --show-error --max-time 15 --resolve "$domain:443:127.0.0.1" "https://$domain/up" >/dev/null; then
    (cd "$previous" && php artisan down --retry=15)
    echo "Health check failed; maintenance remains on. See deploy/README.md."
    exit 1
fi
echo "Rolled back code only. No migrations were reversed."
