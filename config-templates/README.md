# config-templates

Статические шаблоны конфигурации для первого развёртывания.

- При `make u` / `init.sh` скрипт `scripts/bootstrap_config.sh` копирует файлы в `config/`, **если файл ещё не существует**.
- Исключение: **`nginx_default.conf` всегда обновляется** из шаблона при bootstrap (v3 transport paths, `/xh`, BOM-fix).
- **`nginx.conf`** сбрасывается из шаблона только если файл отсутствует, битый или содержит устаревший upstream `ss:8388`.
- Живые данные (`pac.json`, клиенты, ключи WG, xray users) **не** хранятся в git — только локально в `config/`.
- Примеры clash-шаблонов: `examples/T22.json`, `examples/T23.json` (импорт через бот вручную).
