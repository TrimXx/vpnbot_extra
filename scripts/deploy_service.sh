#!/bin/sh
set -eu
cd /root/vpnbot_extra
git fetch origin v2 --quiet
git reset --hard origin/v2
IP="$(hostname -I | awk '{print $1}')"
echo "IP=$IP"
IP="$IP" docker compose --env-file ./.env --env-file ./override.env exec -T php php /app/service.php 2>&1 | tail -6
echo "=== xhttp mode in xray ==="
grep -A2 '"mode"' config/xray.json | head -4
echo "=== ng healthcheck ==="
grep 'nc -z' docker-compose.yml
