#!/bin/bash
# vpnbot mirror — kernel DNAT forwarder to main server (dedicated VPS only).
# Usage: sudo bash mirror_iptables.sh {install|status|uninstall}
set -euo pipefail

TARGET_IP="~ip~"
WG1_PORT="~wg1~"
TG_PORT="~tg~"
SYSCTL_FILE="/etc/sysctl.d/99-vpnbot-mirror.conf"

need_root() {
    if [[ "${EUID}" -ne 0 ]]; then
        echo "Run as root: sudo bash $0 ${ACTION:-install}"
        exit 1
    fi
}

forward_port() {
    local port="$1"
    local proto="$2"

    iptables -t nat -A PREROUTING -p "$proto" --dport "$port" -j DNAT --to-destination "${TARGET_IP}:${port}"
    iptables -I FORWARD 1 -p "$proto" -d "$TARGET_IP" --dport "$port" -j ACCEPT
    iptables -t nat -A POSTROUTING -p "$proto" -d "$TARGET_IP" --dport "$port" -j MASQUERADE
}

clear_rules() {
    iptables -t nat -F
    iptables -F FORWARD
}

cmd_install() {
    echo "Target: ${TARGET_IP}"
    echo "WG1 UDP: ${WG1_PORT}, MTProto TCP: ${TG_PORT}"

    echo "net.ipv4.ip_forward=1" > "$SYSCTL_FILE"
    sysctl -p "$SYSCTL_FILE" >/dev/null

    clear_rules

    forward_port 80 tcp
    forward_port 443 tcp
    forward_port 443 udp
    forward_port 853 tcp
    forward_port 853 udp
    forward_port "$TG_PORT" tcp
    forward_port "$WG1_PORT" udp

    if command -v netfilter-persistent >/dev/null 2>&1; then
        netfilter-persistent save >/dev/null 2>&1 || true
    elif command -v iptables-save >/dev/null 2>&1 && [[ -d /etc/iptables ]]; then
        iptables-save > /etc/iptables/rules.v4
    fi

    echo "iptables mirror rules applied."
    cmd_status
}

cmd_status() {
    echo "ip_forward=$(sysctl -n net.ipv4.ip_forward 2>/dev/null || echo '?')"
    echo "NAT PREROUTING:"
    iptables -t nat -S PREROUTING 2>/dev/null | grep "DNAT" || echo "  (none)"
    echo "FORWARD:"
    iptables -S FORWARD 2>/dev/null | grep "$TARGET_IP" || echo "  (none)"
}

cmd_uninstall() {
    clear_rules
    rm -f "$SYSCTL_FILE"
    echo "Mirror iptables rules removed."
}

ACTION="${1:-install}"
need_root
case "$ACTION" in
    install) cmd_install ;;
    status) cmd_status ;;
    uninstall) cmd_uninstall ;;
    *)
        echo "Usage: sudo bash $0 {install|status|uninstall}"
        exit 1
        ;;
esac
