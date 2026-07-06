#!/bin/sh
# Child node join script. Environment is injected by parent bootstrap endpoint.
set -eu

CHILD_DOMAIN="${1:-}"
if [ -z "$CHILD_DOMAIN" ]; then
  echo "[node] ERROR: child domain required as first argument" >&2
  echo "usage: curl ... | bash -s -- child.example.com" >&2
  exit 1
fi

for var in NODE_ID NODE_TOKEN PARENT_URL BOT_KEY PAC_HASH; do
  eval "val=\${$var:-}"
  if [ -z "$val" ]; then
    echo "[node] ERROR: missing $var" >&2
    exit 1
  fi
done

REPO_URL="${REPO_URL:-https://github.com/TrimXx/vpnbot_extra.git}"
REPO_BRANCH="${REPO_BRANCH:-v2}"
APP_DIR="${APP_DIR:-/root/vpnbot_extra}"

CHILD_DOMAIN="$(echo "$CHILD_DOMAIN" | sed -E 's#^[a-zA-Z]+://##; s#/.*$##')"

echo "[node] Installing dependencies..."
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq ca-certificates curl gnupg lsb-release make git iptables iproute2 jq openssl

if ! command -v docker >/dev/null 2>&1; then
  echo "[node] Docker not found, installing..."
  curl -fsSL https://get.docker.com -o /tmp/get-docker.sh
  sh /tmp/get-docker.sh
fi

if [ -d "$APP_DIR/.git" ]; then
  echo "[node] Existing install in $APP_DIR"
  cd "$APP_DIR"
  git remote set-url origin "$REPO_URL"
  git fetch origin "$REPO_BRANCH" --quiet
  git checkout "origin/$REPO_BRANCH" -- app update makefile version scripts/join_node.sh 2>/dev/null || true
else
  echo "[node] Fresh install to $APP_DIR"
  git clone "$REPO_URL" "$APP_DIR"
  cd "$APP_DIR"
  git checkout "$REPO_BRANCH" 2>/dev/null || git checkout -B "$REPO_BRANCH" "origin/$REPO_BRANCH"
fi

cat > ./app/config.php <<EOF
<?php

\$c = ['key' => '$BOT_KEY'];
EOF

bash "$APP_DIR/scripts/bootstrap_config.sh"

NODE_IP="$(hostname -I | awk '{print $1}')"
{
  echo "IP=$NODE_IP"
  echo "NODE_ROLE=child"
} >> "$APP_DIR/override.env"

PAC_FILE="$APP_DIR/config/pac.json"
if [ ! -f "$PAC_FILE" ]; then
  echo '{}' > "$PAC_FILE"
fi

TMP_PAC="$(mktemp)"
jq --arg domain "$CHILD_DOMAIN" \
   --arg node_id "$NODE_ID" \
   --arg parent "$PARENT_URL" \
   --arg token "$NODE_TOKEN" \
   '.node_role = "child"
    | .node_id = $node_id
    | .parent_url = $parent
    | .node_sync_token = $token
    | .domain = $domain
    | .child_adguard = (.child_adguard // 0)' \
   "$PAC_FILE" > "$TMP_PAC"
mv "$TMP_PAC" "$PAC_FILE"

echo "[node] Starting containers..."
make -C "$APP_DIR" u

REGISTER_BODY="$(jq -nc \
  --arg node_id "$NODE_ID" \
  --arg token "$NODE_TOKEN" \
  --arg domain "$CHILD_DOMAIN" \
  --arg ip "$NODE_IP" \
  '{node_id:$node_id, token:$token, domain:$domain, ip:$ip}')"

TS="$(date +%s)"
SIG="$(printf '%s\n%s' "$TS" "$REGISTER_BODY" | openssl dgst -sha256 -hmac "$NODE_TOKEN" | awk '{print $NF}')"

echo "[node] Registering with parent..."
curl -fsS -X POST \
  -H "Content-Type: application/json" \
  -H "X-Node-Sync-Timestamp: $TS" \
  -H "X-Node-Sync-Signature: $SIG" \
  -d "$REGISTER_BODY" \
  "${PARENT_URL}/pac${PAC_HASH}/node-register"

echo ""
echo "[node] Done. Child node $CHILD_DOMAIN joined cluster."
