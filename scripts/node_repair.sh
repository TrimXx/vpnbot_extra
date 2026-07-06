#!/bin/sh
# Quick child node app upgrade (served from parent via node-repair endpoint).
set -eu

APP_DIR="${APP_DIR:-/root/vpnbot_extra}"
REPO_BRANCH="${REPO_BRANCH:-v2}"
cd "$APP_DIR"

echo "[node-repair] upgrading from origin/$REPO_BRANCH"
git fetch origin "$REPO_BRANCH" --quiet
git reset --hard "origin/$REPO_BRANCH"

bash ./scripts/bootstrap_config.sh

MARKER='# vpnbot-pac-bypass'
if ! grep -q "$MARKER" config/location.conf 2>/dev/null; then
  cat >> config/location.conf <<'EOF'
# vpnbot-pac-bypass
location ~ ^/pac[0-9a-f]*/ {
    access_log off;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_pass http://php;
}
location ~ ^/tlgrm {
    access_log off;
    proxy_pass http://php;
}
EOF
fi

VER="$(awk 'NR==1{for(i=1;i<=NF;i++) if($i ~ /^v[0-9]/){print $i; exit}}' version)"
VER="${VER:-local}"
IP="$(hostname -I | awk '{print $1}')"

IP="$IP" VER="$VER" docker compose --env-file ./.env --env-file ./override.env up -d --force-recreate

docker compose exec -T php php service.php
docker compose exec -T ng nginx -s reload 2>/dev/null || true

echo "[node-repair] done"
