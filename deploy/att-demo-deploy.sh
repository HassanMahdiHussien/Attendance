#!/usr/bin/env bash
# Deploys Attendance to the demo laptop over SSH as https://att.hasnmahdi.com, with fake demo data only.
# Runs beside the Displanary demo without root: its own folder (~/attendance), its own database, and user-level
# systemd services (web on 127.0.0.1:8090, queue worker, scheduler). Run from the project folder in WSL:
#   bash deploy/att-demo-deploy.sh home@192.168.0.141              (main)
#   ATT_REF=my-branch bash deploy/att-demo-deploy.sh home@...      (another branch)
#   ATT_RESEED=1 bash deploy/att-demo-deploy.sh home@...           (wipe and reseed, e.g. to move demo dates forward)
# Only committed files travel (git archive), so git-ignored material such as real sample files never leaves.
set -Eeuo pipefail

TARGET="${1:?Usage: bash deploy/att-demo-deploy.sh user@host}"
REF="${ATT_REF:-main}"
KEY="${DEMO_SSH_KEY:-$HOME/.ssh/displanery_demo}"
DOMAIN="${ATT_DOMAIN:-att.hasnmahdi.com}"
PORT="${ATT_PORT:-8090}"
RESEED="${ATT_RESEED:-0}"
cd "$(dirname "${BASH_SOURCE[0]}")/.."
remote() { ssh -i "$KEY" -o BatchMode=yes "$TARGET" "$@"; }

echo "== Copying $REF ($(git rev-parse --short "$REF")) to $TARGET:~/attendance"
git archive --format=tar "$REF" | remote 'mkdir -p ~/attendance && tar -x -C ~/attendance'

remote bash -s -- "$DOMAIN" "$PORT" "$RESEED" <<'REMOTE'
set -Eeuo pipefail
DOMAIN="$1"; PORT="$2"; RESEED="$3"
cd ~/attendance
command -v php >/dev/null && command -v composer >/dev/null && command -v mysql >/dev/null \
    || { echo "PHP, Composer and MySQL come from the Displanary demo setup (deploy/dev-setup.sh); run that first" >&2; exit 1; }
if [[ ! -f .env ]]; then
    # No root here: reuse the Displanary demo's MySQL account, which may create databases named testing*.
    [[ -f ~/displanery/.env ]] || { echo "Missing ~/displanery/.env (the Displanary demo)" >&2; exit 1; }
    db_user="$(grep -E '^DB_USERNAME=' ~/displanery/.env | cut -d= -f2-)"
    db_pass="$(grep -E '^DB_PASSWORD=' ~/displanery/.env | cut -d= -f2-)"
    seed="$(openssl rand -base64 24 | tr -d '/+=' | cut -c1-20)"
    cp .env.example .env
    sed -i -e "s/^APP_ENV=.*/APP_ENV=demo/" -e "s/^APP_DEBUG=.*/APP_DEBUG=false/" \
        -e "s#^APP_URL=.*#APP_URL=https://$DOMAIN#" -e "s/^LOG_LEVEL=.*/LOG_LEVEL=warning/" \
        -e "s/^DB_HOST=.*/DB_HOST=127.0.0.1/" -e "s/^DB_DATABASE=.*/DB_DATABASE=testing_att_demo/" \
        -e "s/^DB_USERNAME=.*/DB_USERNAME=$db_user/" -e "s/^DB_PASSWORD=.*/DB_PASSWORD=$db_pass/" \
        -e "s/^SESSION_SECURE_COOKIE=.*/SESSION_SECURE_COOKIE=true/" \
        -e "s/^SEED_DEFAULT_PASSWORD=.*/SEED_DEFAULT_PASSWORD=$seed/" .env
    chmod 600 .env
    echo "Created .env (the demo login password is SEED_DEFAULT_PASSWORD in ~/attendance/.env)"
fi
env_value() { grep -E "^$1=" .env | tail -1 | cut -d= -f2-; }
MYSQL_PWD="$(env_value DB_PASSWORD)" mysql -h127.0.0.1 -u"$(env_value DB_USERNAME)" \
    -e "CREATE DATABASE IF NOT EXISTS \`$(env_value DB_DATABASE)\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"

echo "== Building"
composer install --no-dev --optimize-autoloader --no-interaction --quiet
npm ci --no-audit --no-fund --loglevel=error && npm run build --silent
grep -q '^APP_KEY=base64:' .env || php artisan key:generate --force

echo "== Services"
units=~/.config/systemd/user
mkdir -p "$units"
# php -S with workers is enough for a demo; PHP limits match the uploads the screens accept.
cat > "$units/att-web.service" <<EOF
[Unit]
Description=Attendance demo web ($DOMAIN)
[Service]
# Laravel's router script finds index.php through the working directory, as with artisan serve.
WorkingDirectory=%h/attendance/public
Environment=PHP_CLI_SERVER_WORKERS=4
ExecStart=/usr/bin/php -d upload_max_filesize=60M -d post_max_size=60M -d memory_limit=512M -S 127.0.0.1:$PORT -t . ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
Restart=always
RestartSec=3
[Install]
WantedBy=default.target
EOF
for unit in queue scheduler; do
    if [[ $unit == queue ]]; then command='artisan queue:work --tries=3 --sleep=3 --memory=512'; else command='artisan schedule:work'; fi
    cat > "$units/att-$unit.service" <<EOF
[Unit]
Description=Attendance demo $unit
[Service]
WorkingDirectory=%h/attendance
ExecStart=/usr/bin/php $command
Restart=always
RestartSec=3
[Install]
WantedBy=default.target
EOF
done
systemctl --user daemon-reload
systemctl --user stop att-queue att-scheduler 2>/dev/null || true

echo "== Migrating"
if [[ $RESEED == 1 ]]; then
    php artisan migrate:fresh --seed --force
elif [[ "$(php artisan migrate:status 2>/dev/null | grep -c Ran || true)" -eq 0 ]]; then
    php artisan migrate --seed --force
else
    php artisan migrate --force
fi
php artisan filament:assets >/dev/null
php artisan optimize:clear >/dev/null
php artisan optimize >/dev/null
systemctl --user enable att-web att-queue att-scheduler >/dev/null 2>&1
systemctl --user restart att-web att-queue att-scheduler
sleep 2
code="$(curl -s -o /dev/null -w '%{http_code}' -H "Host: $DOMAIN" "http://127.0.0.1:$PORT/up" || true)"
echo "== Done: local health check /up -> HTTP $code; services: $(systemctl --user is-active att-web att-queue att-scheduler | tr '\n' ' ')"
loginctl show-user "$USER" -p Linger 2>/dev/null | grep -q 'Linger=yes' \
    || echo "NOTE: services stop when $USER logs out or the laptop reboots, until $USER logs in again. Once, with sudo: loginctl enable-linger $USER"
REMOTE
