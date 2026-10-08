#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

BASE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SETTINGS=/root/displanery-server.env
LOG=/var/log/displanery-provision.log
[[ $EUID -eq 0 ]] || { echo "Run as root" >&2; exit 1; }
[[ -f $SETTINGS ]] || { echo "Missing $SETTINGS" >&2; exit 1; }
# Settings are root-owned and never echoed to the log.
[[ $(stat -c %a "$SETTINGS") == 600 ]] || { echo "$SETTINGS must be mode 0600" >&2; exit 1; }
# shellcheck source=/dev/null
source "$SETTINGS"
: "${DOMAIN:?DOMAIN required}" "${ADMIN_EMAIL:?ADMIN_EMAIL required}" "${DATA_DEVICE:?DATA_DEVICE required}" "${REPO_URL:?REPO_URL required}"
: "${TLS_MODE:?TLS_MODE required}" "${SSH_PORT:?SSH_PORT required}" "${SWAP_SIZE:?SWAP_SIZE required}"
[[ $DOMAIN =~ ^([a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$ ]] || { echo "Invalid DOMAIN" >&2; exit 1; }
[[ $ADMIN_EMAIL =~ ^[^[:space:]@]+@[^[:space:]@]+\.[^[:space:]@]+$ ]] || { echo "Invalid ADMIN_EMAIL" >&2; exit 1; }
[[ $REPO_URL == git@*:* || $REPO_URL == ssh://* ]] || { echo "REPO_URL must be an SSH URL" >&2; exit 1; }
if ! [[ $SSH_PORT =~ ^[0-9]+$ ]] || (( SSH_PORT < 1 || SSH_PORT > 65535 )); then echo "Invalid SSH_PORT" >&2; exit 1; fi
[[ $SWAP_SIZE =~ ^[1-9][0-9]*[MG]$ ]] || { echo "Invalid SWAP_SIZE" >&2; exit 1; }
[[ $TLS_MODE == letsencrypt || $TLS_MODE == self-signed ]] || { echo "Invalid TLS_MODE" >&2; exit 1; }
[[ $DATA_DEVICE == /dev/disk/by-id/* && -b $DATA_DEVICE ]] || { echo "DATA_DEVICE must be a /dev/disk/by-id/ block device" >&2; exit 1; }
# shellcheck source=/dev/null
[[ -f /etc/os-release ]] && . /etc/os-release
[[ ${ID:-} == ubuntu && ${VERSION_ID:-} == 24.04 ]] || { echo "Ubuntu 24.04 required" >&2; exit 1; }
device="$(readlink -f "$DATA_DEVICE")"
[[ -b $device ]] || { echo "DATA_DEVICE cannot be resolved" >&2; exit 1; }
signature="$(wipefs -n --noheadings "$device")"
luks_key=/root/.luks/displanery.key
if [[ $(blkid -p -o value -s TYPE "$device" 2>/dev/null || true) == crypto_LUKS ]]; then
    [[ -f $luks_key ]] || { echo "Existing LUKS volume has no local key" >&2; exit 1; }
elif [[ -z $signature ]]; then
    [[ ${CONFIRM_FORMAT_DEVICE:-} == "$DATA_DEVICE" ]] || { echo "CONFIRM_FORMAT_DEVICE must equal DATA_DEVICE" >&2; exit 1; }
    serial="$(lsblk -dno SERIAL "$device" | xargs)"
    [[ -n $serial && ${CONFIRM_FORMAT_SERIAL:-} == "$serial" ]] || { echo "CONFIRM_FORMAT_SERIAL must match lsblk serial" >&2; exit 1; }
    [[ $(lsblk -dno TYPE "$device") == disk ]] || { echo "DATA_DEVICE must be TYPE=disk" >&2; exit 1; }
    [[ $(lsblk -npo NAME "$device" | wc -l) -eq 1 ]] || { echo "DATA_DEVICE has partitions or children" >&2; exit 1; }
    [[ -z $(findmnt -rn -S "$device" -o TARGET) ]] || { echo "DATA_DEVICE is mounted" >&2; exit 1; }
    [[ -z $(lsblk -nro MOUNTPOINTS "$device" | tr -d '[:space:]') ]] || { echo "DATA_DEVICE has mounts" >&2; exit 1; }
    [[ -z $(swapon --noheadings --raw --show=NAME | grep -F -x "$device" || true) ]] || { echo "DATA_DEVICE is swap" >&2; exit 1; }
    root_source="$(findmnt -nro SOURCE /)"
    root_chain="$(lsblk -spno NAME "$root_source")" || { echo "Cannot inspect root device chain" >&2; exit 1; }
    [[ -n $root_chain ]] || { echo "Cannot inspect root device chain" >&2; exit 1; }
    if printf '%s\n' "$root_chain" | grep -Fxq "$device"; then echo "DATA_DEVICE is in root device chain" >&2; exit 1; fi
    [[ -z $(wipefs -n --noheadings "$device") ]] || { echo "DATA_DEVICE has a signature" >&2; exit 1; }
    printf 'Formatting candidate: '; lsblk -dno SIZE,MODEL,SERIAL "$device"
else
    echo "DATA_DEVICE has a non-LUKS signature; refusing to format" >&2
    exit 1
fi

exec > >(tee -a "$LOG") 2>&1
step() { printf '[%s] %s\n' "$(date -u +%FT%TZ)" "$*"; }
trap 'step "Provision failed at line $LINENO; inspect $LOG"' ERR
export DEBIAN_FRONTEND=noninteractive
# The MySQL repository is set up before the first update, so a rerun after a failed run never
# trips over a half-configured source. Oracle's published 2025 key is pinned by its full fingerprint.
if ! command -v curl >/dev/null || ! command -v gpg >/dev/null; then
    rm -f /etc/apt/sources.list.d/mysql.list
    apt-get update
    apt-get install -y --no-install-recommends ca-certificates curl gnupg
fi
install -d -m 0755 /etc/apt/keyrings
key_tmp="$(mktemp)"
curl -fsSL https://repo.mysql.com/RPM-GPG-KEY-mysql-2025 -o "$key_tmp"
fingerprint="$(gpg --batch --show-keys --with-colons "$key_tmp" | awk -F: '$1 == "fpr" { print $10; exit }')"
[[ $fingerprint == BCA43417C3B485DD128EC6D4B7B3B788A8D3785C ]] || { echo "Unexpected MySQL signing key"; exit 1; }
gpg --batch --yes --dearmor -o /etc/apt/keyrings/mysql.gpg "$key_tmp"
rm -f "$key_tmp"
printf 'deb [signed-by=/etc/apt/keyrings/mysql.gpg] https://repo.mysql.com/apt/ubuntu/ noble mysql-8.4-lts\n' > /etc/apt/sources.list.d/mysql.list
# apt verifies signatures as the unprivileged _apt user; the script's umask 077 would hide these from it.
chmod 0644 /etc/apt/keyrings/mysql.gpg /etc/apt/sources.list.d/mysql.list
apt-get update
apt-get install -y --no-install-recommends ca-certificates curl gnupg lsb-release software-properties-common cryptsetup e2fsprogs util-linux ufw fail2ban unattended-upgrades nginx certbot supervisor git unzip zip fontconfig openssh-server systemd-timesyncd sudo openssl cron
if cryptsetup isLuks "$device"; then
    cryptsetup open --test-passphrase --key-file "$luks_key" "$device" || { echo "Existing LUKS volume does not open with local key"; exit 1; }
fi
timedatectl set-timezone UTC
systemctl enable --now systemd-timesyncd
for _ in {1..12}; do
    [[ $(timedatectl show -p NTPSynchronized --value) == yes ]] && break
    sleep 5
done
[[ $(timedatectl show -p NTPSynchronized --value) == yes ]] || { echo "NTP did not synchronize in 60 seconds"; exit 1; }

if ! id deploy >/dev/null 2>&1; then useradd -m -s /bin/bash deploy; fi
install -d -m 0700 -o deploy -g deploy /home/deploy/.ssh
if [[ ! -s /home/deploy/.ssh/authorized_keys ]]; then
    [[ -s /root/.ssh/authorized_keys ]] || { echo "No root or deploy authorized key; refusing SSH hardening"; exit 1; }
    install -m 0600 -o deploy -g deploy /root/.ssh/authorized_keys /home/deploy/.ssh/authorized_keys
fi
[[ -s /home/deploy/.ssh/authorized_keys || -s /root/.ssh/authorized_keys ]] || { echo "No authorized key"; exit 1; }
cat > /etc/ssh/sshd_config.d/00-displanery.conf <<EOF
Port $SSH_PORT
PasswordAuthentication no
KbdInteractiveAuthentication no
PermitRootLogin prohibit-password
PubkeyAuthentication yes
EOF
# Open the new port before sshd moves to it, so the current session is never cut off.
ufw allow "$SSH_PORT/tcp"
ufw allow 80/tcp
ufw allow 443/tcp
ufw --force enable
# sshd -t needs this directory; with socket activation it may not exist until the first login.
install -d -m 0755 /run/sshd
sshd -t
# Ubuntu 24.04 starts sshd from ssh.socket; its port comes from a generator that reads sshd_config.
systemctl daemon-reload
if systemctl is-active --quiet ssh.socket; then systemctl restart ssh.socket; else systemctl try-reload-or-restart ssh; fi
cat > /etc/fail2ban/jail.d/displanery.local <<EOF
[sshd]
enabled = true
port = $SSH_PORT
maxretry = 5
bantime = 1h
EOF
systemctl enable --now fail2ban
systemctl restart fail2ban
cat > /etc/apt/apt.conf.d/52displanery-upgrades <<'EOF'
Unattended-Upgrade::Allowed-Origins { "${distro_id}:${distro_codename}-security"; };
// PHP, MySQL and Node come from their own repositories; their security fixes must apply too.
Unattended-Upgrade::Origins-Pattern { "origin=LP-PPA-ondrej-php"; "origin=MySQL"; "origin=Node Source"; };
Unattended-Upgrade::Automatic-Reboot "true";
Unattended-Upgrade::Automatic-Reboot-Time "01:00";
EOF
cat > /etc/apt/apt.conf.d/20auto-upgrades <<'EOF'
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
EOF
systemctl enable --now unattended-upgrades

if ! cryptsetup isLuks "$device"; then
    install -d -m 0700 /root/.luks
    if [[ ! -e $luks_key ]]; then head -c 4096 /dev/urandom > "$luks_key"; fi
    [[ $(stat -c %s "$luks_key") -eq 4096 ]] || { echo "LUKS key file has wrong length"; exit 1; }
    chmod 0400 "$luks_key"
    cryptsetup luksFormat --type luks2 --batch-mode --key-file "$luks_key" "$device"
fi
if ! cryptsetup status displanery-data >/dev/null 2>&1; then
    cryptsetup open --key-file "$luks_key" "$device" displanery-data
fi
if [[ -z $(blkid -o value -s TYPE /dev/mapper/displanery-data) ]]; then
    mkfs.ext4 -F /dev/mapper/displanery-data
fi
[[ $(blkid -o value -s TYPE /dev/mapper/displanery-data) == ext4 ]] || { echo "Mapped volume is not ext4"; exit 1; }
grep -q '^displanery-data ' /etc/crypttab || printf 'displanery-data %s %s luks,nofail\n' "$DATA_DEVICE" "$luks_key" >> /etc/crypttab
install -d /srv/data /var/lib/mysql
grep -q '^/dev/mapper/displanery-data /srv/data ' /etc/fstab || printf '/dev/mapper/displanery-data /srv/data ext4 defaults,nofail 0 2\n' >> /etc/fstab
mountpoint -q /srv/data || mount /srv/data
install -d -m 0750 /srv/data/mysql /srv/data/storage /srv/data/tmp/mysql /srv/data/tmp/php
chmod 0755 /srv/data/tmp
grep -q '^/srv/data/mysql /var/lib/mysql ' /etc/fstab || printf '/srv/data/mysql /var/lib/mysql none bind,nofail,x-systemd.requires-mounts-for=/srv/data 0 0\n' >> /etc/fstab
mountpoint -q /var/lib/mysql || mount /var/lib/mysql
for service in mysql php8.4-fpm supervisor; do
    install -d "/etc/systemd/system/$service.service.d"
    printf '[Unit]\nRequiresMountsFor=/srv/data\n' > "/etc/systemd/system/$service.service.d/data.conf"
done
printf '[Unit]\nRequiresMountsFor=/srv/data /var/lib/mysql\n' > /etc/systemd/system/mysql.service.d/data.conf
systemctl daemon-reload
if [[ ! -e /srv/data/swapfile ]]; then
    fallocate -l "$SWAP_SIZE" /srv/data/swapfile
    chmod 0600 /srv/data/swapfile
    mkswap /srv/data/swapfile
fi
grep -q '^/srv/data/swapfile ' /etc/fstab || printf '/srv/data/swapfile none swap sw,nofail 0 0\n' >> /etc/fstab
swapon --show=NAME | grep -Fxq /srv/data/swapfile || swapon /srv/data/swapfile

apt-get install -y mysql-community-server mysql-community-client
install -m 0644 "$BASE/templates/mysql.cnf" /etc/mysql/mysql.conf.d/99-displanery.cnf
chown -R mysql:mysql /srv/data/mysql /srv/data/tmp/mysql
systemctl enable --now mysql
systemctl restart mysql

install -d -m 0700 /root/.displanery
secrets=/root/.displanery/secrets
if [[ ! -e $secrets ]]; then
    db_password="$(openssl rand -hex 32)"
    drill_password="$(openssl rand -hex 32)"
    archive_password="$(openssl rand -hex 48)"
    app_key="base64:$(openssl rand -base64 32 | tr -d '\n')"
    { printf 'DB_PASSWORD=%s\nDB_DRILL_PASSWORD=%s\nBACKUP_ARCHIVE_PASSWORD=%s\nAPP_KEY=%s\n' "$db_password" "$drill_password" "$archive_password" "$app_key"; } > "$secrets"
    chmod 0600 "$secrets"
fi
# shellcheck source=/dev/null
source "$secrets"
: "${DB_PASSWORD:?missing in secrets}" "${DB_DRILL_PASSWORD:?missing in secrets}" "${BACKUP_ARCHIVE_PASSWORD:?missing in secrets}" "${APP_KEY:?missing in secrets}"
mysql >/dev/null <<EOF
CREATE DATABASE IF NOT EXISTS displanery CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'displanery'@'127.0.0.1' IDENTIFIED BY '$DB_PASSWORD';
ALTER USER 'displanery'@'127.0.0.1' IDENTIFIED BY '$DB_PASSWORD';
GRANT ALL PRIVILEGES ON displanery.* TO 'displanery'@'127.0.0.1';
CREATE USER IF NOT EXISTS 'displanery_drill'@'127.0.0.1' IDENTIFIED BY '$DB_DRILL_PASSWORD';
ALTER USER 'displanery_drill'@'127.0.0.1' IDENTIFIED BY '$DB_DRILL_PASSWORD';
REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'displanery_drill'@'127.0.0.1';
GRANT ALL PRIVILEGES ON displanery_restore_drill.* TO 'displanery_drill'@'127.0.0.1';
EOF
if MYSQL_PWD="$DB_DRILL_PASSWORD" mysql -h 127.0.0.1 -u displanery_drill -e 'USE displanery' >/dev/null 2>&1; then
    echo "Drill account can access live database"; exit 1
fi

add-apt-repository -y ppa:ondrej/php
apt-get update
# Only what the application uses; the dev image's debugging and other-database extensions stay out.
apt-get install -y php8.4-cli php8.4-fpm php8.4-mysql php8.4-mbstring php8.4-xml php8.4-zip php8.4-curl php8.4-gd php8.4-bcmath php8.4-intl php8.4-readline php8.4-opcache
install -m 0644 "$BASE/templates/php-fpm-pool.conf" /etc/php/8.4/fpm/pool.d/displanery.conf
sed -i 's/^expose_php = On/expose_php = Off/' /etc/php/8.4/fpm/php.ini
sed -i 's/^expose_php = On/expose_php = Off/' /etc/php/8.4/cli/php.ini
printf 'upload_tmp_dir=/srv/data/tmp/php\nsys_temp_dir=/srv/data/tmp/php\n' > /etc/php/8.4/cli/conf.d/99-displanery.ini
chown deploy:deploy /srv/data/tmp/php
systemctl enable --now php8.4-fpm
systemctl restart php8.4-fpm

install -d -o deploy -g deploy /srv/displanery /srv/displanery/releases /srv/displanery/shared
printf '%s\n' "$REPO_URL" > /srv/displanery/repo-url
chown deploy:deploy /srv/displanery/repo-url
chmod 0600 /srv/displanery/repo-url
printf '%s\n' "$TLS_MODE" > /srv/displanery/tls-mode
chown deploy:deploy /srv/displanery/tls-mode
install -d -o deploy -g deploy /srv/data/storage /srv/data/storage/app /srv/data/storage/app/private /srv/data/storage/logs /srv/data/storage/framework /srv/data/storage/framework/cache /srv/data/storage/framework/sessions /srv/data/storage/framework/views /srv/data/storage/app/backup-temp
# PHP runs as deploy; nginx never needs private files.
chmod 0750 /srv/data/storage
ln -sfn /srv/data/storage /srv/displanery/shared/storage
if [[ ! -e /srv/displanery/shared/.env ]]; then
    template="$(cat "$BASE/templates/env.production")"
    template="${template//@@DOMAIN@@/$DOMAIN}"
    template="${template//@@ADMIN_EMAIL@@/$ADMIN_EMAIL}"
    template="${template//@@APP_KEY@@/$APP_KEY}"
    template="${template//@@DB_PASSWORD@@/$DB_PASSWORD}"
    template="${template//@@DB_DRILL_PASSWORD@@/$DB_DRILL_PASSWORD}"
    template="${template//@@BACKUP_ARCHIVE_PASSWORD@@/$BACKUP_ARCHIVE_PASSWORD}"
    printf '%s\n' "$template" > /srv/displanery/shared/.env
    chown deploy:deploy /srv/displanery/shared/.env
    chmod 0600 /srv/displanery/shared/.env
fi
for key in backup_ed25519 github_deploy; do
    if [[ ! -e /home/deploy/.ssh/$key ]]; then
        sudo -u deploy ssh-keygen -q -t ed25519 -N '' -f "/home/deploy/.ssh/$key"
    fi
done
chmod 0600 /home/deploy/.ssh/backup_ed25519 /home/deploy/.ssh/github_deploy
cat > /etc/sudoers.d/displanery-deploy <<'EOF'
deploy ALL=(root) NOPASSWD: /usr/bin/supervisorctl stop displanery-worker, /usr/bin/supervisorctl start displanery-worker, /usr/bin/supervisorctl restart displanery-worker, /usr/bin/systemctl reload php8.4-fpm
EOF
chmod 0440 /etc/sudoers.d/displanery-deploy
visudo -cf /etc/sudoers.d/displanery-deploy
install -m 0644 "$BASE/templates/supervisor-worker.conf" /etc/supervisor/conf.d/displanery-worker.conf
systemctl enable --now supervisor
# supervisorctl fails until supervisord has created its control socket.
for _ in {1..30}; do [[ -S /var/run/supervisor.sock ]] && break; sleep 1; done
supervisorctl reread
supervisorctl update

curl -fsSL https://deb.nodesource.com/setup_24.x -o /tmp/displanery-nodesource.sh
bash /tmp/displanery-nodesource.sh
rm -f /tmp/displanery-nodesource.sh
apt-get install -y nodejs poppler-utils tesseract-ocr tesseract-ocr-ara libnss3 libatk1.0-0t64 libatk-bridge2.0-0t64 libcups2t64 libdrm2 libxkbcommon0 libxcomposite1 libxdamage1 libxfixes3 libxrandr2 libgbm1 libasound2t64 libpango-1.0-0 libcairo2 fonts-noto-core fonts-hosny-amiri fonts-dejavu-core
if [[ ! -x /usr/local/bin/chrome-headless-shell ]]; then
    npx -y @puppeteer/browsers install chrome-headless-shell@stable --path /opt/chrome
    chrome="$(find /opt/chrome -type f -name chrome-headless-shell -print -quit)"
    [[ -n $chrome ]] || { echo "Chrome headless shell missing"; exit 1; }
    ln -s "$chrome" /usr/local/bin/chrome-headless-shell
fi
chmod -R a+rX /opt/chrome
fc-cache -f
if [[ ! -x /usr/local/bin/composer ]]; then
    curl -fsSL https://composer.github.io/installer.sig -o /tmp/composer.sig
    curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
    expected="$(cat /tmp/composer.sig)"
    actual="$(sha384sum /tmp/composer-setup.php | cut -d ' ' -f1)"
    [[ $expected == "$actual" ]] || { echo "Composer installer checksum mismatch"; exit 1; }
    php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
    rm -f /tmp/composer.sig /tmp/composer-setup.php
fi

install -d /var/www/letsencrypt
if [[ $TLS_MODE == letsencrypt ]]; then
    # The challenge can be served before the application release exists.
    cat > /etc/nginx/sites-available/displanery-acme <<EOF
server { listen 80; server_name $DOMAIN; location ^~ /.well-known/acme-challenge/ { root /var/www/letsencrypt; } }
EOF
    ln -sfn /etc/nginx/sites-available/displanery-acme /etc/nginx/sites-enabled/displanery-acme
    nginx -t && systemctl reload nginx
    certbot certonly --webroot -w /var/www/letsencrypt -d "$DOMAIN" -m "$ADMIN_EMAIL" --agree-tos --non-interactive --keep-until-expiring
    cert="/etc/letsencrypt/live/$DOMAIN/fullchain.pem"
    cert_key="/etc/letsencrypt/live/$DOMAIN/privkey.pem"
    printf '#!/usr/bin/env bash\nsystemctl reload nginx\n' > /etc/letsencrypt/renewal-hooks/deploy/displanery-nginx
    chmod 0755 /etc/letsencrypt/renewal-hooks/deploy/displanery-nginx
    systemctl enable --now certbot.timer
else
    install -d -m 0700 /etc/ssl/displanery
    cert=/etc/ssl/displanery/fullchain.pem
    cert_key=/etc/ssl/displanery/privkey.pem
    if [[ ! -e $cert ]]; then openssl req -x509 -newkey rsa:3072 -nodes -days 365 -subj "/CN=$DOMAIN" -keyout "$cert_key" -out "$cert"; fi
fi
site="$(cat "$BASE/templates/nginx-site.conf")"
site="${site//@@DOMAIN@@/$DOMAIN}"
site="${site//@@CERT@@/$cert}"
site="${site//@@KEY@@/$cert_key}"
printf '%s\n' "$site" > /etc/nginx/sites-available/displanery
ln -sfn /etc/nginx/sites-available/displanery /etc/nginx/sites-enabled/displanery
rm -f /etc/nginx/sites-enabled/default /etc/nginx/sites-enabled/displanery-acme
nginx -t
systemctl enable --now nginx
systemctl reload nginx
cat > /etc/cron.d/displanery <<'EOF'
* * * * * deploy mountpoint -q /srv/data && cd /srv/displanery/current && /usr/bin/php artisan schedule:run >> /srv/data/storage/logs/scheduler.log 2>&1
EOF
chmod 0644 /etc/cron.d/displanery
systemctl enable --now cron
step "Provision complete. Add GitHub deploy key and backup key below, fill shared .env, then run deploy.sh."
step "Copy /root/.displanery/secrets and /root/.luks/displanery.key off-server into a password manager; no secrets are printed."
cat /home/deploy/.ssh/github_deploy.pub
cat /home/deploy/.ssh/backup_ed25519.pub
