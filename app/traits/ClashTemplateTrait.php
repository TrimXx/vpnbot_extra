<?php

require_once __DIR__ . '/../ClashTemplatePlaceholders.php';

trait ClashTemplateTrait
{
    protected function getAllowedClientFingerprints(): array
    {
        return ['chrome', 'firefox', 'safari', 'ios', 'android', 'edge', '360', 'qq', 'random', 'randomized'];
    }

    protected function normalizeClientFingerprint(string $value): string
    {
        $fp = strtolower(trim($value));

        return in_array($fp, $this->getAllowedClientFingerprints(), true) ? $fp : 'chrome';
    }

    protected function getClientFingerprint(?array $pac = null): string
    {
        $pac = $pac ?? $this->getPacConf();

        return $this->normalizeClientFingerprint((string) ($pac['client_fingerprint'] ?? 'chrome'));
    }

    protected function getAllowedProxyGroupTypes(): array
    {
        return ['keep', 'select', 'url-test', 'fallback', 'load-balance'];
    }

    protected function normalizeProxyGroupType(string $value): string
    {
        $type = strtolower(trim($value));

        return in_array($type, $this->getAllowedProxyGroupTypes(), true) ? $type : 'keep';
    }

    protected function getProxyGroupType(?array $pac = null): string
    {
        $pac = $pac ?? $this->getPacConf();

        return $this->normalizeProxyGroupType((string) ($pac['proxy_group_type'] ?? 'keep'));
    }

    protected function getProxyGroupHealthUrl(?array $pac = null): string
    {
        $pac = $pac ?? $this->getPacConf();
        $url = trim((string) ($pac['proxy_group_url'] ?? ''));

        return $url !== '' ? $url : 'http://www.gstatic.com/generate_204';
    }

    protected function getProxyGroupInterval(?array $pac = null): int
    {
        $pac = $pac ?? $this->getPacConf();
        $interval = (int) ($pac['proxy_group_interval'] ?? 300);

        return $interval > 0 ? $interval : 300;
    }

    /**
     * Override main Clash proxy-group type (PROXY / ~outbound~ / first group).
     * keep = leave template as-is.
     */
    protected function applyProxyGroupTypeToClashConfig(array &$c, ?array $pac = null): void
    {
        $pac = $pac ?? $this->getPacConf();
        $type = $this->getProxyGroupType($pac);
        if ($type === 'keep' || empty($c['proxy-groups']) || !is_array($c['proxy-groups'])) {
            return;
        }

        $outbound = $this->getMainClashOutboundName($pac);
        $target = null;
        foreach ($c['proxy-groups'] as $gk => $group) {
            if (!is_array($group)) {
                continue;
            }
            $name = (string) ($group['name'] ?? '');
            if ($name === 'PROXY' || $name === $outbound) {
                $target = $gk;
                break;
            }
        }
        if ($target === null) {
            foreach ($c['proxy-groups'] as $gk => $group) {
                if (is_array($group)) {
                    $target = $gk;
                    break;
                }
            }
        }
        if ($target === null) {
            return;
        }

        $c['proxy-groups'][$target]['type'] = $type;
        if (in_array($type, ['url-test', 'fallback', 'load-balance'], true)) {
            if (empty($c['proxy-groups'][$target]['url'])) {
                $c['proxy-groups'][$target]['url'] = $this->getProxyGroupHealthUrl($pac);
            }
            if (empty($c['proxy-groups'][$target]['interval'])) {
                $c['proxy-groups'][$target]['interval'] = $this->getProxyGroupInterval($pac);
            }
            if (!array_key_exists('lazy', $c['proxy-groups'][$target])) {
                $c['proxy-groups'][$target]['lazy'] = true;
            }
        } else {
            unset(
                $c['proxy-groups'][$target]['url'],
                $c['proxy-groups'][$target]['interval'],
                $c['proxy-groups'][$target]['lazy'],
                $c['proxy-groups'][$target]['tolerance'],
                $c['proxy-groups'][$target]['strategy']
            );
        }
    }

