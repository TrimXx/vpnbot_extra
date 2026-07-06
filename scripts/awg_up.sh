#!/bin/sh
# Bring up AmneziaWG 2.0. In Docker always use userspace: the host may load
# amneziawg kernel module so awg-quick skips userspace fallback while
# "ip link add type amneziawg" still fails inside the container netns.
set -eu

INTERFACE="${1:-wg0}"
CONFIG="/etc/amnezia/amneziawg/${INTERFACE}.conf"
if [ ! -f "$CONFIG" ]; then
    CONFIG="/etc/wireguard/${INTERFACE}.conf"
fi
if [ ! -f "$CONFIG" ]; then
    echo "awg_up: config not found for $INTERFACE" >&2
    exit 1
fi

awg_down() {
    awg-quick down "$INTERFACE" 2>/dev/null || true
    ip link del "$INTERFACE" 2>/dev/null || true
    if command -v pgrep >/dev/null 2>&1; then
        pkill -f "amneziawg-go.*${INTERFACE}" 2>/dev/null || true
    fi
}

use_userspace() {
    [ -f /.dockerenv ] || [ "${AWG_FORCE_USERSPACE:-1}" = "1" ]
}

awg_down

if use_userspace; then
    if ! command -v amneziawg-go >/dev/null 2>&1; then
        echo "awg_up: amneziawg-go not found" >&2
        exit 1
    fi
    WG_PROCESS_FOREGROUND=1 amneziawg-go -f "$INTERFACE" &
    go_pid=$!
    sleep 1
    if ! kill -0 "$go_pid" 2>/dev/null; then
        echo "awg_up: amneziawg-go failed to start" >&2
        exit 1
    fi
    awg-quick strip "$INTERFACE" | awg setconf "$INTERFACE" /dev/stdin
    while IFS= read -r line; do
        key="${line%%=*}"
        key="$(echo "$key" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')"
        value="${line#*=}"
        value="$(echo "$value" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')"
        case "$key" in
            Address)
                for addr in $(echo "$value" | tr ',' ' '); do
                    addr="$(echo "$addr" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')"
                    [ -z "$addr" ] && continue
                    if echo "$addr" | grep -q ':'; then
                        ip -6 address add "$addr" dev "$INTERFACE" 2>/dev/null || true
                    else
                        ip -4 address add "$addr" dev "$INTERFACE" 2>/dev/null || true
                    fi
                done
                ;;
            MTU)
                ip link set mtu "$value" dev "$INTERFACE" 2>/dev/null || true
                ;;
        esac
    done <<EOF
$(grep -E '^(Address|MTU)[[:space:]]*=' "$CONFIG" || true)
EOF
    ip link set up dev "$INTERFACE"
else
    awg-quick up "$INTERFACE"
fi
