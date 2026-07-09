#!/bin/sh
set -eu
cd /root/vpnbot_extra
MODE="${1:-auto}"
jq --arg m "$MODE" '(.inbounds[] | select(.tag=="vless_xhttp") | .streamSettings.xhttpSettings.mode) = $m' config/xray.json > /tmp/xr2.json
cat /tmp/xr2.json > config/xray.json
docker compose exec -T xr sh -c 'pkill xray; sleep 1; xray run -config /xray.json > /dev/null 2>&1 &'
sleep 2
: > logs/xray_access
: > logs/xray_error
echo "xhttp mode set to $MODE"
docker exec xr grep -A1 xhttpSettings /xray.json | head -4
