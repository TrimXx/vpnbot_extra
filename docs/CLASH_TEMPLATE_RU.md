# Clash / Mihomo — переменные шаблона подписки

Шаблоны (`Menu → Xray → clash templates`) — JSON-конфиг Mihomo/Clash Meta. Перед выдачей подписки бот подставляет **плейсхолдеры** `~tag~`.

Справка в боте: **Menu → Xray → clash templates** (под заголовком).

---

## Как работает подстановка

1. Загружается шаблон (`origin`, кастомный или default).
2. `replaceTags()` заменяет все `~tag~` на значения из `buildClashTemplateTags()`.
3. Если `auto-transports: true` (по умолчанию):
   - основной proxy подстраивается под включённые транспорты клиента;
   - дописываются `proxy-ws`, `proxy-xhttp`, `proxy-hy2` (если ещё нет);
   - в подписку добавляется **AWG Device** (runtime WG по HWID).
4. Rule-providers и DNS nameserver мержатся как раньше.
5. Ключ `auto-transports` из итогового YAML удаляется.

---

## Мета-поле шаблона

| Поле | По умолчанию | Описание |
|------|--------------|----------|
| `auto-transports` | `true` | `false` — бот **не** дописывает транспорты и не правит основной VLESS; в подписке только то, что описано в шаблоне (+ AWG runtime при включённом HWID WG). |
| `vpnbot-app-groups` | — | Опциональная мета (не попадает в YAML подписки). Можно хранить вручную в JSON шаблона. |

Пример продвинутого шаблона: `config-templates/examples/T23.json` (`auto-transports: false`).  
Пример app-групп (RULE-SET MRS): `config-templates/examples/T24.json`.

---

## App-группы (вручную в JSON)

В шаблоне можно добавить select-группы + `rule-providers` (MRS domain/ipcidr или yaml classical) + `RULE-SET` в `rules`.

Порядок правил критичен: app RULE-SET — **сразу после** REJECT/block, **до** process/package/warp/pac/subnet/MATCH. Для таких шаблонов обычно `"add-rule-providers": false`.

В меню шаблонов кнопка **назначить** открывает список подписок и ставит шаблон выбранному клиенту.

---

## Плейсхолдеры

### Клиент

| Переменная | Тип | Описание |
|------------|-----|----------|
| `~outbound~` | string | Имя основного proxy (pac.outbound или `proxy`). |
| `~uid~` | string | UUID VLESS; в HWID runtime — UUID устройства. |
| `~email~` | string | Email клиента в Xray. |
| `~subscription_id~` | string | ID подписки (параметр `s` в URL). |

### Домены

| Переменная | Тип | Описание |
|------------|-----|----------|
| `~domain~` | string | Домен для клиента (CDN/link если Reality выкл.). |
| `~directdomain~` | string | Основной домен (`pac.domain`). |
| `~domain_alt~` | string | Первый alias, иначе linkdomain, иначе `~domain~`. |
| `~cdndomain~` | string | Link/CDN домен. |
| `~ip~` | string | IP сервера. |

### Пути (hash-суффикс)

| Переменная | Пример | Описание |
|------------|--------|----------|
| `~hash~` | `7bab2561` | Hash инстанса. |
| `~ws_path~` | `/ws7bab2561` | WebSocket path. |
| `~xhttp_path~` | `/xh7bab2561` | xHTTP path. |
| `~hy_path~` | `/hy7bab2561` | Hysteria mask path. |

### DNS

| Переменная | Тип | Описание |
|------------|-----|----------|
| `~dns~` | string | DoH URL для `~domain~`. |
| `~dnspath~` | string | Путь DoH: `/dns-query{hash}/{uid}`. |
| `~dns_domains~` | JSON-массив | Все DNS-домены (основной + aliases). |
| `~dns_urls~` | JSON-массив | DoH URL по всем DNS-доменам. |

**JSON-массивы** в шаблоне — подставляйте как единственное значение поля, например:

```json
"nameserver": "~dns_urls~"
```

(в файле шаблона кавычки вокруг `~dns_urls~`; бот заменит на `[ "https://...", ... ]`).

### Reality

| Переменная | Тип | Описание |
|------------|-----|----------|
| `~public_key~` | string | Публичный ключ Reality. |
| `~short_id~` | string | Short ID. |
| `~server_name~` | string | SNI Reality. |
| `~reality_server_host~` | string | Хост bridge (куда подключается клиент). |
| `~reality_server_port~` | number | Порт bridge (в JSON как число через `"~reality_server_port~"`). |
| `~reality_dest~` | string | Dest (маскировка). |

