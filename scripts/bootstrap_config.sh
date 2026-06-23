#!/bin/sh
# Create config/ from config-templates/ on first deploy.
# Never overwrites existing files (safe for upgrades and local live configs).
set -eu

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
CONFIG="$ROOT/config"
TEMPLATES="$ROOT/config-templates"

mkdir -p "$CONFIG"

if [ ! -f "$ROOT/.env" ]; then
    if [ -f "$ROOT/env.defaults" ]; then
        cp "$ROOT/env.defaults" "$ROOT/.env"
        echo "[bootstrap] created .env from env.defaults"
    else
        echo "[bootstrap] WARNING: env.defaults missing, create .env manually" >&2
    fi
fi

if [ ! -f "$ROOT/override.env" ]; then
    : > "$ROOT/override.env"
    echo "[bootstrap] created override.env"
fi

copy_if_missing() {
    src="$1"
    dst="$2"
    if [ ! -f "$dst" ]; then
        mkdir -p "$(dirname "$dst")"
        cp "$src" "$dst"
        strip_utf8_bom "$dst"
        echo "[bootstrap] created $dst"
    fi
}

strip_utf8_bom() {
    file="$1"
    if [ ! -f "$file" ]; then
        return 0
    fi
    bom=$(head -c 3 "$file" | od -An -tx1 2>/dev/null | tr -d ' \n')
    if [ "$bom" = "efbbbf" ]; then
        tail -c +4 "$file" > "${file}.nobom"
        mv "${file}.nobom" "$file"
        echo "[bootstrap] stripped UTF-8 BOM from $file"
    fi
}

if [ -d "$TEMPLATES" ]; then
    for src in $(find "$TEMPLATES" -type f ! -path '*/examples/*'); do
        rel="${src#"$TEMPLATES"/}"
        copy_if_missing "$src" "$CONFIG/$rel"
    done
fi

# Runtime data (empty defaults)
if [ ! -f "$CONFIG/pac.json" ]; then
    echo '{}' > "$CONFIG/pac.json"
    echo "[bootstrap] created $CONFIG/pac.json"
fi

if [ ! -f "$CONFIG/hwid.json" ]; then
    echo '{}' > "$CONFIG/hwid.json"
    echo "[bootstrap] created $CONFIG/hwid.json"
fi

if [ ! -f "$CONFIG/clients.json" ]; then
    echo '[]' > "$CONFIG/clients.json"
    echo "[bootstrap] created $CONFIG/clients.json"
fi

if [ ! -f "$CONFIG/clients1.json" ]; then
    echo '[]' > "$CONFIG/clients1.json"
    echo "[bootstrap] created $CONFIG/clients1.json"
fi

if [ ! -f "$CONFIG/deny" ]; then
    : > "$CONFIG/deny"
    echo "[bootstrap] created $CONFIG/deny"
fi

if [ ! -f "$CONFIG/wg1.conf" ]; then
    : > "$CONFIG/wg1.conf"
    echo "[bootstrap] created $CONFIG/wg1.conf (wg container will generate keys)"
fi

if [ ! -f "$CONFIG/mtprotosecret" ]; then
    if command -v openssl >/dev/null 2>&1; then
        openssl rand -hex 16 > "$CONFIG/mtprotosecret"
    else
        date +%s | sha256sum | cut -c1-32 > "$CONFIG/mtprotosecret"
    fi
    echo "[bootstrap] created $CONFIG/mtprotosecret"
fi

if [ ! -f "$CONFIG/mtprotodomain" ]; then
    : > "$CONFIG/mtprotodomain"
    echo "[bootstrap] created $CONFIG/mtprotodomain"
fi

if [ ! -f "$CONFIG/ocserv.passwd" ]; then
    : > "$CONFIG/ocserv.passwd"
    echo "[bootstrap] created $CONFIG/ocserv.passwd"
fi

if [ ! -f "$CONFIG/location.conf" ]; then
    : > "$CONFIG/location.conf"
    echo "[bootstrap] created $CONFIG/location.conf"
fi

if [ ! -f "$CONFIG/override.conf" ]; then
    : > "$CONFIG/override.conf"
    echo "[bootstrap] created $CONFIG/override.conf"
fi

# Fix BOM in existing config files (e.g. after Windows-encoded templates).
if [ -d "$CONFIG" ]; then
    find "$CONFIG" -type f | while read -r f; do
        strip_utf8_bom "$f"
    done
fi

# v3: shadowsocks container removed — drop /v2ray proxy to ss:8388 from nginx configs.
patch_nginx_remove_ss() {
    file="$1"
    if [ ! -f "$file" ] || ! grep -q 'ss:8388' "$file"; then
        return 0
    fi
    tmp="${file}.patch"
    awk '
        /location \/v2ray/ { skip=1 }
        skip && /^        }$/ { skip=0; next }
        !skip { print }
    ' "$file" > "$tmp" && mv "$tmp" "$file"
    echo "[bootstrap] removed dead ss /v2ray upstream from $file"
}
patch_nginx_remove_ss "$CONFIG/nginx.conf"
patch_nginx_remove_ss "$CONFIG/nginx_default.conf"
