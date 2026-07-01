<?php

trait TransportRuntimeTrait
{
    protected function appendClashCompanionTransportProxy(array &$c, int $index, array $client, array $pac, string $domain, string $uid): void
    {
        $flags = $this->getClientTransportFlags($client, $pac);
        if (empty($flags['ws']) || empty($flags['xhttp'])) {
            return;
        }
        if (!isset($c['proxies'][$index]) || !is_array($c['proxies'][$index])) {
            return;
        }
        $baseName = (string) ($c['proxies'][$index]['name'] ?? 'proxy');
        $hash = $this->getHashBot();
        $existingNetworks = [];
        foreach ($c['proxies'] as $proxy) {
            if (!is_array($proxy)) {
                continue;
            }
            $network = (string) ($proxy['network'] ?? '');
            if ($network !== '') {
                $existingNetworks[$network] = (string) ($proxy['name'] ?? '');
            }
        }
        if (!isset($existingNetworks['ws'])) {
            $wsProxy = $this->buildClashWsTransportProxy($baseName, $domain, $uid, $hash);
            $c['proxies'][] = $wsProxy;
            $this->linkClashTransportProxyToGroups($c, $baseName, (string) ($wsProxy['name'] ?? ''));
        }
        if (!isset($existingNetworks['xhttp'])) {
            $xhttpProxy = $this->buildClashXhttpTransportProxy($baseName, $domain, $uid, $hash);
            $c['proxies'][] = $xhttpProxy;
            $this->linkClashTransportProxyToGroups($c, $baseName, (string) ($xhttpProxy['name'] ?? ''));
        }
    }

    protected function patchXrayInboundTransportPaths(array &$xray, ?string $hash = null): bool
    {
        $hash = $hash ?? $this->getHashBot();
        $expectedWs = $this->getWsTransportPath($hash);
        $expectedXh = $this->getXhttpTransportPath($hash);
        $changed = false;
        foreach (($xray['inbounds'] ?? []) as $idx => $inbound) {
            if (!is_array($inbound)) {
                continue;
            }
            $network = (string) ($inbound['streamSettings']['network'] ?? '');
            if ($network === 'ws') {
                $current = (string) ($inbound['streamSettings']['wsSettings']['path'] ?? '');
                if ($current !== $expectedWs) {
                    $xray['inbounds'][$idx]['streamSettings']['wsSettings']['path'] = $expectedWs;
                    $changed = true;
                }
            }
            if ($network === 'xhttp') {
                if (!isset($xray['inbounds'][$idx]['streamSettings']['xhttpSettings']) || !is_array($xray['inbounds'][$idx]['streamSettings']['xhttpSettings'])) {
                    $xray['inbounds'][$idx]['streamSettings']['xhttpSettings'] = ['mode' => 'auto'];
                    $changed = true;
                }
                $current = (string) ($inbound['streamSettings']['xhttpSettings']['path'] ?? '');
                if ($current !== $expectedXh) {
                    $xray['inbounds'][$idx]['streamSettings']['xhttpSettings']['path'] = $expectedXh;
                    $changed = true;
                }
            }
        }

        return $changed;
    }

    protected function writeAndReloadNginx(string $template): bool
    {
        $livePath = '/config/nginx.conf';
        $previous = is_file($livePath) ? (string) file_get_contents($livePath) : '';
        file_put_contents($livePath, $template);
        $output = trim((string) $this->ssh('nginx -t 2>&1', 'ng'));
        $ok = stripos($output, 'syntax is ok') !== false || stripos($output, 'test is successful') !== false;
        if (!$ok) {
            if ($previous !== '') {
                file_put_contents($livePath, $previous);
            }

            return false;
        }
        $this->ssh('nginx -s reload', 'ng');

        return true;
    }

    protected function buildClashWsTransportProxy(string $baseName, string $domain, string $uid, string $hash): array
    {
        return [
            'name' => $baseName . '-ws',
            'type' => 'vless',
            'server' => $domain,
            'port' => 443,
            'uuid' => $uid,
            'network' => 'ws',
            'udp' => true,
            'tls' => true,
            'servername' => $domain,
            'client-fingerprint' => 'chrome',
            'ws-opts' => [
                'path' => $this->getWsTransportPath($hash),
            ],
        ];
    }

    protected function buildClashXhttpTransportProxy(string $baseName, string $domain, string $uid, string $hash): array
    {
        return [
            'name' => $baseName . '-xhttp',
            'type' => 'vless',
            'server' => $domain,
            'port' => 443,
            'uuid' => $uid,
            'network' => 'xhttp',
            'udp' => true,
            'tls' => true,
            'servername' => $domain,
            'client-fingerprint' => 'chrome',
            'xhttp-opts' => [
                'path' => $this->getXhttpTransportPath($hash),
                'mode' => 'packet-up',
            ],
        ];
    }

    protected function linkClashTransportProxyToGroups(array &$c, string $baseName, string $proxyName): void
    {
        if ($proxyName === '' || empty($c['proxy-groups']) || !is_array($c['proxy-groups'])) {
            return;
        }
        foreach ($c['proxy-groups'] as $gk => $group) {
            if (empty($group['proxies']) || !is_array($group['proxies'])) {
                continue;
            }
            if ($baseName !== '' && in_array($baseName, $group['proxies'], true) && !in_array($proxyName, $group['proxies'], true)) {
                $c['proxy-groups'][$gk]['proxies'][] = $proxyName;
            }
        }
    }

    protected function stripNginxLocationPrefix(string $template, string $prefix): string
    {
        $pattern = '~\n\s*location\s+' . preg_quote($prefix, '~') . '[^\n]*\n.*?\n\s*}\s*~s';

        return preg_replace($pattern, "\n", $template) ?? $template;
    }

    protected function applyTransportAwareNginxTemplate(string $template, array $pac): string
    {
        $global = $this->getTransportRegistryGlobal($pac);
        $hash = $this->getHashBot();
        $template = preg_replace(
            '~(/webapp|/pac|/adguard|/ws|/xh|location /dns-query)~',
            '${1}' . $hash,
            $template
        );
        if (empty($global['ws'])) {
            $template = $this->stripNginxLocationPrefix($template, '/ws' . $hash);
        }
        if (empty($global['xhttp'])) {
            $template = $this->stripNginxLocationPrefix($template, '/xh' . $hash);
        }

        return $template;
    }
}
