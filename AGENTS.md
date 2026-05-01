# AGENTS.md

## Cursor Cloud specific instructions

### Overview

VPNBot Extra is a Docker Compose–based Telegram bot that manages a multi-protocol VPN server. The entire stack (16 services) is defined in `docker-compose.yml` and orchestrated via the `makefile`. There is no traditional database — all state is stored in JSON files under `config/`.

### Starting / Stopping

- `make u` — start all containers (pulls images from Docker Hub, starts compose stack).
- `make d` — stop all containers.
- `make r` — restart (`make d` then `make u`).
- `docker compose ps` or `make ps` — list containers.

### Cloud VM Limitations

- **No `/dev/net/tun`**: WireGuard (`wg`, `wg1`), OpenConnect (`oc`), and WARP (`wp`) containers require TUN device and `NET_ADMIN` — they won't start in cloud VMs. This is expected.
- **No port 443 binding**: The `upstream` container binds 443 and depends on `oc`/`np`. It may fail if those dependencies aren't healthy.
- Core services (`php`, `nginx`, `xray`, `adguard`, `shadowsocks`, `mtproto`, `dnstt`, `hysteria`, `naive`, `proxy`, `service`) run fine.

### Development Config

- `app/config.php` must exist with at least `$c = ['key' => '<telegram_bot_token>'];`. Without a real token, `init.php` (webhook setup) will fail. Use a `docker-compose.override.yml` to bypass this by touching `/start` in the PHP container command.
- Required empty files before first run: `touch override.env docker-compose.override.yml config/location.conf config/override.conf`
- Required directories: `certs/`, `logs/`, `ssh/`, `update/`, `mirror/`, `singbox_windows/`
- The `update/` directory needs `pipe`, `key`, `curl` files (can be empty).

### PHP Linting

Run inside the PHP container:
```sh
docker exec php-v2.29 sh -c 'for f in /app/*.php; do php -l "$f"; done'
```
The container name includes the version suffix from `version` file (currently `v2.29`).

### Testing the Bot Webhook

The webhook URL pattern: `POST /tlgrm<hash>?k=<bot_key>` where `<hash>` is `substr(sha256(bot_key), 0, 8)`. A valid POST with a JSON Telegram update body returns HTTP 200.

### Docker Daemon in Cloud VM

Docker requires nested-Docker workarounds:
- Storage driver: `fuse-overlayfs` (set in `/etc/docker/daemon.json`)
- iptables: must use `iptables-legacy` (`update-alternatives --set iptables /usr/sbin/iptables-legacy`)
- Start daemon manually: `dockerd > /var/log/dockerd.log 2>&1 &`
