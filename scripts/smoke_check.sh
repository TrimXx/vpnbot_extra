#!/bin/sh
# Post-deploy smoke checks. Run on host: bash scripts/smoke_check.sh
# Or in php container: docker compose exec php sh /app/../scripts/smoke_check.sh
set -eu

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
CONFIG="$ROOT/config"
FAILED=0

fail() {
    echo "[smoke] FAIL: $1" >&2
    FAILED=1
}

pass() {
    echo "[smoke] OK: $1"
}

# --- nginx template sanity ---
xhttp_enabled=1
if [ -f "$CONFIG/pac.json" ] && command -v python3 >/dev/null 2>&1; then
    xhttp_enabled=$(python3 - <<'PY' "$CONFIG/pac.json" 2>/dev/null || echo 1
import json, sys
with open(sys.argv[1]) as f:
    p = json.load(f)
g = (p.get("transport_registry") or {}).get("global") or {}
print(1 if g.get("xhttp") else 0)
PY
)
fi

for f in nginx_default.conf nginx.conf; do
    if [ ! -f "$CONFIG/$f" ]; then
        fail "missing $CONFIG/$f"
        continue
    fi
    if ! grep -q 'location /ws' "$CONFIG/$f"; then
        fail "$f: no /ws location"
    else
        pass "$f has /ws location"
    fi
    require_xh=1
    if [ "$f" = "nginx.conf" ] && [ "$xhttp_enabled" != "1" ]; then
        require_xh=0
        echo "[smoke] SKIP: $f /xh check (xhttp transport disabled)"
    fi
    if [ "$require_xh" = 1 ]; then
        if ! grep -q 'location /xh' "$CONFIG/$f"; then
            fail "$f: no /xh location"
        else
            pass "$f has /xh location"
        fi
    fi
done

# --- xray.json structure ---
if [ ! -f "$CONFIG/xray.json" ]; then
    fail "missing xray.json"
else
  if command -v python3 >/dev/null 2>&1; then
    python3 - <<'PY' "$CONFIG/xray.json" || fail "xray.json invalid JSON"
import json, sys
with open(sys.argv[1]) as f:
    data = json.load(f)
inbounds = data.get("inbounds") or []
tags = [i.get("tag") for i in inbounds if isinstance(i, dict)]
if not tags:
    raise SystemExit("no inbounds")
print("inbounds:", ", ".join(str(t) for t in tags if t))
PY
    pass "xray.json is valid JSON"
  else
    pass "xray.json exists (install python3 for JSON validation)"
  fi
fi

# --- transport path separation in template ---
if [ -f "$CONFIG/nginx_default.conf" ]; then
    if grep -q 'xr:8443' "$CONFIG/nginx_default.conf"; then
        pass "nginx_default.conf proxies /xh to xr:8443"
    else
        fail "nginx_default.conf missing xr:8443 upstream for xhttp"
    fi
fi

# --- nginx -t inside ng container (if running) ---
if docker compose -f "$ROOT/docker-compose.yml" ps --status running ng 2>/dev/null | grep -q ng; then
    if docker compose -f "$ROOT/docker-compose.yml" exec -T ng nginx -t >/dev/null 2>&1; then
        pass "nginx -t in ng container"
    else
        fail "nginx -t failed in ng container"
    fi
else
    echo "[smoke] SKIP: ng container not running"
fi

# --- php traits ---
for trait in SubscriptionSecurityTrait.php TransportRegistryTrait.php PacUrlTrait.php TransportRuntimeTrait.php BotCacheTrait.php HwidTrait.php LegacyRemovedTrait.php; do
    if [ -f "$ROOT/app/traits/$trait" ]; then
        pass "trait $trait exists"
    else
        fail "missing app/traits/$trait"
    fi
done

# --- php syntax ---
if command -v php >/dev/null 2>&1; then
    if php -l "$ROOT/app/bot.php" >/dev/null 2>&1; then
        pass "php -l app/bot.php"
    else
        fail "php -l app/bot.php"
    fi
    if php -l "$ROOT/app/index.php" >/dev/null 2>&1; then
        pass "php -l app/index.php"
    else
        fail "php -l app/index.php"
    fi
elif docker compose -f "$ROOT/docker-compose.yml" ps --status running php 2>/dev/null | grep -q php; then
    if docker compose -f "$ROOT/docker-compose.yml" exec -T php php -l /app/bot.php >/dev/null 2>&1; then
        pass "php -l app/bot.php (in container)"
    else
        fail "php -l app/bot.php (in container)"
    fi
else
    echo "[smoke] SKIP: php not available"
fi

if [ "$FAILED" -eq 0 ]; then
    echo "[smoke] all checks passed"
    exit 0
fi

echo "[smoke] some checks failed"
exit 1
