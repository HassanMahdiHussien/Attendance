#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
[[ $EUID -eq 0 ]] || { echo "Run as root" >&2; exit 1; }
settings=/root/displanery-backup-server.env
[[ -f $settings && $(stat -c %a "$settings") == 600 ]] || { echo "Create $settings with mode 0600" >&2; exit 1; }
# shellcheck source=/dev/null
source "$settings"
: "${SSH_PORT:?SSH_PORT required}" "${BACKUP_PUBLIC_KEY:?BACKUP_PUBLIC_KEY required}"
if ! [[ $SSH_PORT =~ ^[0-9]+$ ]] || (( SSH_PORT < 1 || SSH_PORT > 65535 )); then echo "Invalid SSH_PORT" >&2; exit 1; fi
[[ $BACKUP_PUBLIC_KEY == ssh-ed25519\ * ]] || { echo "BACKUP_PUBLIC_KEY must be an ed25519 public key" >&2; exit 1; }
VAULT_DAILY_DAYS="${VAULT_DAILY_DAYS:-35}"
VAULT_MONTHLY_MONTHS="${VAULT_MONTHLY_MONTHS:-24}"
[[ $VAULT_DAILY_DAYS =~ ^[1-9][0-9]*$ && $VAULT_MONTHLY_MONTHS =~ ^[1-9][0-9]*$ ]] || { echo "Invalid retention" >&2; exit 1; }
# shellcheck source=/dev/null
[[ -f /etc/os-release ]] && . /etc/os-release
[[ ${ID:-} == ubuntu && ${VERSION_ID:-} == 24.04 ]] || { echo "Ubuntu 24.04 required" >&2; exit 1; }
[[ -s /root/.ssh/authorized_keys ]] || { echo "Root SSH key required before hardening" >&2; exit 1; }
export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y --no-install-recommends openssh-server ufw fail2ban unattended-upgrades
if ! id displanery-backup >/dev/null 2>&1; then
    useradd --no-create-home --shell /usr/sbin/nologin displanery-backup
fi
install -d -m 0755 -o root -g root /srv/backups
install -d -m 0750 -o displanery-backup -g displanery-backup /srv/backups/displanery
install -d -m 0700 -o root -g root /srv/backup-vault /srv/backup-vault/daily /srv/backup-vault/monthly
install -d -m 0755 /etc/ssh/authorized_keys
printf '%s\n' "$BACKUP_PUBLIC_KEY" > /etc/ssh/authorized_keys/displanery-backup
# sshd reads AuthorizedKeysFile as the target user; root ownership keeps it unwritable by that user.
chmod 0644 /etc/ssh/authorized_keys/displanery-backup
cat > /etc/ssh/sshd_config.d/00-displanery-backup.conf <<EOF
Port $SSH_PORT
PasswordAuthentication no
KbdInteractiveAuthentication no
PermitRootLogin prohibit-password
PubkeyAuthentication yes
EOF
if ! grep -q '^# displanery backup SFTP$' /etc/ssh/sshd_config; then
cat >> /etc/ssh/sshd_config <<'EOF'
# displanery backup SFTP
Match User displanery-backup
    ChrootDirectory /srv/backups
    ForceCommand internal-sftp
    AuthorizedKeysFile /etc/ssh/authorized_keys/%u
    AllowTcpForwarding no
    X11Forwarding no
    PermitTTY no
EOF
fi
ufw allow "$SSH_PORT/tcp"
ufw --force enable
# sshd -t needs this directory; with socket activation it may not exist until the first login.
install -d -m 0755 /run/sshd
sshd -t
# Ubuntu 24.04 starts sshd from ssh.socket; its port comes from a generator that reads sshd_config.
systemctl daemon-reload
if systemctl is-active --quiet ssh.socket; then systemctl restart ssh.socket; else systemctl try-reload-or-restart ssh; fi
cat > /etc/fail2ban/jail.d/displanery-backup.local <<EOF
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
Unattended-Upgrade::Automatic-Reboot "true";
Unattended-Upgrade::Automatic-Reboot-Time "01:00";
EOF
cat > /etc/apt/apt.conf.d/20auto-upgrades <<'EOF'
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
EOF
systemctl enable --now unattended-upgrades
cat > /etc/displanery-vault.env <<EOF
VAULT_DAILY_DAYS=$VAULT_DAILY_DAYS
VAULT_MONTHLY_MONTHS=$VAULT_MONTHLY_MONTHS
EOF
chmod 0600 /etc/displanery-vault.env
install -m 0750 "$(dirname "${BASH_SOURCE[0]}")/vault-copy.sh" /usr/local/sbin/displanery-vault-copy
cat > /etc/cron.d/displanery-vault <<'EOF'
15 * * * * root /usr/local/sbin/displanery-vault-copy >> /var/log/displanery-vault.log 2>&1
EOF
chmod 0644 /etc/cron.d/displanery-vault
# phpseclib expects sha256 over the raw ED25519 host public key blob, in lowercase hex.
fingerprint="$(awk '{print $2}' /etc/ssh/ssh_host_ed25519_key.pub | base64 -d | sha256sum | awk '{print $1}')"
printf 'BACKUP_SFTP_USERNAME=displanery-backup\nBACKUP_SFTP_ROOT=/displanery\nBACKUP_SFTP_FINGERPRINT=sha256:%s\n' "$fingerprint"
