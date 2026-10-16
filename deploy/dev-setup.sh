#!/usr/bin/env bash
# Local development setup on Ubuntu 26.04 (WSL), without Docker. Installs the same runtime as the server
# (PHP 8.5, MySQL 8.4, Node 24, headless Chrome, OCR) without the server hardening in provision.sh.
# Usage, from the project folder:  sudo bash deploy/dev-setup.sh [path/to/backup.sql]
set -Eeuo pipefail

[[ $EUID -eq 0 ]] || { echo "Run with sudo" >&2; exit 1; }
DEV_USER="${SUDO_USER:?Run with sudo from your own account}"
APP="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BACKUP="${1:-}"
# shellcheck source=/dev/null
. /etc/os-release
[[ ${ID:-} == ubuntu && ${VERSION_ID:-} == 26.04 ]] || { echo "Ubuntu 26.04 required" >&2; exit 1; }
[[ -f $APP/.env ]] || { echo "Missing $APP/.env" >&2; exit 1; }
[[ -z $BACKUP || -f $BACKUP ]] || { echo "Backup file not found: $BACKUP" >&2; exit 1; }
env_value() { grep -E "^$1=" "$APP/.env" | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
DB_NAME="$(env_value DB_DATABASE)"
DB_USER="$(env_value DB_USERNAME)"
DB_PASS="$(env_value DB_PASSWORD)"
[[ $DB_NAME =~ ^[A-Za-z0-9_]+$ && $DB_USER =~ ^[A-Za-z0-9_]+$ && -n $DB_PASS ]] || { echo "DB_DATABASE, DB_USERNAME and DB_PASSWORD must be set in .env" >&2; exit 1; }
step() { printf '\n== %s\n' "$*"; }
# The .env values above are never echoed, so the log holds no secrets.
exec > >(tee -a /var/log/displanery-dev-setup.log) 2>&1
trap 'echo "Setup failed at line $LINENO; see /var/log/displanery-dev-setup.log"' ERR

export DEBIAN_FRONTEND=noninteractive
step "Packages"
apt-get update
# OPcache is built into PHP 8.5, so there is no php8.5-opcache package.
apt-get install -y --no-install-recommends ca-certificates curl gnupg unzip zip git nginx mysql-server mysql-client \
    php8.5-cli php8.5-fpm php8.5-mysql php8.5-mbstring php8.5-xml php8.5-zip php8.5-curl php8.5-gd php8.5-bcmath \
    php8.5-intl php8.5-readline php8.5-sqlite3 \
    poppler-utils tesseract-ocr tesseract-ocr-ara fontconfig fonts-noto-core fonts-hosny-amiri fonts-dejavu-core \
    libnss3 libatk1.0-0t64 libatk-bridge2.0-0t64 libcups2t64 libdrm2 libxkbcommon0 libxcomposite1 libxdamage1 \
    libxfixes3 libxrandr2 libgbm1 libasound2t64 libpango-1.0-0 libcairo2
if ! node --version 2>/dev/null | grep -q '^v24\.'; then
    curl -fsSL https://deb.nodesource.com/setup_24.x -o /tmp/displanery-nodesource.sh
    bash /tmp/displanery-nodesource.sh
    rm -f /tmp/displanery-nodesource.sh
    apt-get install -y nodejs
fi
if [[ ! -x /usr/local/bin/chrome-headless-shell ]]; then
    npx -y @puppeteer/browsers install chrome-headless-shell@stable --path /opt/chrome
    chrome="$(find /opt/chrome -type f -name chrome-headless-shell -print -quit)"
    [[ -n $chrome ]] || { echo "Chrome headless shell missing" >&2; exit 1; }
    ln -sfn "$chrome" /usr/local/bin/chrome-headless-shell
fi
chmod -R a+rX /opt/chrome
fc-cache -f >/dev/null
if [[ ! -x /usr/local/bin/composer ]]; then
    curl -fsSL https://composer.github.io/installer.sig -o /tmp/composer.sig
    curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
    [[ "$(cat /tmp/composer.sig)" == "$(sha384sum /tmp/composer-setup.php | cut -d ' ' -f1)" ]] || { echo "Composer installer checksum mismatch" >&2; exit 1; }
    php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
    rm -f /tmp/composer.sig /tmp/composer-setup.php
fi

step "MySQL"
# Stored functions in the migrations need this, as the Docker image's --log-bin-trust-function-creators did.
printf '[mysqld]\nlog_bin_trust_function_creators = 1\nbind-address = 127.0.0.1\n' > /etc/mysql/mysql.conf.d/99-displanery-dev.cnf
systemctl enable --now mysql
systemctl restart mysql
# Tests run against `testing` and, in parallel, `testing_test_N`; the dev database is never touched by them.
mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- '%' because the restored triggers are defined by that account; MySQL listens on 127.0.0.1 only.
CREATE USER IF NOT EXISTS '$DB_USER'@'%' IDENTIFIED BY '$DB_PASS';
ALTER USER '$DB_USER'@'%' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'%';
GRANT ALL PRIVILEGES ON \`testing%\`.* TO '$DB_USER'@'%';
FLUSH PRIVILEGES;
SQL
if [[ -n $BACKUP ]]; then
    tables="$(mysql -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$DB_NAME'")"
    if [[ $tables -eq 0 ]]; then
        mysql < "$BACKUP"
        echo "Restored $BACKUP into $DB_NAME"
    else
        echo "$DB_NAME already has $tables tables; backup NOT restored (drop it yourself if you mean to replace it)"
    fi
fi

step "PHP-FPM and nginx"
cat > /etc/php/8.5/fpm/pool.d/displanery-dev.conf <<EOF
[displanery-dev]
user = $DEV_USER
group = $DEV_USER
listen = /run/php/php8.5-fpm-displanery-dev.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = dynamic
pm.max_children = 8
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 4
php_admin_value[upload_max_filesize] = 60M
php_admin_value[post_max_size] = 60M
EOF
systemctl enable --now php8.5-fpm
systemctl restart php8.5-fpm
cat > /etc/nginx/sites-available/displanery-dev <<EOF
server {
    listen 80 default_server;
    server_name localhost;
    root $APP/public;
    index index.php;
    client_max_body_size 60m;
    location / { try_files \$uri \$uri/ /index.php?\$query_string; }
    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.5-fpm-displanery-dev.sock;
        fastcgi_read_timeout 300;
    }
    location ~ /\.(?!well-known) { deny all; }
}
EOF
ln -sfn /etc/nginx/sites-available/displanery-dev /etc/nginx/sites-enabled/displanery-dev
rm -f /etc/nginx/sites-enabled/default
# nginx (www-data) must be able to walk into the home folder to reach public/.
chmod o+x "$(eval echo "~$DEV_USER")"
nginx -t
systemctl enable --now nginx
systemctl reload nginx

step "Queue worker and scheduler"
for unit in queue scheduler; do
    if [[ $unit == queue ]]; then command='artisan queue:work --tries=3 --sleep=3'; else command='artisan schedule:work'; fi
    cat > "/etc/systemd/system/displanery-$unit.service" <<EOF
[Unit]
Description=Displanery dev $unit
After=mysql.service
Requires=mysql.service

[Service]
User=$DEV_USER
WorkingDirectory=$APP
ExecStart=/usr/bin/php $command
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
EOF
done
systemctl daemon-reload
systemctl enable --now displanery-queue displanery-scheduler

step "Done"
php --version | head -1
mysql --version
node --version
echo "Set DB_HOST=127.0.0.1 in .env, then run: php artisan migrate --pretend | head  (should list nothing to run)"
echo "Restart the worker after code changes: sudo systemctl restart displanery-queue"
