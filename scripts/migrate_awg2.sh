#!/bin/bash
set -euo pipefail

PAC="/config/pac.json"
BACKUP="/config/pac.json.bak.awg2.$(date +%Y%m%d%H%M%S)"

if [ ! -f "$PAC" ]; then
    echo "pac.json not found: $PAC" >&2
    exit 1
fi

cp "$PAC" "$BACKUP"
echo "backup: $BACKUP"

jq 'del(.wg1_amnezia_keys)' "$PAC" > "${PAC}.tmp"
mv "${PAC}.tmp" "$PAC"
echo "removed wg1_amnezia_keys from pac.json"
