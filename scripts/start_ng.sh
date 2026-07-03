cat /ssh/key.pub > /root/.ssh/authorized_keys
ssh-keygen -A
/usr/sbin/sshd -D -e "$@" &
if ! nginx -t >>/logs/nginx_error 2>&1; then
    exit 1
fi
exec nginx -g "daemon off;"
