#!/bin/sh
set -eu

if [ ! -s /ssh/key ] || [ ! -s /ssh/key.pub ]; then
    rm -f /ssh/key /ssh/key.pub
    ssh-keygen -m PEM -t rsa -f /ssh/key -N ''
fi

cert_ip=""
if [ -s /certs/self_public ]; then
    cert_ip=$(openssl x509 -in /certs/self_public -noout -ext subjectAltName 2>/dev/null | sed -n 's/.*IP Address:\([0-9.]\{1,\}\).*/\1/p' | head -n 1 || true)
fi
# Telegram checks the webhook IP against subjectAltName. An old file must be reissued.
if [ ! -s /certs/self_private ] || [ ! -s /certs/self_public ] || [ "$cert_ip" != "$IP" ]; then
    if [ -z "${IP:-}" ]; then
        echo "webhook certificate: IP is empty" >&2
        exit 1
    fi
    openssl req -newkey rsa:2048 -sha256 -nodes -x509 -days 365 \
        -keyout /certs/self_private -out /certs/self_public \
        -subj "/C=NN/ST=N/L=N/O=N/CN=$IP" \
        -addext "subjectAltName=IP:$IP"
fi

if [ ! -s /certs/cert_public ] && [ -s /certs/self_public ]; then
    cp /certs/self_public /certs/cert_public
fi
if [ ! -s /certs/cert_private ] && [ -s /certs/self_private ]; then
    cp /certs/self_private /certs/cert_private
fi

mkdir -p /logs
chmod 777 /logs

php init.php
if [[ -f "/start" && -f "/ssh/key.pub" && -s "/ssh/key.pub" ]]; then
    unitd --log /logs/unit_error
    curl -X PUT --data-binary @/config/unit.json --unix-socket /var/run/control.unit.sock http://localhost/config
    pkill unitd
    unitd --no-daemon --control 0.0.0.0:8080 --log /logs/unit_error
else
    exit 1;
fi
