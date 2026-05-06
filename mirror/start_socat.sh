#!/usr/bin/env bash
set -euo pipefail

# =========================
# vpnbot mirror (one-click)
# =========================
# Usage:
#   sudo bash socat.sh install
#   sudo bash socat.sh status
#   sudo bash socat.sh restart
#   sudo bash socat.sh logs
#   sudo bash socat.sh uninstall
#
# Notes:
# - TARGET is your main vpnbot server IP/domain.
# - UDP bridge is required for Amnezia/WireGuard.
# - Script creates systemd services per port/protocol.

# --------- Variables (edit if needed) ----------
TARGET="~ip~"
TCP_PORTS=(80 443 853 ~tg~ ~ss~)
UDP_PORTS=(443 853 ~wg1~ ~wg2~)
SERVICE_PREFIX="vpnbot-mirror"
# -----------------------------------------------

ACTION="${1:-install}"
CONFIG_DIR="/etc/${SERVICE_PREFIX}"
SYSTEMD_DIR="/etc/systemd/system"
TCP_PORTS_FILE="${CONFIG_DIR}/ports.tcp"
UDP_PORTS_FILE="${CONFIG_DIR}/ports.udp"
ENV_FILE="${CONFIG_DIR}/mirror.env"

need_root() {
  if [[ "${EUID}" -ne 0 ]]; then
    echo "Run as root: sudo bash $0 ${ACTION}"
    exit 1
  fi
}

dedup_ports() {
  local -n arr_ref="$1"
  local out=()
  local seen=""
  local p
  for p in "${arr_ref[@]}"; do
    [[ -z "${p}" ]] && continue
    [[ "${p}" =~ ^[0-9]+$ ]] || continue
    ((p >= 1 && p <= 65535)) || continue
    if [[ " ${seen} " != *" ${p} "* ]]; then
      out+=("${p}")
      seen="${seen} ${p}"
    fi
  done
  arr_ref=("${out[@]}")
}

install_socat() {
  if command -v socat >/dev/null 2>&1; then
    return
  fi
  echo "Installing socat..."
  if command -v apt-get >/dev/null 2>&1; then
    apt-get update -y
    apt-get install -y socat
  elif command -v dnf >/dev/null 2>&1; then
    dnf install -y socat
  elif command -v yum >/dev/null 2>&1; then
    yum install -y socat
  else
    echo "Unsupported distro: install socat manually."
    exit 1
  fi
}

port_busy_by_other() {
  local port="$1"
  local proto="$2"
  local lines=""
  if [[ "$proto" == "tcp" ]]; then
    lines="$(ss -ltnp "sport = :${port}" 2>/dev/null | awk 'NR>1{print $0}' || true)"
  else
    lines="$(ss -lunp "sport = :${port}" 2>/dev/null | awk 'NR>1{print $0}' || true)"
  fi
  [[ -z "$lines" ]] && return 1

  local line
  while IFS= read -r line; do
    [[ -z "$line" ]] && continue
    if [[ "$line" == *"${SERVICE_PREFIX}"* ]] || [[ "$line" == *"socat"* ]]; then
      continue
    fi
    echo "$line"
    return 0
  done <<< "$lines"
  return 1
}

write_config() {
  mkdir -p "$CONFIG_DIR"
  cat > "$ENV_FILE" <<EOF
TARGET=${TARGET}
EOF
  printf "%s\n" "${TCP_PORTS[@]}" > "$TCP_PORTS_FILE"
  printf "%s\n" "${UDP_PORTS[@]}" > "$UDP_PORTS_FILE"
}

write_units() {
  cat > "${SYSTEMD_DIR}/${SERVICE_PREFIX}-tcp@.service" <<'EOF'
[Unit]
Description=vpnbot mirror TCP port %I
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
EnvironmentFile=/etc/vpnbot-mirror/mirror.env
ExecStart=/usr/bin/socat TCP4-LISTEN:%I,reuseaddr,fork,nodelay,keepalive TCP4:${TARGET}:%I
Restart=always
RestartSec=1
LimitNOFILE=1048576

[Install]
WantedBy=multi-user.target
EOF

  cat > "${SYSTEMD_DIR}/${SERVICE_PREFIX}-udp@.service" <<'EOF'
[Unit]
Description=vpnbot mirror UDP port %I
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
EnvironmentFile=/etc/vpnbot-mirror/mirror.env
ExecStart=/usr/bin/socat -T120 UDP4-LISTEN:%I,reuseaddr,fork UDP4:${TARGET}:%I
Restart=always
RestartSec=1
LimitNOFILE=1048576

[Install]
WantedBy=multi-user.target
EOF
}

