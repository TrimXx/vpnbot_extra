#!/bin/sh
# Fix nginx configs after v3 trim (no shadowsocks): remove /v2ray blocks, restore truncated files.
set -eu

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
CONFIG="$ROOT/config"
TEMPLATES="$ROOT/config-templates"

nginx_balanced() {
    file="$1"
    if [ ! -f "$file" ]; then
        return 1
    fi
    open=$(grep -o '{' "$file" | wc -l | tr -d ' ')
    close=$(grep -o '}' "$file" | wc -l | tr -d ' ')
    [ "$open" = "$close" ] && tail -1 "$file" | grep -q '^}$'
}

strip_utf8_bom() {
    file="$1"
    bom=$(head -c 3 "$file" | od -An -tx1 2>/dev/null | tr -d ' \n')
    if [ "$bom" = "efbbbf" ]; then
        tail -c +4 "$file" > "${file}.nobom"
        mv "${file}.nobom" "$file"
    fi
}

# Only active location blocks (8 spaces) — never match commented "#     location /v2ray".
remove_v2ray_blocks() {
    file="$1"
    awk '
        /^        location \/v2ray/ {
            skip = 1
            depth = 0
            for (i = 1; i <= length($0); i++) {
                c = substr($0, i, 1)
                if (c == "{") depth++
                if (c == "}") depth--
            }
            if (depth <= 0) next
        }
        skip {
            for (i = 1; i <= length($0); i++) {
                c = substr($0, i, 1)
                if (c == "{") depth++
                if (c == "}") depth--
            }
            if (depth <= 0) { skip = 0; next }
            next
        }
        { print }
    ' "$file" > "${file}.tmp" && mv "${file}.tmp" "$file"
}

restore_from_template() {
    dst="$1"
    src="$2"
    cp "$src" "$dst"
    strip_utf8_bom "$dst"
    echo "[repair_nginx] restored $dst from template"
}

need_reset_default=0
if [ -f "$CONFIG/nginx_default.conf" ]; then
    if grep -q 'ss:8388' "$CONFIG/nginx_default.conf" || ! nginx_balanced "$CONFIG/nginx_default.conf"; then
        need_reset_default=1
    fi
else
    need_reset_default=1
fi

if [ "$need_reset_default" = 1 ] && [ -f "$TEMPLATES/nginx_default.conf" ]; then
    restore_from_template "$CONFIG/nginx_default.conf" "$TEMPLATES/nginx_default.conf"
fi

for f in "$CONFIG/nginx.conf" "$CONFIG/nginx_default.conf"; do
    if [ -f "$f" ] && grep -q 'ss:8388' "$f"; then
        remove_v2ray_blocks "$f"
        echo "[repair_nginx] removed /v2ray (ss) blocks from $f"
    fi
done

if [ -f "$CONFIG/nginx.conf" ] && ! nginx_balanced "$CONFIG/nginx.conf"; then
    if [ -f "$TEMPLATES/nginx.conf" ]; then
        restore_from_template "$CONFIG/nginx.conf" "$TEMPLATES/nginx.conf"
        echo "[repair_nginx] nginx.conf was truncated; service will regenerate domains on start"
    fi
fi

if [ -f "$CONFIG/nginx_default.conf" ] && ! nginx_balanced "$CONFIG/nginx_default.conf"; then
    if [ -f "$TEMPLATES/nginx_default.conf" ]; then
        restore_from_template "$CONFIG/nginx_default.conf" "$TEMPLATES/nginx_default.conf"
    fi
fi
