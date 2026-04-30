telegram bot to manage servers (inside the bot)

- VLESS (Reality / Websocket / Both)
- NaiveProxy
- OpenConnect
- Wireguard
- Amnezia
- AdguardHome
- MTProto
- PAC
- automatic ssl

---
environment: ubuntu 22.04/24.04, debian 11/12

## Install:

```shell
wget -O- https://raw.githubusercontent.com/TrimXx/vpnbot_extra/master/scripts/init.sh | sh -s YOUR_TELEGRAM_BOT_KEY master
```

## One-command install/upgrade

Use the same command for both first install and safe in-place upgrade:

```shell
wget -O- https://raw.githubusercontent.com/TrimXx/vpnbot_extra/master/scripts/init.sh | sh -s YOUR_TELEGRAM_BOT_KEY master
```

- Fresh server: clones repo, creates `app/config.php`, starts containers.
- Existing server (`/root/vpnbot_extra` or legacy `/root/vpnbot`): pulls latest code and runs `make u` without wiping runtime data (`config/`, `certs/`, `.env`, stats, HWID storage).
- If local git changes exist, script auto-saves them to `git stash` before update.
#### Restart:
```shell
make r
```
#### autoload:
```shell
crontab -e
```
add `@reboot cd /root/vpnbot_extra && make r` and save

---

## Project updates

- Added HWID runtime mode controls (global + per-subscription override).
- Implemented lazy migration to per-device UUID model (`1 device = 1 UUID`).
- Added connected devices section in subscription UI with device traffic.
- Added backward compatibility for legacy subscription links after migration.
- Added transport mode `Both` for subscription generation (WS + Reality profiles).
- Added Telegram control to change Reality target destination (`ip/domain:port`) for `Reality`/`Both`.
- Fixed garbled button labels in Telegram UI.
- Improved PHP logging setup and error capture.
- Migrated traffic accounting to stable `users_by_id` key with fallback compatibility.

## AI contribution notice

Part of these fixes and refactorings were implemented with AI assistance.
