cat /ssh/key.pub > /root/.ssh/authorized_keys
ssh-keygen -A
exec /usr/sbin/sshd -D -e "$@" &
uuid=$(cat /xray.json | jq -r '.inbounds[0].settings.clients[0].id // empty')
if [ -n "$uuid" ]; then
    reality_show=$(jq -r '[.inbounds[]?.streamSettings?.realitySettings?.show // false] | any' /xray.json 2>/dev/null)
    if [ "$reality_show" = "true" ]; then
        xray run -config /xray.json >> /logs/xray_reality.log 2>&1 &
    else
        xray run -config /xray.json > /dev/null 2>&1 &
    fi
fi
tail -f /dev/null