    protected function isClashAutoTransportsEnabled(array $template): bool
    {
        if (!array_key_exists('auto-transports', $template)) {
            return true;
        }

        return !empty($template['auto-transports']);
    }

    protected function stripClashTemplateMetaKeys(array &$c): void
    {
        unset($c['auto-transports'], $c['vpnbot-app-groups']);
    }

    protected function slugClashAppGroupProvider(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('~[^a-z0-9]+~', '_', $slug) ?? '';
        $slug = trim($slug, '_');

        return $slug !== '' ? $slug : 'app';
    }

    /**
     * Parse "Name|https://…/file.mrs" (pipe or whitespace-separated URL).
     *
     * @return array{name: string, url: string}|null
     */
    protected function parseClashAppGroupLine(string $line): ?array
    {
        $line = trim($line);
        if ($line === '') {
            return null;
        }
        if (str_contains($line, '|')) {
            [$name, $url] = array_map('trim', explode('|', $line, 2));
        } elseif (preg_match('~^(\S+)\s+(https?://\S+)$~i', $line, $m)) {
            $name = $m[1];
            $url = $m[2];
        } else {
            return null;
        }
        if ($name === '' || !preg_match('~^https?://.+\.(mrs|yaml|yml)(\?.*)?$~i', $url)) {
            return null;
        }
        if (in_array(strtoupper($name), ['PROXY', 'DIRECT', 'REJECT', 'GLOBAL', 'PASS', 'MATCH'], true)) {
            return null;
        }

        return ['name' => $name, 'url' => $url];
    }

    /**
     * @param list<array{name: string, url: string, interval?: int, behavior?: string}> $groups
     * @return list<array{name: string, provider: string, url: string, interval: int, behavior: string}>
     */
    protected function normalizeClashAppGroups(array $groups): array
    {
        $out = [];
        $usedProviders = [];
        foreach ($groups as $g) {
            if (!is_array($g)) {
                continue;
            }
            $name = trim((string) ($g['name'] ?? ''));
            $url = trim((string) ($g['url'] ?? ''));
            if ($name === '' || $url === '') {
                continue;
            }
            $provider = trim((string) ($g['provider'] ?? ''));
            if ($provider === '') {
                $provider = $this->slugClashAppGroupProvider($name);
            }
            $base = $provider;
            $i = 2;
            while (isset($usedProviders[$provider])) {
                $provider = $base . '_' . $i;
                $i++;
            }
            $usedProviders[$provider] = true;
            $format = 'mrs';
            if (preg_match('~\.(yaml|yml)(\?.*)?$~i', $url)) {
                $format = 'yaml';
            }
            $behavior = strtolower(trim((string) ($g['behavior'] ?? 'domain')));
            if (!in_array($behavior, ['domain', 'ipcidr', 'classical'], true)) {
                $behavior = $format === 'mrs' ? 'domain' : 'classical';
            }
            $interval = (int) ($g['interval'] ?? 86400);
            if ($interval <= 0) {
                $interval = 86400;
            }
            $out[] = [
                'name' => $name,
                'provider' => $provider,
                'url' => $url,
                'interval' => $interval,
                'behavior' => $behavior,
                'format' => $format,
            ];
        }

        return $out;
    }

    /**
     * @return list<array{name: string, provider: string, url: string, interval: int, behavior: string, format?: string}>
     */
    protected function extractClashAppGroupsFromTemplate(array $template): array
    {
        $meta = $template['vpnbot-app-groups'] ?? null;
        if (is_array($meta) && $meta !== []) {
            return $this->normalizeClashAppGroups($meta);
        }

        return [];
    }

