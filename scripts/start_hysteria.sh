#!/bin/sh
set -eu

cat /ssh/key.pub > /root/.ssh/authorized_keys
ssh-keygen -A
exec /usr/sbin/sshd -D -e "$@" &

if ! command -v jq >/dev/null 2>&1; then
    apk add --no-cache jq >/dev/null 2>&1 || true
fi

if [ ! -f /config/pac.json ] && [ ! -f /pac.json ]; then
    tail -f /dev/null
    exit 0
fi

PAC_FILE=/config/pac.json
if [ ! -f "$PAC_FILE" ]; then
    PAC_FILE=/pac.json
fi

enabled="$(jq -r '.transport_registry.global.hysteria // .hysteria // 0' "$PAC_FILE")"
password="$(jq -r '.hysteria_pass // empty' "$PAC_FILE")"
if [ "$enabled" != "1" ] || [ -z "$password" ]; then
    tail -f /dev/null
    exit 0
fi

if [ ! -f /config/hysteria.yaml ]; then
    tail -f /dev/null
    exit 0
fi

exec hysteria server -c /config/hysteria.yaml
