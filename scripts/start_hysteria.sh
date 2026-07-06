#!/bin/sh
set -u

cat /ssh/key.pub > /root/.ssh/authorized_keys
ssh-keygen -A
exec /usr/sbin/sshd -D -e "$@" &

# Hysteria is started by service.php (restartHysteria) after nginx/upstream are ready.
# Starting it here raced with service.php (pkill + rewrite masquerade) and left HY broken
# until the client refreshed the subscription (by which time svc had finished).
exec tail -f /dev/null
