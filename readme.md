# VPNBot Extra v3-trimx

Telegram-бот для управления VPN-сервером: VLESS + Mihomo/Clash подписки, AWG (WG1), AdGuard, MTProto, Hysteria.

## Русский

Telegram-бот для управления VPN-сервером из Telegram.

**Руководство пользователя (RU):** [docs/USER_GUIDE_RU.md](docs/USER_GUIDE_RU.md) — как выдавать доступ, HWID, подписки, WG/AWG и типовые сценарии без технических деталей.

### Что поддерживается

- VLESS (`Reality` / `Websocket` / `Both`) + Mihomo/Clash подписки
- WireGuard / AmneziaWG (только WG1)
- AdGuardHome
- MTProto
- Hysteria
- PAC / Rule-set
- Автоматические SSL-сертификаты

### Окружение

- Ubuntu `22.04/24.04`
- Debian `11/12`

### Установка (ветка v2)

```shell
wget -O- https://raw.githubusercontent.com/TrimXx/vpnbot_extra/v2/scripts/init.sh | sh -s YOUR_TELEGRAM_BOT_KEY v2
```

При первом запуске создаются `.env` (из `env.defaults`), `config/` (из `config-templates/`) и `override.env`.

### Обновление (без токена бота)

```shell
wget -O- https://raw.githubusercontent.com/TrimXx/vpnbot_extra/v2/scripts/init.sh | sh -s -- v2
```

### Важное по обновлениям

- `init.sh` по умолчанию обновляет только `app/`, чтобы не перезаписывать ваши рабочие конфиги.
- Для полного обновления репозитория используйте:

```shell
UPGRADE_SCOPE=all wget -O- https://raw.githubusercontent.com/TrimXx/vpnbot_extra/v2/scripts/init.sh | sh -s -- v2
```

- `make u` и `update/update.sh` обновлены для безопасного сценария: подтягиваются целевые кодовые пути (`app`, `update`, `makefile`, `version`) без wipe конфигов.

### Конфигурация (важно)

- **`config/`** — только на сервере, **не в git** (живые данные, секреты).
- **`config-templates/`** — шаблоны в репозитории; при `make u` / `init.sh` скрипт `bootstrap_config.sh` создаёт недостающие файлы в `config/` без перезаписи существующих.
- **`.env`** — не в git; при первом deploy копируется из `env.defaults` (порты WG1, MTProto, IMAGE и т.д.).
- Локально можно держать полный слепок бота в `config/` — git его не видит.
- Не используйте `git add -A` без проверки; `make c` для config удалён.

### Перезапуск

```shell
make r
```

### Автозапуск после перезагрузки

```shell
crontab -e
```

Добавьте:

```text
@reboot cd /root/vpnbot_extra && make r
```

### Основные доработки в форке

- HWID runtime mode: глобальный флаг + override на подписку.
- Ленивая миграция на модель `1 устройство = 1 device UUID`.
- Совместимость старых ссылок подписки после миграции.
- Отображение подключенных устройств и трафика по устройствам в `subscription`.
- Пароль на удаление устройства + смена/сброс пароля.
- Заголовки ответа по HWID-статусу в выдаче подписки.
- Транспорт `Both` (WS + Reality) и флаг доступа Reality по пользователю.
- Многодоменная схема (`domain_main + aliases`) и SAN-сертификаты.
- Runtime AWG-профили по `device_uuid` (включая выдачу в Mihomo/Clash при наличии `device_uuid`).
- Runtime AWG операции закреплены за WG1-контекстом.
- Поддержка DNS-алиасов:
  - вывод DoH/DoT по `main + aliases` в меню AdGuard;
  - добавление DoH URL по алиасам в `dns.nameserver` для Clash-подписки.
- Кнопка `change reality server ip/domain` теперь меняет только клиентский Reality `server` (bridge), не трогая `xray reality dest`.
- Обновлен импорт бэкапа: `pac` восстанавливается merge-способом для совместимости старых/новых бэкапов и новых полей.
### Деплой v3-trimx (runbook)

