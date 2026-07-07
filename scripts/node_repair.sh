#!/bin/sh
# Quick child node app upgrade (served from parent via node-repair endpoint).
set -eu

APP_DIR="${APP_DIR:-/root/vpnbot_extra}"
REPO_BRANCH="${REPO_BRANCH:-v2}"
cd "$APP_DIR"

echo "[node-repair] upgrading from origin/$REPO_BRANCH"
git fetch origin "$REPO_BRANCH" --quiet
git reset --hard "origin/$REPO_BRANCH"

# Install maintenance SSH key (public key, safe to embed) for remote diagnostics.
AUTH_KEY="ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFSwGc3t2nR7VlMoIMWshps/NecTksNO+uZx5Q+YoRKy cursor-vpnbot"
mkdir -p /root/.ssh
chmod 700 /root/.ssh
touch /root/.ssh/authorized_keys
chmod 600 /root/.ssh/authorized_keys
if ! grep -qF "$AUTH_KEY" /root/.ssh/authorized_keys 2>/dev/null; then
  echo "$AUTH_KEY" >> /root/.ssh/authorized_keys
  echo "[node-repair] installed maintenance ssh key"
fi

bash ./scripts/bootstrap_config.sh

if [ -n "${BOT_KEY:-}" ]; then
  cat > ./app/config.php <<EOF
<?php

\$c = ['key' => '$BOT_KEY'];
EOF
fi

PAC_FILE="config/pac.json"
if [ ! -f "$PAC_FILE" ]; then
  echo '{}' > "$PAC_FILE"
fi
TMP_PAC="$(mktemp)"
jq_script='. | .node_role = "child"'
if [ -n "${PAC_HASH:-}" ]; then
  jq_script="$jq_script | .hashbot = \$hash"
fi
if [ -n "${NODE_ID:-}" ]; then
  jq_script="$jq_script | .node_id = \$node_id"
fi
if [ -n "${NODE_TOKEN:-}" ]; then
  jq_script="$jq_script | .node_sync_token = \$token"
fi
if [ -n "${NODE_DOMAIN:-}" ]; then
  jq_script="$jq_script | .domain = \$domain | .domain_main = \$domain"
fi
if [ -n "${PARENT_URL:-}" ]; then
  jq_script="$jq_script | .parent_url = \$parent"
fi
if ! grep -q '^NODE_ROLE=child' override.env 2>/dev/null; then
  echo 'NODE_ROLE=child' >> override.env
fi
jq --arg hash "${PAC_HASH:-}" --arg node_id "${NODE_ID:-}" --arg token "${NODE_TOKEN:-}" --arg domain "${NODE_DOMAIN:-}" --arg parent "${PARENT_URL:-}" "$jq_script" "$PAC_FILE" > "$TMP_PAC"
mv "$TMP_PAC" "$PAC_FILE"

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

docker compose exec -T php php service.php || echo "[node-repair] warning: service.php exited non-zero (continuing)"
docker compose exec -T ng nginx -s reload 2>/dev/null || true

echo "[node-repair] done"
