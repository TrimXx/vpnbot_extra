#!/bin/sh
# Copy for php container (./app is mounted). Delegates to repo scripts/.
set -eu
ROOT="${ROOT:-/root/vpnbot_extra}"
if [ -x "$ROOT/scripts/node_remote_update.sh" ]; then
  exec sh "$ROOT/scripts/node_remote_update.sh" "$@"
fi
if [ -x "/scripts/node_remote_update.sh" ]; then
  exec sh "/scripts/node_remote_update.sh" "$@"
fi
echo "[node-update] script not found" >&2
exit 1
