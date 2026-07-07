#!/bin/sh
# Ad-hoc child-node transport diagnostics.
cd /root/vpnbot_extra || exit 1

echo "=== service status ==="
docker compose ps --format '{{.Name}} {{.Status}}' 2>/dev/null

echo "=== xray process ==="
docker compose exec -T xr sh -c 'pgrep -a xray || echo NO_XRAY_PROCESS'

echo "=== xray listen (8080 api / inbounds) ==="
docker compose exec -T xr sh -c 'ss -tlnp 2>/dev/null | grep -E ":8080|:443|:2053" || netstat -tlnp 2>/dev/null | grep -E ":8080|:443|:2053" || echo NO_LISTENERS'

echo "=== hysteria process ==="
docker compose exec -T hy sh -c 'pgrep -a hysteria || echo NO_HY_PROCESS'

echo "=== nginx upstream to xray (443 sni) ==="
docker compose exec -T up sh -c 'pgrep -a nginx | head -3'

echo "=== last service run marker ==="
ls -la /start 2>/dev/null || echo NO_START_MARKER

echo "=== re-run service.php (bounded) ==="
timeout 60 docker compose exec -T php php service.php 2>&1 | tail -20 || echo "service.php exited $?"

echo "=== xray process after service ==="
docker compose exec -T xr sh -c 'pgrep -a xray || echo NO_XRAY_PROCESS'

echo "DIAG_DONE"
