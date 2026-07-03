#!/bin/sh
set -eu

cat /ssh/key.pub > /root/.ssh/authorized_keys
ssh-keygen -A
exec /usr/sbin/sshd -D -e "$@" &

if [ ! -f /pac.json ]; then
    tail -f /dev/null
    exit 0
fi

enabled="$(jq -r '.transport_registry.global.hysteria // .hysteria // 0' /pac.json)"
password="$(jq -r '.hysteria_pass // empty' /pac.json)"
if [ "$enabled" != "1" ] || [ -z "$password" ]; then
    tail -f /dev/null
    exit 0
fi

if [ ! -f /config/hysteria.yaml ]; then
    tail -f /dev/null
    exit 0
fi

exec hysteria server -c /config/hysteria.yaml
