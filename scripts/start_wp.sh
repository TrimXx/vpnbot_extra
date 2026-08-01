#!/bin/bash
set -e

cat /ssh/key.pub > /root/.ssh/authorized_keys
ssh-keygen -A
/usr/sbin/sshd

WGCF_DIR="/etc/warp"
WGCF_CONF="$WGCF_DIR/wgcf-profile.conf"
WGCF_TOML="$WGCF_DIR/wgcf-account.toml"
# Keep legacy socks port so existing xray outbound (10.10.0.13:4000) stays valid.
SOCKS_PORT="${SOCKS_PORT:-4000}"

off=$(jq -r '.warpoff // empty' /config/pac.json 2>/dev/null || true)
WARP_LICENSE_KEY=$(jq -r '.warp // empty' /config/pac.json 2>/dev/null || true)

mkdir -p "$WGCF_DIR"
cd "$WGCF_DIR"

start_warp() {
    if [ ! -f "$WGCF_CONF" ]; then
        echo "[warp] Registering WARP account..."
        wgcf register --accept-tos
        if [ -n "$WARP_LICENSE_KEY" ]; then
            echo "[warp] Applying license key..."
            if grep -q '^license_key' "$WGCF_TOML"; then
                sed -i "s/^license_key.*/license_key = \"$WARP_LICENSE_KEY\"/" "$WGCF_TOML"
            fi
            wgcf update
        fi
        echo "[warp] Generating WireGuard profile..."
        wgcf generate
        echo "[warp] Stripping IPv6 from profile..."
        sed -i '/^Address.*:/d' "$WGCF_CONF"
        sed -i '/^AllowedIPs.*::/d' "$WGCF_CONF"
    fi

    echo "[warp] Starting WireGuard (WARP)..."
    set +e
    out=$(wg-quick up "$WGCF_CONF" 2>&1)
    ec=$?
    set -e
    echo "$out" | grep -v "skip sysctl" || true
    if [ "$ec" -ne 0 ] && ! wg show >/dev/null 2>&1; then
        echo "[warp] wg-quick failed" >&2
        exit 1
    fi

    echo "[warp] Starting microsocks on port $SOCKS_PORT..."
    microsocks -p "$SOCKS_PORT" &
}

if [ -z "$off" ]; then
    start_warp
else
    echo "[warp] warpoff set — tunnel not started"
fi

# Keep container alive for SSH / bot toggle (do not exec microsocks as PID 1).
sleep infinity
