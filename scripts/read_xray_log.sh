#!/bin/sh
cd /root/vpnbot_extra
echo "access total: $(wc -l < logs/xray_access)"
echo "non-api access: $(grep -vc 'api -> api' logs/xray_access || echo 0)"
grep -v 'api -> api' logs/xray_access | tail -15
echo "=== error reality-ish (last 20) ==="
grep -i reality logs/xray_error | tail -10
grep -i 'rejected\|invalid\|failed to' logs/xray_error | grep -v dokodemo | grep -v 8080 | tail -10
echo "=== upstream 33443 last 5 ==="
grep 33443 logs/upstream_error 2>/dev/null | tail -5
grep 33443 logs/upstream_access 2>/dev/null | tail -5
