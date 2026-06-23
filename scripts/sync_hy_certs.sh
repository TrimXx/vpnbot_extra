#!/usr/bin/env sh
# Copy vpnbot TLS certs to a remote sing-box (Hysteria2) server and restart sing-box.
#
# Usage (on vpnbot host):
#   sh scripts/sync_hy_certs.sh
#
# One SSH session = one password prompt (unless you use ssh keys).
# Optional: ssh-copy-id root@HY_IP  — then no password at all.

set -eu

# --- configure ---
HY_HOST="root@1.2.3.4"
HY_CERT_DIR="/etc/sing-box"
HY_SERVICE="sing-box"

VPNBOT_DIR="${VPNBOT_DIR:-$(cd "$(dirname "$0")/.." && pwd)}"
SRC_PUBLIC="${VPNBOT_DIR}/certs/cert_public"
SRC_PRIVATE="${VPNBOT_DIR}/certs/cert_private"

DST_PUBLIC="${HY_CERT_DIR}/fullchain.pem"
DST_PRIVATE="${HY_CERT_DIR}/privkey.pem"
# --- end configure ---

if [ ! -f "$SRC_PUBLIC" ] || [ ! -f "$SRC_PRIVATE" ]; then
    echo "ERROR: missing certs in ${VPNBOT_DIR}/certs/"
    echo "  need: cert_public, cert_private"
    exit 1
fi

echo "Source:  ${SRC_PUBLIC}"
echo "         ${SRC_PRIVATE}"
echo "Target:  ${HY_HOST}:${HY_CERT_DIR}/"
echo "One SSH connection (one password if no key)."
echo

tar cf - -C "${VPNBOT_DIR}/certs" cert_public cert_private | ssh "$HY_HOST" "set -eu
    CERT_DIR='${HY_CERT_DIR}'
    DST_PUBLIC='${DST_PUBLIC}'
    DST_PRIVATE='${DST_PRIVATE}'
    HY_SERVICE='${HY_SERVICE}'
    TS=\$(date +%Y%m%d%H%M%S)

    mkdir -p \"\${CERT_DIR}\"
    [ -f \"\${DST_PUBLIC}\" ]  && cp -a \"\${DST_PUBLIC}\"  \"\${DST_PUBLIC}.bak.\${TS}\"  || true
    [ -f \"\${DST_PRIVATE}\" ] && cp -a \"\${DST_PRIVATE}\" \"\${DST_PRIVATE}.bak.\${TS}\" || true

    TMP=\"\$(mktemp -d)\"
    tar xf - -C \"\${TMP}\"
    mv -f \"\${TMP}/cert_public\"  \"\${DST_PUBLIC}\"
    mv -f \"\${TMP}/cert_private\" \"\${DST_PRIVATE}\"
    rmdir \"\${TMP}\"

    chmod 644 \"\${DST_PUBLIC}\"
    chmod 600 \"\${DST_PRIVATE}\"

    if command -v sing-box >/dev/null 2>&1 && [ -f \"\${CERT_DIR}/config.json\" ]; then
        sing-box check -c \"\${CERT_DIR}/config.json\"
    fi
    if command -v systemctl >/dev/null 2>&1 && systemctl is-active --quiet \"\${HY_SERVICE}\"; then
        systemctl restart \"\${HY_SERVICE}\"
        echo \"Restarted \${HY_SERVICE}\"
    elif command -v systemctl >/dev/null 2>&1 && systemctl list-unit-files \"\${HY_SERVICE}.service\" 2>/dev/null | grep -q \"\${HY_SERVICE}\"; then
        systemctl start \"\${HY_SERVICE}\"
        echo \"Started \${HY_SERVICE}\"
    else
        echo \"WARN: \${HY_SERVICE} not found — restart sing-box manually\"
    fi
    openssl x509 -in \"\${DST_PUBLIC}\" -noout -subject -dates 2>/dev/null || true
"

echo "Done."
