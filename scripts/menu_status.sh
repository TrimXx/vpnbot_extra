#!/bin/sh
# One-shot service health for main menu (run inside service container via docker.sock).
set -eu

VER="${VER:-}"
WG1_AWG="${WG1_AWG:-0}"

pgrep_in() {
    local cname="$1"
    local pattern="$2"
    if ! docker inspect -f '{{.State.Running}}' "$cname" 2>/dev/null | grep -q true; then
        return 1
    fi
    docker exec "$cname" sh -c "pgrep $pattern" 2>/dev/null | grep -q .
}

wg1_cmd="wg"
if [ "$WG1_AWG" = "1" ]; then
    wg1_cmd="awg"
fi

wg1=0
xr=0
hy=0
tg=0
cron=0
warp="off"

if pgrep_in "wireguard1-${VER}" "-x $wg1_cmd"; then
    wg1=1
fi
if pgrep_in "xray-${VER}" "xray"; then
    xr=1
fi
if pgrep_in "hysteria-${VER}" "hysteria"; then
    hy=1
fi
if pgrep_in "mtproto-${VER}" "mtproto-proxy"; then
    tg=1
fi
if pgrep_in "service-${VER}" "cron.php"; then
    cron=1
fi

wp="warp-${VER}"
if docker inspect -f '{{.State.Running}}' "$wp" 2>/dev/null | grep -q true; then
    if docker exec "$wp" sh -c 'pgrep warp-svc' 2>/dev/null | grep -q .; then
        trace="$(docker exec "$wp" curl -m 1 -s -x socks5://127.0.0.1:40000 https://cloudflare.com/cdn-cgi/trace 2>/dev/null || true)"
        warp="$(echo "$trace" | sed -n 's/^warp=\(.*\)$/\1/p' | head -n1)"
        if [ -z "$warp" ]; then
            warp="on"
        fi
    fi
fi

printf '{"wg1":%s,"xr":%s,"hy":%s,"tg":%s,"cron":%s,"warp":"%s"}' \
    "$wg1" "$xr" "$hy" "$tg" "$cron" "$warp"
