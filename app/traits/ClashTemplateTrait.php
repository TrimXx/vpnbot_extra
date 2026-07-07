<?php

require_once __DIR__ . '/../ClashTemplatePlaceholders.php';

trait ClashTemplateTrait
{
    protected function isClashAutoTransportsEnabled(array $template): bool
    {
        if (!array_key_exists('auto-transports', $template)) {
            return true;
        }

        return !empty($template['auto-transports']);
    }

    protected function stripClashTemplateMetaKeys(array &$c): void
    {
        unset($c['auto-transports']);
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
        ];

        return $tags;
    }

    protected function getClashTemplateHelpHtml(): string
    {
        return ClashTemplatePlaceholders::helpHtml();
    }
}
