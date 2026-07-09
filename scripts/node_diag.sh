#!/bin/sh
cd /root/vpnbot_extra
echo "=== node VDSINA ==="
jq '.nodes[] | select(.name=="VDSINA" or .domain=="nltest.trimx.ru")' config/pac.json
echo "=== DNS ==="
getent hosts nltest.trimx.ru || true
echo "=== TCP 443 ==="
nc -z -w5 nltest.trimx.ru 443 && echo OPEN || echo CLOSED
echo "=== TCP 80 ==="
nc -z -w5 nltest.trimx.ru 80 && echo OPEN || echo CLOSED
echo "=== curl ==="
curl -sS -o /dev/null -w "https code=%{http_code} time=%{time_total}s\n" --max-time 8 https://nltest.trimx.ru/ 2>&1 || echo curl_fail
echo "=== parent IP ==="
hostname -I | awk '{print $1}'
