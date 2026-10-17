#!/usr/bin/env bash
# Deploys a git ref to the demo laptop over SSH, with demo data only. Run from the project folder in WSL:
#   bash deploy/demo-deploy.sh home@192.168.0.141            (main)
#   DEMO_REF=my-branch bash deploy/demo-deploy.sh home@...   (another branch)
# Only committed files travel (git archive), so git-ignored material such as docs/attachments never leaves.
# The first run creates ~/displanery/.env on the laptop with fresh random secrets; the root-level install is
# then a one-time step for the laptop's owner: sudo bash ~/displanery/deploy/dev-setup.sh
set -Eeuo pipefail

TARGET="${1:?Usage: bash deploy/demo-deploy.sh user@host}"
REF="${DEMO_REF:-main}"
KEY="${DEMO_SSH_KEY:-$HOME/.ssh/displanery_demo}"
DOMAIN="${DEMO_DOMAIN:-demo.hasnmahdi.com}"
cd "$(dirname "${BASH_SOURCE[0]}")/.."
remote() { ssh -i "$KEY" -o BatchMode=yes "$TARGET" "$@"; }

echo "== Copying $REF ($(git rev-parse --short "$REF")) to $TARGET"
git archive --format=tar "$REF" | remote 'mkdir -p ~/displanery && tar -x -C ~/displanery'

remote bash -s -- "$DOMAIN" <<'REMOTE'
set -Eeuo pipefail
cd ~/displanery
if [[ ! -f .env ]]; then
    cp .env.example .env
    db="$(openssl rand -hex 16)"
    seed="$(openssl rand -base64 24 | tr -d '/+=' | cut -c1-20)"
    sed -i -e "s/^APP_ENV=.*/APP_ENV=demo/" -e "s/^APP_DEBUG=.*/APP_DEBUG=false/" \
        -e "s#^APP_URL=.*#APP_URL=https://$1#" -e "s/^LOG_LEVEL=.*/LOG_LEVEL=warning/" \
        -e "s/^DB_HOST=.*/DB_HOST=127.0.0.1/" -e "s/^DB_DATABASE=.*/DB_DATABASE=displanery_demo/" \
        -e "s/^DB_USERNAME=.*/DB_USERNAME=displanery_demo/" -e "s/^DB_PASSWORD=.*/DB_PASSWORD=$db/" \
        -e "s/^SEED_DEFAULT_PASSWORD=.*/SEED_DEFAULT_PASSWORD=$seed/" .env
    chmod 600 .env
    echo "Created .env with new secrets (the demo login password is SEED_DEFAULT_PASSWORD in ~/displanery/.env)"
fi
if ! command -v php >/dev/null || ! command -v composer >/dev/null; then
    echo "NEXT: on the laptop run once:  sudo bash ~/displanery/deploy/dev-setup.sh   then deploy again"
    exit 0
fi
echo "== Building"
composer install --no-dev --optimize-autoloader --no-interaction --quiet
npm ci --no-audit --no-fund --loglevel=error && npm run build --silent
grep -q '^APP_KEY=base64:' .env || php artisan key:generate --force
echo "== Migrating"
if [[ "$(php artisan migrate:status 2>/dev/null | grep -c Ran || true)" -eq 0 ]]; then
    php artisan migrate --seed --force
else
    php artisan migrate --force
fi
php artisan filament:assets >/dev/null
php artisan optimize:clear >/dev/null
php artisan queue:restart >/dev/null
echo "== Done: $(php artisan about --only=environment 2>/dev/null | grep -E 'Environment|Debug' | tr -s ' ' | tr '\n' ' ')"
REMOTE
