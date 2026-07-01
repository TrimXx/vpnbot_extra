cat /ssh/key.pub > /root/.ssh/authorized_keys
ssh-keygen -A
exec /usr/sbin/sshd -D -e "$@" &
if [ "$(jq -r '.transport_registry.global.hysteria // .hysteria // 0' /pac.json)" -ne 1 ]; then
    tail -f /dev/null
    exit 0
fi
tail -f /dev/null