    /**
     * Insert app RULE-SET rules after REJECT/block, before pac/subnet/warp/process/package/MATCH.
     *
     * @param list<array{name: string, provider: string, url: string, interval: int, behavior: string, format?: string}> $appGroups
     */
    protected function patchClashTemplateAppGroups(array $template, array $appGroups): array
    {
        $appGroups = $this->normalizeClashAppGroups($appGroups);
        $template['vpnbot-app-groups'] = array_map(static function (array $g): array {
            return [
                'name' => $g['name'],
                'provider' => $g['provider'],
                'url' => $g['url'],
                'interval' => $g['interval'],
                'behavior' => $g['behavior'],
            ];
        }, $appGroups);

        $appNames = [];
        foreach ($appGroups as $g) {
            $appNames[$g['name']] = true;
        }

        $groups = [];
        foreach (($template['proxy-groups'] ?? []) as $group) {
            if (!is_array($group)) {
                continue;
            }
            $name = (string) ($group['name'] ?? '');
            if ($name !== '' && isset($appNames[$name])) {
                continue;
            }
            $groups[] = $group;
        }

        $members = ['PROXY', 'DIRECT'];
        foreach (($template['proxies'] ?? []) as $proxy) {
            $pn = trim((string) ($proxy['name'] ?? ''));
            if ($pn !== '' && !in_array($pn, $members, true)) {
                $members[] = $pn;
            }
        }

        foreach ($appGroups as $g) {
            $groups[] = [
                'name' => $g['name'],
                'type' => 'select',
                'proxies' => $members,
            ];
        }
        $template['proxy-groups'] = $groups;

        $providers = is_array($template['rule-providers'] ?? null) ? $template['rule-providers'] : [];
        $appProviderIds = [];
        foreach ($appGroups as $g) {
            $appProviderIds[$g['provider']] = true;
            $providers[$g['provider']] = [
                'type' => 'http',
                'behavior' => $g['behavior'],
                'format' => $g['format'] ?? 'mrs',
                'url' => $g['url'],
                'interval' => $g['interval'],
            ];
        }
        $template['rule-providers'] = $providers;

        $existing = is_array($template['rules'] ?? null) ? $template['rules'] : [];
        $head = [];
        $tail = [];
        $matchRule = ['type' => 'MATCH', 'action' => 'DIRECT'];
        foreach ($existing as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $type = strtoupper((string) ($rule['type'] ?? ''));
            if ($type === 'MATCH') {
                $matchRule = $rule;
                continue;
            }
            if ($type === 'RULE-SET') {
                $list = (string) ($rule['list'] ?? $rule['name'] ?? '');
                $action = (string) ($rule['action'] ?? '');
                $name = (string) ($rule['name'] ?? '');
                if (isset($appProviderIds[$list]) || isset($appProviderIds[$name]) || isset($appNames[$action])) {
                    continue;
                }
            }
            $action = strtoupper((string) ($rule['action'] ?? ''));
            $name = strtolower((string) ($rule['name'] ?? ''));
            $isReject = ($action === 'REJECT' || $name === 'block');
            if ($isReject) {
                $head[] = $rule;
            } else {
                $tail[] = $rule;
            }
        }

        $appRules = [];
        foreach ($appGroups as $g) {
            $appRules[] = [
                'type' => 'RULE-SET',
                'list' => $g['provider'],
                'action' => $g['name'],
                'name' => $g['provider'],
                'interval' => $g['interval'],
                'behavior' => $g['behavior'],
            ];
        }

        $template['rules'] = array_merge($head, $appRules, $tail, [$matchRule]);
        $template['add-rule-providers'] = false;

        return $template;
    }

