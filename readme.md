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
