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

    protected function getHysteriaListenPort(): int
    {
        $services = $this->getDockerComposeServices();
        $port = (int) explode(':', (string) ($services['hy']['ports'][0] ?? ''))[0];
        if ($port > 0) {
            return $port;
        }
        $pac = $this->getPacConf();
        if (!empty($this->getTransportRegistryGlobal($pac)['hysteria'])) {
            return 443;
        }

        return 0;
    }

    protected function buildClashHysteria2Proxy(string $baseName, string $domain, array $pac): ?array
    {
        $password = (string) ($pac['hysteria_pass'] ?? '');
        if ($password === '') {
            return null;
        }
        $port = $this->getHysteriaListenPort();
        if ($port <= 0) {
            return null;
        }

        return [
            'name' => $baseName . '-hy2',
            'type' => 'hysteria2',
            'server' => $domain,
            'port' => $port,
            'password' => $password,
            'sni' => $domain,
            'skip-cert-verify' => false,
            'alpn' => ['h3'],
        ];
    }

    protected function appendClashSubscriptionTransportProxies(array &$c, int $index, array $client, array $pac, string $domain): void
    {
        if (!isset($c['proxies'][$index]) || !is_array($c['proxies'][$index])) {
            return;
        }
        $flags = $this->getClientTransportFlags($client, $pac);
        $baseName = (string) ($c['proxies'][$index]['name'] ?? 'proxy');
        $existingNames = [];
        foreach ($c['proxies'] as $proxy) {
            if (is_array($proxy) && !empty($proxy['name'])) {
                $existingNames[(string) $proxy['name']] = true;
            }
        }
        if (!empty($flags['hysteria'])) {
            $hyProxy = $this->buildClashHysteria2Proxy($baseName, $domain, $pac);
            if (is_array($hyProxy)) {
                $hyName = (string) ($hyProxy['name'] ?? '');
                if ($hyName !== '' && empty($existingNames[$hyName])) {
                    $c['proxies'][] = $hyProxy;
                    $this->linkClashTransportProxyToGroups($c, $baseName, $hyName);
                }
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
            '~(/webapp|/pac|/adguard|/ws|/xh|/hy|location /dns-query)~',
            '${1}' . $hash,
            $template
        );
        if (empty($global['ws'])) {
            $template = $this->stripNginxLocationPrefix($template, '/ws' . $hash);
        }
        if (empty($global['xhttp'])) {
            $template = $this->stripNginxLocationPrefix($template, '/xh' . $hash);
        }
        if (empty($global['hysteria'])) {
            $template = $this->stripNginxLocationPrefix($template, '/hy' . $hash);
        }

        return $template;
    }

    public function applyHysteriaUpstreamRuntime(array $pac, bool $reload = true): void
    {
        $enabled = !empty($this->getTransportRegistryGlobal($pac)['hysteria']);
        $path = '/config/upstream.conf';
        if (!is_readable($path)) {
            return;
        }
        $nginx = (string) file_get_contents($path);
        $changed = false;

        if ($enabled) {
            $hysteriaBlock = "    upstream hysteria {\n        server 10.10.0.17:443;\n    }\n\n";
            if (!str_contains($nginx, 'upstream hysteria')) {
                $replaced = preg_replace('~(upstream\s+reality\s*\{[^}]*\}\s*)~s', '$1' . "\n" . $hysteriaBlock, $nginx, 1, $count);
                if ($count > 0 && $replaced !== null) {
                    $nginx = $replaced;
                    $changed = true;
                }
            } else {
                $normalized = preg_replace(
                    '~upstream\s+hysteria\s*\{[^}]*\}~s',
                    trim($hysteriaBlock),
                    $nginx,
                    1,
                    $count
                );
                if ($count > 0 && $normalized !== null && $normalized !== $nginx) {
                    $nginx = $normalized;
                    $changed = true;
                }
            }
        } else {
            $stripped = preg_replace('~\n\s*upstream\s+hysteria\s*\{[^}]*\}\s*~s', "\n", $nginx, 1, $count);
            if ($count > 0 && $stripped !== null) {
                $nginx = $stripped;
                $changed = true;
            }
        }

        $hysteriaUdp = <<<'NGINX'

    server {
        listen          443 udp reuseport;
        proxy_pass      hysteria;
        proxy_protocol  on;
    }
NGINX;
        $fallbackUdp = <<<'NGINX'

    server {
        listen          443 udp reuseport;
        proxy_pass      other;
        proxy_protocol  on;
    }
NGINX;
        $pattern = '~\n\s*server\s*\{[^{}]*listen\s+443\s+udp[^{}]*\}\s*~s';
        $replacement = $enabled ? $hysteriaUdp : $fallbackUdp;
        $updated = preg_replace($pattern, $replacement, $nginx, 1, $udpCount);
        if ($udpCount > 0 && $updated !== null && $updated !== $nginx) {
            $nginx = $updated;
            $changed = true;
        }

        if (!$changed) {
            return;
        }
        file_put_contents($path, $nginx);
        if ($reload) {
            $this->ssh('nginx -s reload 2>&1', 'up');
        }
    }

    public function syncUpstreamRuntime(?array $xray = null, bool $reload = true): void
    {
        $this->normalizeUpstreamComposeIps();
        $pac = $this->getPacConf();
        $x = $xray ?? $this->getXray();
        $global = $this->getTransportRegistryGlobal($pac);
        $this->applyHysteriaUpstreamRuntime($pac, $reload);
        $this->setUpstreamDomain($this->getUpstreamRealityDomain($pac, $x), $reload);
        $this->setUpstreamRealityPort(!empty($global['reality']) ? $this->getRealityInboundPort($pac) : $this->getWsInboundPort($pac), $reload);
    }

    protected function normalizeUpstreamComposeIps(): void
    {
        $path = '/config/upstream.conf';
        if (!is_readable($path)) {
            return;
        }
        $nginx = (string) file_get_contents($path);
        $normalized = str_replace('server ng:443;', 'server 10.10.0.2:443;', $nginx);
        $normalized = preg_replace('~server xr:(\d+);~', 'server 10.10.0.9:$1;', $normalized) ?? $normalized;
        $normalized = str_replace('server hy:443;', 'server 10.10.0.17:443;', $normalized);
        if ($normalized !== $nginx) {
            file_put_contents($path, $normalized);
        }
    }
}
