<?php

/**
 * Единый каталог плейсхолдеров Clash/Mihomo-шаблонов.
 * Используется валидатором, генерацией подписки и документацией.
 */
final class ClashTemplatePlaceholders
{
    public const CATALOG = [
        '~outbound~' => [
            'group' => 'client',
            'type' => 'string',
            'ru' => 'Имя основного proxy (pac.outbound, меню proxy names). Подставляется в шаблон и auto-transports.',
        ],
        '~uid~' => [
            'group' => 'client',
            'type' => 'string',
            'ru' => 'UUID клиента VLESS; в HWID runtime — UUID устройства.',
        ],
        '~email~' => [
            'group' => 'client',
            'type' => 'string',
            'ru' => 'Email/имя подписки в Xray.',
        ],
        '~subscription_id~' => [
            'group' => 'client',
            'type' => 'string',
            'ru' => 'Публичный ID подписки (параметр s в URL).',
        ],
        '~domain~' => [
            'group' => 'domain',
            'type' => 'string',
            'ru' => 'Домен для клиента: CDN/link при выключенном Reality, иначе основной домен.',
        ],
        '~directdomain~' => [
            'group' => 'domain',
            'type' => 'string',
            'ru' => 'Основной домен сервера (pac.domain).',
        ],
        '~domain_alt~' => [
            'group' => 'domain',
            'type' => 'string',
            'ru' => 'Первый alias домена, иначе linkdomain, иначе ~domain~.',
        ],
        '~cdndomain~' => [
            'group' => 'domain',
            'type' => 'string',
            'ru' => 'Link/CDN домен (pac.linkdomain), может быть пустым.',
        ],
        '~ip~' => [
            'group' => 'domain',
            'type' => 'string',
            'ru' => 'IP сервера (env IP).',
        ],
        '~hash~' => [
            'group' => 'paths',
            'type' => 'string',
            'ru' => 'Hash инстанса (hashbot), суффикс путей /ws /xh /hy.',
        ],
        '~ws_path~' => [
            'group' => 'paths',
            'type' => 'string',
            'ru' => 'Путь WebSocket VLESS, например /ws7bab2561.',
        ],
        '~xhttp_path~' => [
            'group' => 'paths',
            'type' => 'string',
            'ru' => 'Путь xHTTP VLESS.',
        ],
        '~hy_path~' => [
            'group' => 'paths',
            'type' => 'string',
            'ru' => 'Путь маскировки Hysteria на nginx.',
        ],
        '~dnspath~' => [
            'group' => 'dns',
            'type' => 'string',
            'ru' => 'Путь DoH без схемы: /dns-query{hash}/{uid}.',
        ],
        '~dns~' => [
            'group' => 'dns',
            'type' => 'string',
            'ru' => 'Полный URL DoH для основного ~domain~.',
        ],
        '~dns_domains~' => [
            'group' => 'dns',
            'type' => 'json-array',
            'ru' => 'JSON-массив всех DNS-доменов (основной + aliases) для fake-ip-filter.',
        ],
        '~dns_urls~' => [
            'group' => 'dns',
            'type' => 'json-array',
            'ru' => 'JSON-массив DoH URL по всем DNS-доменам.',
        ],
        '~public_key~' => [
            'group' => 'reality',
            'type' => 'string',
            'ru' => 'Публичный ключ Reality (pac.xray).',
        ],
        '~short_id~' => [
            'group' => 'reality',
            'type' => 'string',
            'ru' => 'Short ID Reality.',
        ],
        '~server_name~' => [
            'group' => 'reality',
            'type' => 'string',
            'ru' => 'SNI Reality (serverNames[0]).',
        ],
        '~reality_server_host~' => [
            'group' => 'reality',
            'type' => 'string',
            'ru' => 'Хост Reality bridge (куда стучится клиент).',
        ],
        '~reality_server_port~' => [
            'group' => 'reality',
            'type' => 'number',
            'ru' => 'Порт Reality bridge (число в JSON).',
        ],
        '~reality_dest~' => [
            'group' => 'reality',
            'type' => 'string',
            'ru' => 'Цель Reality dest (куда маскируется трафик).',
        ],
        '~port_ws~' => [
            'group' => 'ports',
            'type' => 'number',
            'ru' => 'Клиентский порт WebSocket VLESS (обычно 443).',
        ],
        '~port_xhttp~' => [
            'group' => 'ports',
            'type' => 'number',
            'ru' => 'Клиентский порт xHTTP (обычно 443).',
        ],
        '~port_reality~' => [
            'group' => 'ports',
            'type' => 'number',
            'ru' => 'Клиентский порт Reality (обычно 443).',
        ],
        '~port_hy~' => [
            'group' => 'ports',
            'type' => 'number',
            'ru' => 'Порт Hysteria2 для клиента.',
        ],
        '~hy_password~' => [
            'group' => 'transports',
            'type' => 'string',
            'ru' => 'Пароль Hysteria2 (pac.hysteria_pass).',
        ],
        '~wg_server~' => [
            'group' => 'transports',
            'type' => 'string',
            'ru' => 'Хост AWG/WG endpoint (домен или IP, без порта).',
        ],
        '~wg_port~' => [
            'group' => 'transports',
            'type' => 'number',
            'ru' => 'UDP-порт AWG/WG1 (WG1PORT).',
        ],
        '~transport_ws~' => [
            'group' => 'flags',
            'type' => 'number',
            'ru' => '1 если WS включён для подписки, иначе 0.',
        ],
        '~transport_xhttp~' => [
            'group' => 'flags',
            'type' => 'number',
            'ru' => '1 если xHTTP включён, иначе 0.',
        ],
        '~transport_reality~' => [
            'group' => 'flags',
            'type' => 'number',
            'ru' => '1 если Reality включён, иначе 0.',
        ],
        '~transport_hy~' => [
            'group' => 'flags',
            'type' => 'number',
            'ru' => '1 если Hysteria включена, иначе 0.',
        ],
        '~transport_awg~' => [
            'group' => 'flags',
            'type' => 'number',
            'ru' => '1 если AWG runtime в подписке включён для клиента, иначе 0.',
        ],
        '~pac~' => [
            'group' => 'rules',
            'type' => 'json-array',
            'ru' => 'Список includelist для RULE-SET pac.',
        ],
        '~block~' => [
            'group' => 'rules',
            'type' => 'json-array',
            'ru' => 'Список blocklist.',
        ],
        '~warp~' => [
            'group' => 'rules',
            'type' => 'json-array',
            'ru' => 'Список warplist.',
        ],
        '~process~' => [
            'group' => 'rules',
            'type' => 'json-array',
            'ru' => 'Список processlist.',
        ],
        '~package~' => [
            'group' => 'rules',
            'type' => 'json-array',
            'ru' => 'Список packagelist.',
        ],
        '~subnet~' => [
            'group' => 'rules',
            'type' => 'json-array',
            'ru' => 'Список subnetlist.',
        ],
        '~mirror_hosts~' => [
            'group' => 'mirrors',
            'type' => 'json-array',
            'ru' => 'JSON-массив включённых зеркал (host или host:port).',
        ],
        '~proxy_suffix_ws~' => [
            'group' => 'mirrors',
            'type' => 'string',
            'ru' => 'Суффикс имени WS-прокси (по умолчанию -ws).',
        ],
        '~proxy_suffix_xhttp~' => [
            'group' => 'mirrors',
            'type' => 'string',
            'ru' => 'Суффикс имени xHTTP-прокси (по умолчанию -xhttp).',
        ],
        '~proxy_suffix_hy2~' => [
            'group' => 'mirrors',
            'type' => 'string',
            'ru' => 'Суффикс имени HY2-прокси (по умолчанию -hy2).',
        ],
        '~client_fingerprint~' => [
            'group' => 'transports',
            'type' => 'string',
            'ru' => 'uTLS fingerprint для VLESS (pac.client_fingerprint): chrome, firefox, safari, ios, android, edge, 360, qq, random, randomized.',
        ],
        '~proxy_group_type~' => [
            'group' => 'client',
            'type' => 'string',
            'ru' => 'Тип основной proxy-group (pac.proxy_group_type): keep, select, url-test, fallback, load-balance. keep = как в шаблоне.',
        ],
    ];

