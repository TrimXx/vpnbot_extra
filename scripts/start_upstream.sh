cat /ssh/key.pub > /root/.ssh/authorized_keys
ssh-keygen -A
/usr/sbin/sshd -D -e "$@" &
# hy:443 fails nginx -t when the hy container is not on the network yet; use fixed compose IP
sed -i 's/server hy:443;/server 10.10.0.17:443;/g' /etc/nginx/nginx.conf 2>/dev/null || true
if ! nginx -t >>/logs/upstream_error 2>&1; then
    exit 1
fi
exec nginx -g "daemon off;"
