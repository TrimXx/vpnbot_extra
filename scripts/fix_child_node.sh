#!/bin/sh
set -eu
cd /root/vpnbot_extra
python3 <<'PY'
import re
p='config/nginx.conf'
s=open(p).read()
s2=re.sub(
    r'\n\s+location /tlgrm \{\s*\n\s+access_log off;\s*\n\s+proxy_pass http://php;\s*\n\s+\}',
    '',
    s,
)
open(p,'w').write(s2)
print('removed plain /tlgrm blocks:', s.count('location /tlgrm {'), '->', s2.count('location /tlgrm {'))
PY
docker compose run --rm --no-deps ng nginx -t 2>&1 | tail -2
IP="$(hostname -I | awk '{print $1}')"
IP="$IP" docker compose --env-file ./.env --env-file ./override.env up -d ng up service 2>&1 | tail -8
sleep 10
docker compose ps ng up service | tail -4
ss -tlnp | grep -E ':80|:443'
curl -sk -o /dev/null -w "node-sync:%{http_code}\n" -X POST "https://127.0.0.1/pac7bab2561/node-sync" -d '{}'