enable_start_units() {
  systemctl daemon-reload
  local p
  while IFS= read -r p; do
    [[ -z "$p" ]] && continue
    if busy="$(port_busy_by_other "$p" tcp)"; then
      echo "TCP ${p} is busy by other process:"
      echo "  $busy"
      exit 1
    fi
    systemctl enable --now "${SERVICE_PREFIX}-tcp@${p}.service"
  done < "$TCP_PORTS_FILE"

  while IFS= read -r p; do
    [[ -z "$p" ]] && continue
    if busy="$(port_busy_by_other "$p" udp)"; then
      echo "UDP ${p} is busy by other process:"
      echo "  $busy"
      exit 1
    fi
    systemctl enable --now "${SERVICE_PREFIX}-udp@${p}.service"
  done < "$UDP_PORTS_FILE"
}

stop_disable_units() {
  local p
  if [[ -f "$TCP_PORTS_FILE" ]]; then
    while IFS= read -r p; do
      [[ -z "$p" ]] && continue
      systemctl disable --now "${SERVICE_PREFIX}-tcp@${p}.service" 2>/dev/null || true
    done < "$TCP_PORTS_FILE"
  fi
  if [[ -f "$UDP_PORTS_FILE" ]]; then
    while IFS= read -r p; do
      [[ -z "$p" ]] && continue
      systemctl disable --now "${SERVICE_PREFIX}-udp@${p}.service" 2>/dev/null || true
    done < "$UDP_PORTS_FILE"
  fi
}

cmd_install() {
  dedup_ports TCP_PORTS
  dedup_ports UDP_PORTS
  install_socat
  write_config
  write_units
  enable_start_units
  cmd_status
}

cmd_status() {
  echo "Target: ${TARGET}"
  echo "TCP ports: ${TCP_PORTS[*]}"
  echo "UDP ports: ${UDP_PORTS[*]}"
  echo
  echo "Services:"
  systemctl --no-pager --plain --type=service | awk -v p="${SERVICE_PREFIX}" '$1 ~ p {print $1, $3, $4}'
}

cmd_restart() {
  local p
  systemctl daemon-reload
  if [[ -f "$TCP_PORTS_FILE" ]]; then
    while IFS= read -r p; do
      [[ -z "$p" ]] && continue
      systemctl restart "${SERVICE_PREFIX}-tcp@${p}.service"
    done < "$TCP_PORTS_FILE"
  fi
  if [[ -f "$UDP_PORTS_FILE" ]]; then
    while IFS= read -r p; do
      [[ -z "$p" ]] && continue
      systemctl restart "${SERVICE_PREFIX}-udp@${p}.service"
    done < "$UDP_PORTS_FILE"
  fi
  cmd_status
}

cmd_logs() {
  journalctl -u "${SERVICE_PREFIX}-tcp@*" -u "${SERVICE_PREFIX}-udp@*" -n 200 --no-pager
}

cmd_uninstall() {
  stop_disable_units
  rm -f "${SYSTEMD_DIR}/${SERVICE_PREFIX}-tcp@.service" "${SYSTEMD_DIR}/${SERVICE_PREFIX}-udp@.service"
  rm -rf "$CONFIG_DIR"
  systemctl daemon-reload
  echo "Mirror services removed."
}

need_root
case "$ACTION" in
  install)   cmd_install ;;
  status)    cmd_status ;;
  restart)   cmd_restart ;;
  logs)      cmd_logs ;;
  uninstall) cmd_uninstall ;;
  *)
    echo "Unknown action: $ACTION"
    echo "Usage: sudo bash $0 {install|status|restart|logs|uninstall}"
    exit 1
    ;;
esac