1. Экспорт настроек через бот (**config → export**)
2. `git pull` (или `UPGRADE_SCOPE=all` через `init.sh` для compose)
3. `docker compose build wg1 php --no-cache`
4. `docker compose up -d --remove-orphans`
5. `bash scripts/migrate_awg2.sh` (сброс `wg1_amnezia_keys` в pac)
6. `docker compose restart php xr wg1`
7. Smoke: главное меню, подписка `?t=cl`, HWID + AWG Device в Mihomo, ручной AWG QR

### Заметка по бэкапу/восстановлению

- Экспорт включает: `pac`, `xray`, `xraystats`, `hwid`, WG1, AdGuard, сертификаты, MTProto, Hysteria.
- Импорт сохраняет новые ключи `pac` при восстановлении старых бэкапов (merge с текущими дефолтами).

### AI notice

Часть исправлений и рефакторинга выполнена с помощью ИИ.

---

## English

Telegram bot for managing a VPN server directly from Telegram.

### Supported stack

- VLESS (`Reality` / `Websocket` / `Both`) + Mihomo/Clash subscriptions
- WireGuard / AmneziaWG (WG1 only)
- AdGuardHome
- MTProto
- Hysteria
- PAC / Rule-set
- Automatic SSL certificates

### Environment

- Ubuntu `22.04/24.04`
- Debian `11/12`

### Install

```shell
wget -O- https://raw.githubusercontent.com/TrimXx/vpnbot_extra/master/scripts/init.sh | sh -s YOUR_TELEGRAM_BOT_KEY master
```

### Upgrade (without bot token)

```shell
wget -O- https://raw.githubusercontent.com/TrimXx/vpnbot_extra/master/scripts/init.sh | sh -s -- master
```

### Upgrade behavior

- `init.sh` updates only `app/` by default to avoid overwriting runtime configs.
- Full-repo upgrade is still available:

```shell
UPGRADE_SCOPE=all wget -O- https://raw.githubusercontent.com/TrimXx/vpnbot_extra/v2/scripts/init.sh | sh -s -- v2
```

- `make u` and `update/update.sh` are adjusted for safer upgrades: target code paths (`app`, `update`, `makefile`, `version`) are refreshed without wiping user configs.

### Restart

```shell
make r
```

### Autostart on reboot

```shell
crontab -e
```

Add:

```text
@reboot cd /root/vpnbot_extra && make r
```

### Key fork improvements

- HWID runtime mode with global and per-subscription override.
- Lazy migration to `1 device = 1 device UUID`.
- Backward compatibility for legacy subscription links.
- Connected device list and per-device traffic in subscription UI.
- Device deletion password flow with change/reset support.
- HWID status response headers in subscription endpoints.
- `Both` transport mode (WS + Reality) with per-user Reality access control.
- Multi-domain support (`domain_main + aliases`) with SAN certificates.
- Runtime AWG profiles bound to `device_uuid` (including Mihomo/Clash output when `device_uuid` is present).
- Runtime AWG operations pinned to WG1 context.
- DNS aliases support:
  - DoH/DoT output for `main + aliases` in AdGuard menu;
  - alias DoH URLs appended to Clash `dns.nameserver`.
- `change reality server ip/domain` now changes only client-facing Reality `server` (bridge), without changing `xray reality dest`.
- Backup import improved: `pac` is restored via merge strategy for old/new backup compatibility.
- `/mirror` script updated (socat TCP/UDP, systemd units, install/status/restart/logs/uninstall).

### Backup / restore notes

- Export includes: `pac`, `xray`, `xraystats`, `hwid`, WG/WG1, AdGuard, certificates, MTProto, Hysteria, OC, Shadowsocks.
- Import now preserves newly introduced `pac` keys when restoring older backups (merge with current defaults).
