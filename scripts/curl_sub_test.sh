#!/bin/sh
SUB='https://test.trimx.ru/pac7bab2561/eyJ2IjoyLCJkIjp7ImgiOiI3YmFiMjU2MSIsInQiOiJjbCIsInMiOiJmYzkxNWU5My03NjRlLTRiMmEtYTRlNy04OWY1MWUxNDdlNTAifSwicyI6IjI5ZjI0MGZkMGUzMDZmNmY2NzAyYTQxYjRkYTliNGUwZTJjMTJhNTQ4MDBlYTA1YzVkMDdhYjljYzliM2M2ZmEifQ'
RULE='https://test.trimx.ru/pac7bab2561/eyJ2IjoyLCJkIjp7ImgiOiI3YmFiMjU2MSIsInQiOiJjbCIsInMiOiJmYzkxNWU5My03NjRlLTRiMmEtYTRlNy04OWY1MWUxNDdlNTAiLCJyIjoicGFja2FnZSJ9LCJzIjoiOWM3ODU2NTJmZmQ5NDM1NWE1MmUzM2U0NzY3M2NmOTNiZTZkMjUzMTJiMWI3ODI1OTRkNzc3YTY3ZDlkMjBkMCJ9'
HWID='6aa432aa-64af-46a4-8d36-59fcacb6b7de'
UA='ClashMeta'

test_url() {
  name="$1"
  url="$2"
  extra="$3"
  echo "=== $name ==="
  if [ -n "$extra" ]; then
    code=$(curl -sk -o /tmp/curl_body -w '%{http_code}' -A "$UA" -H "$extra" "$url")
  else
    code=$(curl -sk -o /tmp/curl_body -w '%{http_code}' -A "$UA" "$url")
  fi
  size=$(wc -c </tmp/curl_body)
  head -c 120 /tmp/curl_body | tr '\n' ' '
  echo
  echo "HTTP $code size=$size"
}

test_url 'SUB no HWID' "$SUB" ''
test_url 'SUB with HWID' "$SUB" "X-HWID: $HWID"
test_url 'RULE package no HWID' "$RULE" ''
test_url 'RULE package with HWID' "$RULE" "X-HWID: $HWID"

echo '=== SUB with HWID: proxies snippet ==='
curl -sk -A "$UA" -H "X-HWID: $HWID" "$SUB" | grep -E 'global-client-fingerprint|client-fingerprint|network:|type: vless|type: hysteria|type: wireguard|name:' | head -40
