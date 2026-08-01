#!/bin/sh
# One-shot service health for main menu (run inside svc container via docker.sock).
set -u

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

wg1_interface_up() {
    if ! docker inspect -f '{{.State.Running}}' wg1 2>/dev/null | grep -q true; then
        return 1
    fi
    docker exec wg1 sh -c '
        if command -v awg >/dev/null 2>&1 && awg show wg0 >/dev/null 2>&1; then
            exit 0
        fi
        if command -v wg >/dev/null 2>&1 && wg show wg0 >/dev/null 2>&1; then
            exit 0
        fi
        ip link show wg0 2>/dev/null | grep -q "wg0"
    ' 2>/dev/null
}

hysteria_running() {
    proc_match_in hy '[h]ysteria'
}

wg1=0
xr=0
hy=0
tg=0
cron=0
warp="off"

if wg1_interface_up; then
    wg1=1
fi
if proc_match_in xr '[x]ray'; then
    xr=1
fi
if hysteria_running; then
    hy=1
fi
if proc_match_in tg 'mtproto-proxy'; then
    tg=1
fi
if proc_match_in svc 'cron.php'; then
    cron=1
fi

if docker inspect -f '{{.State.Running}}' wp 2>/dev/null | grep -q true; then
    if docker exec wp sh -c 'pgrep microsocks >/dev/null 2>&1 && wg show >/dev/null 2>&1' 2>/dev/null; then
        trace="$(docker exec wp wgcf trace 2>/dev/null || true)"
        if [ -z "$trace" ]; then
            # Fallback via xray container to legacy socks :4000 (config-compatible).
            trace="$(docker exec xr curl -m 1 -s -x socks5://10.10.0.13:4000 https://cloudflare.com/cdn-cgi/trace 2>/dev/null || true)"
        fi
        warp="$(echo "$trace" | sed -n 's/^warp=\(.*\)$/\1/p' | head -n1)"
        if [ -z "$warp" ]; then
            warp="on"
        fi
    fi
fi

printf '{"wg1":%s,"xr":%s,"hy":%s,"tg":%s,"cron":%s,"warp":"%s"}' \
    "$wg1" "$xr" "$hy" "$tg" "$cron" "$warp"
