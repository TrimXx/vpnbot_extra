cat /ssh/key.pub > /root/.ssh/authorized_keys
ssh-keygen -A
/usr/sbin/sshd -D -e "$@" &
if ! nginx -t 2>&1 | tee -a /logs/nginx_error; then
    exit 1
fi
exec nginx -g "daemon off;"
