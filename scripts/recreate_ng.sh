#!/bin/sh
cd /root/vpnbot_extra
IP="$(hostname -I | awk '{print $1}')"
IP="$IP" docker compose --env-file ./.env --env-file ./override.env up -d --force-recreate ng
echo done
