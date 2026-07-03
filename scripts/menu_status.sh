#!/bin/sh
# One-shot service health for main menu (run inside service container via docker.sock).
set -u

VER="${VER:-}"
WG1_AWG="${WG1_AWG:-0}"

resolve_container() {
    want="$1"
    if docker inspect -f '{{.State.Running}}' "$want" 2>/dev/null | grep -q true; then
        printf '%s' "$want"
        return 0
    fi
    docker ps --format '{{.Names}}' 2>/dev/null | grep -F "$want" | head -n1
}

proc_match_in() {
    cname="$1"
    pattern="$2"
    if ! docker inspect -f '{{.State.Running}}' "$cname" 2>/dev/null | grep -q true; then
        return 1
    fi
    docker exec "$cname" sh -c "
        pattern=\"$pattern\"
        if command -v pgrep >/dev/null 2>&1; then
            pgrep -f \"\$pattern\" >/dev/null 2>&1 && exit 0
        fi
        if ps w 2>/dev/null | grep -v grep | grep -q \"\$pattern\"; then
            exit 0
        fi
        exit 1
    " 2>/dev/null || return 1
    return 0
}

proc_match_resolved() {
    base="$1"
    pattern="$2"
    cname="$(resolve_container "$base")"
    if [ -z "$cname" ]; then
        return 1
    fi
    proc_match_in "$cname" "$pattern"
}

hysteria_running() {
    cname="$(resolve_container "hysteria-${VER}")"
    if [ -z "$cname" ]; then
        return 1
    fi
    if docker exec "$cname" sh -c 'grep -aq hysteria /proc/1/cmdline 2>/dev/null' 2>/dev/null; then
        return 0
    fi
    proc_match_in "$cname" hysteria
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

if proc_match_resolved "wireguard1-${VER}" "$wg1_cmd"; then
    wg1=1
fi
if proc_match_resolved "xray-${VER}" xray; then
    xr=1
fi
if hysteria_running; then
    hy=1
fi
if proc_match_resolved "mtproto-${VER}" mtproto-proxy; then
    tg=1
fi
if proc_match_resolved "service-${VER}" cron.php; then
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
