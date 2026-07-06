cat /ssh/key.pub > /root/.ssh/authorized_keys
ssh-keygen -A
exec /usr/sbin/sshd -D -e "$@" &
if ! command -v docker >/dev/null 2>&1; then
    apk add --no-cache docker-cli >/dev/null 2>&1 || true
fi
php service.php
php iplimit.php &
php cron.php