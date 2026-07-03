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
'

echo ""
echo "=== local POST /tlgrm (synthetic /id) ==="
KEY="$(docker compose exec -T php php -r 'require "/app/config.php"; echo $c["key"];')"
ADMIN="$(docker compose exec -T php php -r 'require "/app/config.php"; echo is_array($c["admin"]) ? $c["admin"][0] : $c["admin"];')"
BODY="{\"update_id\":999001,\"message\":{\"message_id\":1,\"from\":{\"id\":$ADMIN,\"username\":\"diag\"},\"chat\":{\"id\":$ADMIN,\"type\":\"private\"},\"text\":\"/id\"}}"
docker compose exec -T ng wget -qO- \
  --header='Content-Type: application/json' \
  --post-data="$BODY" \
  "http://php/tlgrm?k=$KEY" >/dev/null 2>&1 || echo "wget failed"
sleep 1
echo "--- webhook tail after synthetic request ---"
docker compose exec -T php sh -c 'tail -5 /logs/webhook 2>/dev/null || echo "still no /logs/webhook"'
