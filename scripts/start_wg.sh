cat /ssh/key.pub > /root/.ssh/authorized_keys
ssh-keygen -A
exec /usr/sbin/sshd -D -e "$@" &

# WG1 (wireguard1) always runs AmneziaWG. transport_registry.global.awg only gates
# optional VLESS runtime device profiles in subscriptions — not this container.
INTERFACE=$(route | grep '^default' | grep -o '[^ ]*$')
if [ "$HOSTNAME" = "wireguard1" ]
then
    if [ $(cat /etc/wireguard/wg0.conf | wc -c) -eq 0 ]
    then
        PRIVATEKEY=$(wg genkey | tee /etc/wireguard/privatekey)
        echo "[Interface]" > /etc/wireguard/wg0.conf
        echo "PrivateKey = $PRIVATEKEY" >> /etc/wireguard/wg0.conf
        echo "Address = $ADDRESS" >> /etc/wireguard/wg0.conf
        echo "ListenPort = $WG1PORT" >> /etc/wireguard/wg0.conf
    else
        sed "s/ListenPort = [0-9]\+/ListenPort = $WG1PORT/" /etc/wireguard/wg0.conf > change_port
        sed "s|Address = [0-9\.\/ ]\+|Address = $ADDRESS|" change_port > change_address
        cat change_address > /etc/wireguard/wg0.conf
    fi
else
    if [ $(cat /etc/wireguard/wg0.conf | wc -c) -eq 0 ]
    then
        PRIVATEKEY=$(wg genkey | tee /etc/wireguard/privatekey)
        echo "[Interface]" > /etc/wireguard/wg0.conf
        echo "PrivateKey = $PRIVATEKEY" >> /etc/wireguard/wg0.conf
        echo "Address = $ADDRESS" >> /etc/wireguard/wg0.conf
        echo "ListenPort = $WGPORT" >> /etc/wireguard/wg0.conf
    else
        sed "s/ListenPort = [0-9]\+/ListenPort = $WGPORT/" /etc/wireguard/wg0.conf > change_port
        sed "s|Address = [0-9\.\/ ]\+|Address = $ADDRESS|" change_port > change_address
        cat change_address > /etc/wireguard/wg0.conf
    fi
fi
iptables -t nat -A POSTROUTING --destination 10.10.0.5 -j ACCEPT
iptables -t nat -A POSTROUTING -o $INTERFACE -j MASQUERADE
mkdir -p /etc/amnezia/amneziawg
ln -sf /etc/wireguard/wg0.conf /etc/amnezia/amneziawg/wg0.conf
if [ "$HOSTNAME" = "wireguard1" ]
then
    sh /awg_up.sh wg0
    if [ "$(cat /pac.json | jq -r '.wg1_blocktorrent // 0')" -eq 1 ]
    then
        sh /block_torrent.sh
    fi
    if [ "$(cat /pac.json | jq -r '.wg1_exchange // 0')" -eq 1 ]
    then
        sh /block_exchange.sh
    fi
else
    if [ "$(cat /pac.json | jq -r '.amnezia // 0')" -eq 1 ]
    then
        awg-quick up wg0
    else
        wg-quick up wg0
    fi
    if [ "$(cat /pac.json | jq -r '.blocktorrent // 0')" -eq 1 ]
    then
        sh /block_torrent.sh
    fi
    if [ "$(cat /pac.json | jq -r '.exchange // 0')" -eq 1 ]
    then
        sh /block_exchange.sh
    fi
fi
tail -f /dev/null
