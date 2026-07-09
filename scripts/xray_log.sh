#!/bin/sh
set -eu
cd /root/vpnbot_extra
MODE="${1:-on}"
if [ "$MODE" = "on" ]; then
  jq '.log = {access:"/logs/xray_access", error:"/logs/xray_error", loglevel:"debug"}' config/xray.json > /tmp/xr.json
else
  jq '.log = {access:"none", error:"/logs/xray_error", loglevel:"error"}' config/xray.json > /tmp/xr.json
fi
cat /tmp/xr.json > config/xray.json
docker compose exec -T xr sh -c 'pkill xray; sleep 1; xray run -config /xray.json > /dev/null 2>&1 &'
sleep 2
: > logs/xray_access
: > logs/xray_error
echo "xray log=$MODE, container sees:"
docker exec xr grep -E 'access|loglevel' /xray.json | head -3
echo "ports:"
docker exec xr netstat -tlnp 2>/dev/null | grep -E ':443|:33443' || true
