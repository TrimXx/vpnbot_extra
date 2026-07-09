#!/bin/sh
# Child/parent transport diagnostics.
cd /root/vpnbot_extra || exit 1

IP=$(hostname -I | awk '{print $1}')
export IP

echo "=== host / role ==="
echo "IP=$IP"
jq -r '"node_role=" + (.node_role // "parent") + " domain=" + (.domain // "")' config/pac.json 2>/dev/null || echo 'pac.json unreadable'

echo "=== service status ==="
IP="$IP" docker compose ps --format '{{.Name}} {{.Status}}' 2>/dev/null

echo "=== xray process ==="
IP="$IP" docker compose exec -T xr sh -c 'pgrep -a xray || echo NO_XRAY_PROCESS'

echo "=== xray listen (8080 api / inbounds) ==="
IP="$IP" docker compose exec -T xr sh -c 'ss -tlnp 2>/dev/null | grep -E ":8080|:443|:2053|:33443|:8443" || netstat -tlnp 2>/dev/null | grep -E ":8080|:443|:2053|:33443|:8443" || echo NO_LISTENERS'

echo "=== hysteria process ==="
IP="$IP" docker compose exec -T hy sh -c 'pgrep -a hysteria || echo NO_HY_PROCESS'

echo "=== nginx upstream to xray (443 sni) ==="
IP="$IP" docker compose exec -T up sh -c 'pgrep -a nginx | head -3'

echo "=== last service run marker ==="
ls -la /start 2>/dev/null || echo NO_START_MARKER

echo "=== LoggingTrait present ==="
ls -la app/traits/LoggingTrait.php 2>/dev/null || echo MISSING_LoggingTrait

echo "=== re-run service.php (bounded) ==="
timeout 60 IP="$IP" docker compose exec -T php php service.php 2>&1 | tail -20 || echo "service.php exited $?"

echo "=== xray process after service ==="
IP="$IP" docker compose exec -T xr sh -c 'pgrep -a xray || echo NO_XRAY_PROCESS'

echo "DIAG_DONE"
