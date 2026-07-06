#!/bin/sh
# Remote app update for child nodes (triggered from parent via node-update API).
set -eu

branch="${1:-v2}"
if [ -d /repo/.git ]; then
  APP_DIR="/repo"
else
  APP_DIR="${APP_DIR:-$(cd "$(dirname "$0")/.." && pwd)}"
fi
cd "$APP_DIR"

echo "[node-update] branch=$branch dir=$APP_DIR"

git fetch origin "$branch" --quiet
git reset --hard "origin/$branch"

bash ./scripts/bootstrap_config.sh

VER="$(awk 'NR==1{for(i=1;i<=NF;i++) if($i ~ /^v[0-9]/){print $i; exit}}' version)"
VER="${VER:-local}"
IP="$(hostname -I | awk '{print $1}')"

IP="$IP" VER="$VER" docker compose --env-file ./.env --env-file ./override.env up -d --force-recreate

docker compose exec -T php php service.php
docker compose exec -T ng nginx -s reload 2>/dev/null || true

echo "[node-update] done"