    public static function names(): array
    {
        return array_keys(self::CATALOG);
    }

    public static function helpHtml(): string
    {
        $groups = [
            'client' => 'Клиент',
            'domain' => 'Домены',
            'paths' => 'Пути',
            'dns' => 'DNS',
            'reality' => 'Reality',
            'ports' => 'Порты',
            'transports' => 'Транспорты',
            'flags' => 'Флаги транспортов',
            'mirrors' => 'Зеркала и имена',
            'rules' => 'Списки правил',
        ];
        $byGroup = [];
        foreach (self::CATALOG as $name => $meta) {
            $byGroup[$meta['group']][] = [$name, $meta['ru'], $meta['type']];
        }
        $lines = [];
        foreach ($groups as $gid => $title) {
            if (empty($byGroup[$gid])) {
                continue;
            }
            $lines[] = "<b>$title</b>";
            foreach ($byGroup[$gid] as [$name, $ru, $type]) {
                $typeHint = $type === 'number' ? ' <i>(число)</i>' : ($type === 'json-array' ? ' <i>(JSON-массив)</i>' : '');
                $lines[] = "<code>$name</code>$typeHint — $ru";
            }
            $lines[] = '';
        }
        $lines[] = '<b>Мета</b>';
        $lines[] = '<code>auto-transports</code> — true (по умолчанию): бот дописывает proxy-ws/xhttp/hy2, правит основной VLESS, зеркала и AWG runtime. false: только шаблон + зеркала (если заданы).';

        return implode("\n", $lines);
    }
}
