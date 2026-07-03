#!/bin/sh
# Quick bot/webhook diagnostics (run on the server in repo root).
set -eu

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

echo "=== git ==="
git rev-parse --short HEAD 2>/dev/null || echo "no git"
grep -n "vpnbot_trace\|before action" app/index.php app/timezone.php app/bot.php 2>/dev/null | head -5 || true

echo ""
echo "=== containers ==="
docker compose ps
echo ""
echo "=== upstream (host :443) ==="
UP_STATUS="$(docker compose ps -a --format '{{.Name}} {{.Status}}' 2>/dev/null | grep '^up ' || true)"
if [ -n "$UP_STATUS" ]; then
    echo "$UP_STATUS"
else
    echo "WARNING: container up is not running — Telegram webhook needs host :443 (Connection refused)"
fi
if command -v nc >/dev/null 2>&1; then
    if nc -z 127.0.0.1 443 2>/dev/null; then
        echo "host :443 TCP open"
    else
        echo "host :443 TCP CLOSED — external HTTPS webhooks will fail"
    fi
else
    echo "nc not installed, skip host :443 probe"
fi
docker compose exec -T up sh -c 'tail -15 /logs/upstream_error 2>/dev/null' 2>/dev/null || echo "cannot read upstream_error (up down?)"
docker compose exec -T ng sh -c 'nc -z 10.10.0.2 443 && echo ng:443 listening || echo ng:443 NOT listening' 2>/dev/null || echo "cannot probe ng:443"

echo ""
echo "=== /logs in php ==="
docker compose exec -T php sh -c 'ls -la /logs/ 2>/dev/null || echo "no /logs"'

echo ""
echo "=== webhook log ==="
docker compose exec -T php sh -c 'tail -20 /logs/webhook 2>/dev/null || echo "no /logs/webhook"'

echo ""
echo "=== unit_error ==="
docker compose exec -T php sh -c 'tail -20 /logs/unit_error 2>/dev/null || echo "no unit_error"'

echo ""
echo "=== php_error ==="
docker compose exec -T php sh -c 'tail -20 /logs/php_error 2>/dev/null || echo "no php_error"'

echo ""
echo "=== requests_error ==="
docker compose exec -T php sh -c 'tail -10 /logs/requests_error 2>/dev/null || echo "no requests_error"'

echo ""
echo "=== Telegram getWebhookInfo ==="
docker compose exec -T php php -r '
require "/app/config.php";
$ch = curl_init("https://api.telegram.org/bot{$c["key"]}/getWebhookInfo");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
$res = curl_exec($ch);
curl_close($ch);
$j = json_decode($res, true);
$r = $j["result"] ?? [];
echo "url: " . ($r["url"] ?? "-") . "\n";
echo "pending: " . ($r["pending_update_count"] ?? "?") . "\n";
echo "last_error_date: " . ($r["last_error_date"] ?? "-") . "\n";
echo "last_error_message: " . ($r["last_error_message"] ?? "-") . "\n";
echo "max_connections: " . ($r["max_connections"] ?? "-") . "\n";
$err = $r["last_error_message"] ?? "";
if (stripos($err, "connection refused") !== false) {
    echo "hint: fix container up (publishes host :443). Internal http://php/tlgrm can work while Telegram cannot.\n";
}
'

KEY="$(docker compose exec -T php php -r 'require "/app/config.php"; echo $c["key"];')"
ADMIN="$(docker compose exec -T php php -r 'require "/app/config.php"; echo is_array($c["admin"]) ? $c["admin"][0] : $c["admin"];')"
BODY="{\"update_id\":999001,\"message\":{\"message_id\":1,\"from\":{\"id\":$ADMIN,\"username\":\"diag\"},\"chat\":{\"id\":$ADMIN,\"type\":\"private\"},\"text\":\"/id\"}}"

echo ""
echo "=== HTTPS POST /tlgrm via host :443 ==="
if command -v curl >/dev/null 2>&1 && nc -z 127.0.0.1 443 2>/dev/null; then
    CODE="$(curl -k -sS -o /dev/null -w '%{http_code}' -X POST \
        -H 'Content-Type: application/json' \
        --data "$BODY" \
        "https://127.0.0.1/tlgrm?k=$KEY" 2>/dev/null || echo fail)"
    echo "http_code: $CODE (expect 200; this is the path Telegram uses)"
else
    echo "skipped (curl missing or host :443 closed)"
fi

echo ""
echo "=== local POST /tlgrm (synthetic /id, bypasses :443) ==="
docker compose exec -T ng wget -qO- \
  --header='Content-Type: application/json' \
  --post-data="$BODY" \
  "http://php/tlgrm?k=$KEY" >/dev/null 2>&1 || echo "wget failed"
sleep 1
echo "--- webhook tail after synthetic request ---"
docker compose exec -T php sh -c 'tail -5 /logs/webhook 2>/dev/null || echo "still no /logs/webhook"'