    /**
     * Remove app groups previously stored in meta, then apply new list.
     *
     * @param list<array{name: string, url: string, interval?: int, behavior?: string}> $appGroups
     */
    protected function replaceClashTemplateAppGroups(array $template, array $appGroups): array
    {
        $old = $this->extractClashAppGroupsFromTemplate($template);
        $oldNames = [];
        $oldProviders = [];
        foreach ($old as $g) {
            $oldNames[$g['name']] = true;
            $oldProviders[$g['provider']] = true;
        }

        if (!empty($template['proxy-groups']) && is_array($template['proxy-groups'])) {
            $template['proxy-groups'] = array_values(array_filter(
                $template['proxy-groups'],
                static function ($group) use ($oldNames): bool {
                    if (!is_array($group)) {
                        return false;
                    }
                    $name = (string) ($group['name'] ?? '');

                    return $name === '' || !isset($oldNames[$name]);
                }
            ));
        }

        if (!empty($template['rule-providers']) && is_array($template['rule-providers'])) {
            foreach (array_keys($template['rule-providers']) as $key) {
                if (isset($oldProviders[$key])) {
                    unset($template['rule-providers'][$key]);
                }
            }
        }

        if (!empty($template['rules']) && is_array($template['rules'])) {
            $template['rules'] = array_values(array_filter(
                $template['rules'],
                static function ($rule) use ($oldNames, $oldProviders): bool {
                    if (!is_array($rule)) {
                        return false;
                    }
                    if (strtoupper((string) ($rule['type'] ?? '')) !== 'RULE-SET') {
                        return true;
                    }
                    $list = (string) ($rule['list'] ?? $rule['name'] ?? '');
                    $action = (string) ($rule['action'] ?? '');
                    $name = (string) ($rule['name'] ?? '');
                    if (isset($oldProviders[$list]) || isset($oldProviders[$name]) || isset($oldNames[$action])) {
                        return false;
                    }

                    return true;
                }
            ));
        }

        unset($template['vpnbot-app-groups']);

        return $this->patchClashTemplateAppGroups($template, $appGroups);
    }

    /**
     * Build a new clash template from origin + wizard options.
     *
     * @param list<array{name: string, url: string, interval?: int, behavior?: string}> $appGroups
     */
    protected function buildClashTemplateFromWizard(array $opts = []): array
    {
        $originPath = '/config/clash.json';
        $base = [];
        if (is_readable($originPath)) {
            $decoded = json_decode((string) file_get_contents($originPath), true);
            if (is_array($decoded)) {
                $base = $decoded;
            }
        }
        if ($base === []) {
            $base = [
                'proxies' => [[
                    'name' => '~outbound~',
                    'type' => 'vless',
                    'server' => '~reality_server_host~',
                    'port' => '~port_reality~',
                    'uuid' => '~uid~',
                    'network' => 'tcp',
                    'udp' => true,
                    'tls' => true,
                ]],
                'proxy-groups' => [[
                    'name' => 'PROXY',
                    'type' => 'select',
                    'proxies' => ['~outbound~'],
                ]],
                'rules' => [['type' => 'MATCH', 'action' => 'DIRECT']],
            ];
        }

        if (array_key_exists('auto-transports', $opts)) {
            $base['auto-transports'] = !empty($opts['auto-transports']);
        }
        $proxyType = strtolower(trim((string) ($opts['proxy_group_type'] ?? '')));
        if (in_array($proxyType, ['select', 'url-test', 'fallback', 'load-balance'], true)) {
            foreach ($base['proxy-groups'] as $i => $group) {
                if (($group['name'] ?? '') === 'PROXY') {
                    $base['proxy-groups'][$i]['type'] = $proxyType;
                    if (in_array($proxyType, ['url-test', 'fallback', 'load-balance'], true)) {
                        if (empty($base['proxy-groups'][$i]['url'])) {
                            $base['proxy-groups'][$i]['url'] = $this->getProxyGroupHealthUrl();
                        }
                        if (empty($base['proxy-groups'][$i]['interval'])) {
                            $base['proxy-groups'][$i]['interval'] = $this->getProxyGroupInterval();
                        }
                    }
                    break;
                }
            }
        }

        $appGroups = is_array($opts['app_groups'] ?? null) ? $opts['app_groups'] : [];

        return $this->replaceClashTemplateAppGroups($base, $appGroups);
    }