### Порты

| Переменная | Обычно | Описание |
|------------|--------|----------|
| `~port_ws~` | 443 | VLESS WebSocket. |
| `~port_xhttp~` | 443 | VLESS xHTTP. |
| `~port_reality~` | 443 | VLESS Reality (клиентский). |
| `~port_hy~` | 443 | Hysteria2. |
| `~wg_port~` | 51821 | AWG/WG UDP. |

В JSON-шаблоне: `"port": "~port_ws~"` (строка, в YAML станет числом).

### Транспорты

| Переменная | Описание |
|------------|----------|
| `~hy_password~` | Пароль Hysteria2. |
| `~wg_server~` | Хост WG endpoint (без порта). |

Секреты AWG (private-key) **не** выносятся в шаблон — только runtime-профиль по HWID.

### Флаги (0 / 1)

`~transport_ws~`, `~transport_xhttp~`, `~transport_reality~`, `~transport_hy~`, `~transport_awg~`

Чистый JSON условий не поддерживает — используйте для документации в шаблоне или несколько заготовок proxy.

### Списки правил (RULE-SET)

| Переменная | Список в pac.json |
|------------|-------------------|
| `~pac~` | includelist |
| `~block~` | blocklist |
| `~warp~` | warplist |
| `~process~` | processlist |
| `~package~` | packagelist |
| `~subnet~` | subnetlist |

В rules: `"list": "~package~"` (бот подставит JSON-массив).

---

## Примеры proxy

### WebSocket VLESS

```json
{
  "name": "WS",
  "type": "vless",
  "server": "~directdomain~",
  "port": "~port_ws~",
  "uuid": "~uid~",
  "network": "ws",
  "tls": true,
  "servername": "~directdomain~",
  "client-fingerprint": "chrome",
  "ws-opts": { "path": "~ws_path~" }
}
```

### Hysteria2

```json
{
  "name": "HY2",
  "type": "hysteria2",
  "server": "~domain~",
  "port": "~port_hy~",
  "password": "~hy_password~",
  "sni": "~directdomain~",
  "alpn": ["h3"]
}
```

### Reality (основной outbound в `origin`)

См. `config-templates/clash.json` — `~reality_server_host~`, `~port_reality~`, `~server_name~`, `~public_key~`, `~short_id~`.

---

## Валидация

При **загрузке файла** в боте и при **Save** в web-редакторе шаблон проверяется сразу. Ошибки блокируют сохранение; warnings показываются, но не блокируют.

Проверяется:

- синтаксис JSON (в т.ч. типичные подсказки: trailing comma, кавычки);
- `proxies` / `proxy-groups` (нужна группа `PROXY`, ссылки на существующие proxy/group);
- `rule-providers` (behavior/format/url; `mrs` + `classical` — ошибка);
- `rules`: type/action, RULE-SET → provider, `MATCH` только последним;
- warning, если внешний RULE-SET стоит ниже `~pac~`;
- неизвестные плейсхолдеры (warning).

---

## Файлы в репозитории

| Файл | Назначение |
|------|------------|
| `app/ClashTemplatePlaceholders.php` | Каталог переменных (источник истины). |
| `app/traits/ClashTemplateTrait.php` | `buildClashTemplateTags()`, `auto-transports`. |
| `config-templates/clash.json` | Шаблон `origin`. |
| `config-templates/examples/T23.json` | Multi-node fallback без auto-transports. |
| `config-templates/examples/T24.json` | App-группы (RULE-SET MRS) выше pac/subnet. |

---

## Частые ошибки

1. **Хардкод `/ws7bab2561`** — при смене hash подписка ломается. Используйте `~ws_path~`.
2. **`global-client-fingerprint`** — удалён в Mihomo; только `client-fingerprint` на proxy.
3. **`auto-transports: true` + свои WS/HY в шаблоне** — получите дубликаты; ставьте `false` или уберите ручные proxy.
4. **AWG в шаблоне** — статические ключи не подставляются; runtime AWG добавляется ботом при HWID WG.
5. **App RULE-SET ниже `~pac~`** — домен уйдёт в PROXY, а не в app-группу. Держите app-правила сразу после block.
