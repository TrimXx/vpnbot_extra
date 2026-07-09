#!/bin/sh
cd /root/vpnbot_extra
IP="$(hostname -I | awk '{print $1}')"
echo "IP=$IP"
IP="$IP" VER="${VER:-}" docker compose --env-file ./.env --env-file ./override.env up -d php xr > /tmp/recreate_xr.log 2>&1
echo "exit=$?"
sleep 4
docker compose ps php xr
