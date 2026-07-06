cat /ssh/key.pub > /root/.ssh/authorized_keys
ssh-keygen -A
/usr/sbin/sshd -D -e "$@" &
# Docker DNS may be unavailable at nginx -t; use fixed compose IPs
sed -i \
    -e 's/server ng:443;/server 10.10.0.2:443;/g' \
    -e 's/server xr:33443;/server 10.10.0.9:33443;/g' \
    -e 's/server xr:\([0-9][0-9]*\);/server 10.10.0.9:\1;/g' \
    -e 's/server hy:443;/server 10.10.0.17:443;/g' \
    /etc/nginx/nginx.conf 2>/dev/null || true
if ! nginx -t >>/logs/upstream_error 2>&1; then
    exit 1
fi
exec nginx -g "daemon off;"