    protected function formatClashAppGroupsSaveHint(array $appGroups): string
    {
        $names = [];
        foreach ($this->normalizeClashAppGroups($appGroups) as $g) {
            $names[] = $g['name'];
        }
        if ($names === []) {
            return $this->i18n('clash_app_groups_saved_empty');
        }

        return sprintf(
            $this->i18n('clash_app_groups_saved'),
            htmlspecialchars(implode(', ', $names), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        );
    }

    protected function resolveClashRealityMeta(array $xr, array $pac, string $domain): array
    {
        $realityInbound = null;
        foreach (($xr['inbounds'] ?? []) as $inbound) {
            if (($inbound['streamSettings']['security'] ?? '') === 'reality') {
                $realityInbound = $inbound;
                break;
            }
        }
        $realitySettings = $realityInbound['streamSettings']['realitySettings'] ?? [];
        $fallbackRealitySettings = $xr['inbounds'][0]['streamSettings']['realitySettings'] ?? [];
        $realityShortId = $realitySettings['shortIds'][0]
            ?? $fallbackRealitySettings['shortIds'][0]
            ?? '';
        $realityServerName = $realitySettings['serverNames'][0]
            ?? $fallbackRealitySettings['serverNames'][0]
            ?? $domain;
        $realityBridgeServer = $this->normalizeRealityTarget((string) ($pac['reality']['bridge_server'] ?? ''));
        if ($realityBridgeServer === '') {
            $realityBridgeServer = $this->normalizeRealityTarget($domain . ':443');
        }
        [$realityServerHost, $realityServerPort] = $this->splitEndpointHostPort($realityBridgeServer);
        if ($realityServerHost === '') {
            $realityServerHost = $domain;
        }
        if ($realityServerPort <= 0) {
            $realityServerPort = 443;
        }
        $realityDest = (string) ($realitySettings['dest']
            ?? $fallbackRealitySettings['dest']
            ?? ($pac['reality']['destination'] ?? ''));

        return [
            'short_id' => $realityShortId,
            'server_name' => $realityServerName,
            'server_host' => $realityServerHost,
            'server_port' => $realityServerPort,
            'dest' => $realityDest,
        ];
    }

    protected function buildClashTemplateTags(
        array $pac,
        array $client,
        string $domain,
        string $uid,
        string $email,
        string $subscriptionId,
        string $outbound,
        array $realityMeta
    ): array {
        $hash = $this->getHashBot();
        $scheme = empty($this->nginxGetTypeCert()) ? 'http' : 'https';
        $flags = $this->getClientTransportFlags($client, $pac);
        $dnsDomains = $this->getDnsDomainsForOutput($pac);
        if ($dnsDomains === []) {
            $dnsDomains = [$domain];
        }
        $dnsUrls = [];
        foreach ($dnsDomains as $dnsDomain) {
            $dnsDomain = trim((string) $dnsDomain);
            if ($dnsDomain === '') {
                continue;
            }
            $dnsUrls[] = "{$scheme}://{$dnsDomain}/dns-query{$hash}/{$uid}";
        }
        $aliases = $this->getDomainAliasesFromConfig($pac);
        $domainAlt = trim((string) ($aliases[0] ?? ''));
        if ($domainAlt === '') {
            $domainAlt = trim((string) ($pac['linkdomain'] ?? ''));
        }
        if ($domainAlt === '') {
            $domainAlt = $domain;
        }
        $wgPort = (int) getenv($this->getInstanceWG(1) ? 'WG1PORT' : 'WGPORT');
        if ($wgPort <= 0) {
            $wgPort = 51821;
        }
        $wgEndpoint = trim((string) ($pac['hwid_runtime_wg_endpoint'] ?? ''));
        if ($wgEndpoint === '') {
            $wgServer = $this->getDomain();
        } else {
            [$wgServer] = $this->splitEndpointHostPort(preg_replace('~^\w+://~', '', $wgEndpoint));
            if ($wgServer === '') {
                $wgServer = $this->getDomain();
            }
        }

        $tags = [
            '~outbound~' => $outbound,
            '~uid~' => $uid,
            '~email~' => $email,
            '~subscription_id~' => $subscriptionId,
            '~domain~' => $domain,
            '~directdomain~' => (string) ($pac['domain'] ?? ''),
            '~domain_alt~' => $domainAlt,
            '~cdndomain~' => (string) ($pac['linkdomain'] ?? ''),
            '~ip~' => (string) $this->ip,
            '~hash~' => $hash,
            '~ws_path~' => $this->getWsTransportPath($hash),
            '~xhttp_path~' => $this->getXhttpTransportPath($hash),
            '~hy_path~' => $this->getHyTransportPath($hash),
            '~dnspath~' => "/dns-query{$hash}/{$uid}",
            '~dns~' => "{$scheme}://{$domain}/dns-query{$hash}/{$uid}",
            '~public_key~' => (string) ($pac['xray'] ?? ''),
            '~short_id~' => (string) ($realityMeta['short_id'] ?? ''),
            '~server_name~' => (string) ($realityMeta['server_name'] ?? $domain),
            '~reality_server_host~' => (string) ($realityMeta['server_host'] ?? $domain),
            '~reality_dest~' => (string) ($realityMeta['dest'] ?? ''),
            '~hy_password~' => (string) ($pac['hysteria_pass'] ?? ''),
            '~wg_server~' => $wgServer,
            '"~pac~"' => json_encode(array_keys(array_filter($pac['includelist'] ?? []))),
            '"~block~"' => json_encode(array_keys(array_filter($pac['blocklist'] ?? []))),
            '"~warp~"' => json_encode(array_keys(array_filter($pac['warplist'] ?? []))),
            '"~process~"' => json_encode(array_keys(array_filter($pac['processlist'] ?? []))),
            '"~package~"' => json_encode(array_keys(array_filter($pac['packagelist'] ?? []))),
            '"~subnet~"' => json_encode(array_keys(array_filter($pac['subnetlist'] ?? []))),
            '"~dns_domains~"' => json_encode(array_values($dnsDomains), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            '"~dns_urls~"' => json_encode(array_values($dnsUrls), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            '"~reality_server_port~"' => (int) ($realityMeta['server_port'] ?? 443),
            '"~port_ws~"' => $this->getTransportClientPort('ws', $pac),
            '"~port_xhttp~"' => $this->getTransportClientPort('xhttp', $pac),
            '"~port_reality~"' => $this->getTransportClientPort('reality', $pac),
            '"~port_hy~"' => $this->getHysteriaListenPort(),
            '"~wg_port~"' => $wgPort,
            '~port_ws~' => (string) $this->getTransportClientPort('ws', $pac),
            '~port_xhttp~' => (string) $this->getTransportClientPort('xhttp', $pac),
            '~port_reality~' => (string) $this->getTransportClientPort('reality', $pac),
            '~port_hy~' => (string) $this->getHysteriaListenPort(),
            '~wg_port~' => (string) $wgPort,
            '"~transport_ws~"' => !empty($flags['ws']) ? 1 : 0,
            '"~transport_xhttp~"' => !empty($flags['xhttp']) ? 1 : 0,
            '"~transport_reality~"' => !empty($flags['reality']) ? 1 : 0,
            '"~transport_hy~"' => !empty($flags['hysteria']) ? 1 : 0,
            '"~transport_awg~"' => ($this->isRuntimeDeviceWgEnabled($client) ? 1 : 0),
            '"~mirror_hosts~"' => json_encode(array_values(array_map(static function (array $mirror): string {
                return $mirror['port'] ? $mirror['host'] . ':' . $mirror['port'] : $mirror['host'];
            }, $this->getEnabledDnatMirrors($pac))), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            '~proxy_suffix_ws~' => $this->getClashTransportSuffix('ws', $pac),
            '~proxy_suffix_xhttp~' => $this->getClashTransportSuffix('xhttp', $pac),
            '~proxy_suffix_hy2~' => $this->getClashTransportSuffix('hy2', $pac),
            '~client_fingerprint~' => $this->getClientFingerprint($pac),
            '~proxy_group_type~' => $this->getProxyGroupType($pac),
        ];

        return $tags;
    }

    protected function getClashTemplateHelpHtml(): string
    {
        return ClashTemplatePlaceholders::helpHtml();
    }
}
