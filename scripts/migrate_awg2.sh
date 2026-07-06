#!/bin/sh
# Migrate pac.json from AWG 1.x to AWG 2.0.
set -eu

PAC="${1:-/config/pac.json}"
if [ ! -f "$PAC" ]; then
    PAC="./config/pac.json"
fi
if [ ! -f "$PAC" ]; then
    echo "pac.json not found: $PAC" >&2
    exit 1
fi

if ! command -v jq >/dev/null 2>&1; then
    echo "jq is required" >&2
    exit 1
fi

BACKUP="${PAC}.bak.awg2.$(date +%Y%m%d%H%M%S)"
cp "$PAC" "$BACKUP"
echo "backup: $BACKUP"

jq '
  .wg1_amnezia = 1
  | del(.wg1_amnezia_keys)
  | del(.wg1_presharedkey)
' "$PAC" > "${PAC}.tmp"
mv "${PAC}.tmp" "$PAC"
echo "pac.json: wg1_amnezia=1, cleared legacy AWG 1.x keys/psk (regenerated on service start)"
