<?php

trait TransportRegistryTrait
{
    protected function normalizeTransportRegistry(array $conf): array
    {
        $fallback = [
            'reality' => 0,
            'ws' => 1,
            'xhttp' => 0,
            'hysteria' => !empty($conf['hysteria']) ? 1 : 0,
            'awg' => !empty($conf['wg1']) ? 1 : 0,
        ];
        $legacy = (string) ($conf['transport'] ?? 'Websocket');
        if ($legacy === 'Reality') {
            $fallback['reality'] = 1;
            $fallback['ws'] = 0;
            $fallback['xhttp'] = 0;
        } elseif ($legacy === 'xhttp') {
            $fallback['reality'] = 0;
            $fallback['ws'] = 0;
            $fallback['xhttp'] = 1;
        } elseif ($legacy === 'Both') {
            $fallback['reality'] = 1;
            $fallback['ws'] = 1;
            $fallback['xhttp'] = 0;
        }
        $registry = $conf['transport_registry'] ?? [];
        $global = is_array($registry['global'] ?? null) ? $registry['global'] : [];
        foreach ($fallback as $k => $v) {
            $global[$k] = !empty($global[$k]) ? 1 : (int) $v;
        }
        $users = is_array($registry['users'] ?? null) ? $registry['users'] : [];
        $portDefaults = [
            'ws' => 443,
            'xhttp' => 8443,
            'reality' => 33443,
        ];
        $ports = is_array($registry['ports'] ?? null) ? $registry['ports'] : [];
        foreach ($portDefaults as $k => $defaultPort) {
            $value = (int) ($ports[$k] ?? $defaultPort);
            $ports[$k] = $value > 0 ? $value : $defaultPort;
        }
        $conf['transport_registry'] = [
            'global' => $global,
            'users' => $users,
            'ports' => $ports,
        ];

        return $conf;
    }

    protected function getTransportRegistryPorts(?array $pac = null): array
    {
        $pac = $pac ?? $this->getPacConf();
        $pac = $this->normalizeTransportRegistry($pac);

        return $pac['transport_registry']['ports'] ?? [
            'ws' => 443,
            'xhttp' => 8443,
            'reality' => 33443,
        ];
    }

    protected function getTransportClientPort(string $transport, ?array $pac = null): int
    {
        $ports = $this->getTransportRegistryPorts($pac);
        if ($transport === 'xhttp' || $transport === 'ws' || $transport === 'reality') {
            return 443;
        }

        return (int) ($ports[$transport] ?? 443);
    }

    protected function getWsInboundPort(?array $pac = null): int
    {
        $ports = $this->getTransportRegistryPorts($pac);

        return (int) $ports['ws'];
    }

    protected function getTransportRegistryGlobal(array $pac): array
    {
        $pac = $this->normalizeTransportRegistry($pac);

        return $pac['transport_registry']['global'] ?? [];
    }

    protected function getClientTransportFlags(array $client, array $pac): array
    {
        $global = $this->getTransportRegistryGlobal($pac);
        $subId = $this->getClientSubscriptionId($client);
        $overrides = [];
        if ($subId !== '' && is_array($pac['transport_registry']['users'][$subId] ?? null)) {
            $overrides = $pac['transport_registry']['users'][$subId];
        }
        foreach ($global as $k => $v) {
            if (array_key_exists($k, $overrides)) {
                $global[$k] = !empty($overrides[$k]) ? 1 : 0;
            }
        }

        return $global;
    }

    protected function hasEnabledXrayTransport(array $flags): bool
    {
        return !empty($flags['reality']) || !empty($flags['ws']) || !empty($flags['xhttp']);
    }

    protected function getWsTransportPath(string $hash = ''): string
    {
        return '/ws' . ($hash !== '' ? $hash : $this->getHashBot());
    }

    protected function getXhttpTransportPath(string $hash = ''): string
    {
        return '/xh' . ($hash !== '' ? $hash : $this->getHashBot());
    }

    protected function getXhttpInboundPort(?array $pac = null): int
    {
        $ports = $this->getTransportRegistryPorts($pac);

        return (int) $ports['xhttp'];
    }

    protected function getRealityInboundPort(?array $pac = null): int
    {
        $ports = $this->getTransportRegistryPorts($pac);

        return (int) $ports['reality'];
    }

    protected function buildXrayInboundsByRegistry(array $xray, array $pac): array
    {
        $h = $this->getHashBot();
        $global = $this->getTransportRegistryGlobal($pac);
        $ports = $this->getTransportRegistryPorts($pac);
        $clients = [];
        foreach (($xray['inbounds'] ?? []) as $inbound) {
            if (!empty($inbound['settings']['clients']) && is_array($inbound['settings']['clients'])) {
                $clients = $inbound['settings']['clients'];
                break;
            }
        }
        $sniffing = ['destOverride' => ['http', 'tls', 'quic'], 'enabled' => true];
        foreach (($xray['inbounds'] ?? []) as $inbound) {
            if (!empty($inbound['sniffing']) && is_array($inbound['sniffing'])) {
                $sniffing = $inbound['sniffing'];
                break;
            }
        }
        $apiInbound = ['listen' => '127.0.0.1', 'port' => 8080, 'protocol' => 'dokodemo-door', 'settings' => ['address' => '127.0.0.1'], 'tag' => 'api'];
        foreach (($xray['inbounds'] ?? []) as $inbound) {
            if (($inbound['tag'] ?? '') === 'api' || ($inbound['protocol'] ?? '') === 'dokodemo-door') {
                $apiInbound = $inbound;
                break;
            }
        }
        $apiInbound['listen'] = '127.0.0.1';
        $apiInbound['port'] = 8080;
        $apiInbound['protocol'] = 'dokodemo-door';
        $apiInbound['settings'] = ['address' => '127.0.0.1'];
        $apiInbound['tag'] = 'api';

        $inbounds = [];
        if (!empty($global['ws'])) {
            $inbounds[] = [
                'port' => $ports['ws'],
                'protocol' => 'vless',
                'settings' => ['clients' => $clients, 'decryption' => 'none'],
                'sniffing' => $sniffing,
                'streamSettings' => ['network' => 'ws', 'wsSettings' => ['path' => $this->getWsTransportPath($h)]],
                'tag' => 'vless_tls',
            ];
        }
        if (!empty($global['xhttp'])) {
            $inbounds[] = [
                'port' => $ports['xhttp'],
                'protocol' => 'vless',
                'settings' => ['clients' => $clients, 'decryption' => 'none'],
                'sniffing' => $sniffing,
                'streamSettings' => ['network' => 'xhttp', 'xhttpSettings' => ['mode' => 'auto', 'path' => $this->getXhttpTransportPath($h)]],
                'tag' => 'vless_xhttp',
            ];
        }
        if (!empty($global['reality'])) {
            $inbounds[] = [
                'port' => $ports['reality'],
                'protocol' => 'vless',
                'settings' => ['clients' => $clients, 'decryption' => 'none'],
                'sniffing' => $sniffing,
                'streamSettings' => [
                    'network' => 'tcp',
                    'realitySettings' => [
                        'dest' => (string) ($pac['reality']['destination'] ?? (($pac['reality']['domain'] ?? 'yandex.ru') . ':443')),
                        'privateKey' => (string) ($pac['reality']['privateKey'] ?? ''),
                        'serverNames' => [(string) ($pac['reality']['domain'] ?? 'yandex.ru')],
                        'shortIds' => [(string) ($pac['reality']['shortId'] ?? '')],
                        'show' => false,
                        'xver' => 0,
                    ],
                    'tcpSettings' => ['acceptProxyProtocol' => true],
                    'sockopt' => ['acceptProxyProtocol' => true],
                    'security' => 'reality',
                ],
                'tag' => 'vless_reality',
            ];
        }
        if (empty($inbounds)) {
            $inbounds[] = [
                'port' => $ports['ws'],
                'protocol' => 'vless',
                'settings' => ['clients' => [], 'decryption' => 'none'],
                'sniffing' => $sniffing,
                'streamSettings' => ['network' => 'ws', 'wsSettings' => ['path' => $this->getWsTransportPath($h)]],
                'tag' => 'vless_tls',
            ];
        }
        $inbounds[] = $apiInbound;

        return $inbounds;
    }

    protected function applyTransportRegistryAndRuntime(): void
    {
        $pac = $this->getPacConf();
        $pac = $this->normalizeTransportRegistry($pac);
        $global = $this->getTransportRegistryGlobal($pac);
        $x = $this->getXray();
        $x['inbounds'] = $this->buildXrayInboundsByRegistry($x, $pac);
        $this->setUpstreamDomain($this->getUpstreamRealityDomain($pac, $x));
        $this->setUpstreamRealityPort(!empty($global['reality']) ? $this->getRealityInboundPort($pac) : $this->getWsInboundPort($pac));
        $this->setPacConf($pac);
        $this->restartXray($x);
        $this->cloakNginx();
        $this->restartHysteria();
        if (empty($global['awg'])) {
            $this->ssh('wg-quick down wg0 || true; awg-quick down wg0 || true', $this->getInstanceWG());
        } else {
            $prevWg = $this->wg ?? null;
            $this->wg = 1;
            try {
                $conf = $this->createConfig($this->readConfig());
                $this->restartWG($conf, true);
            } finally {
                if ($prevWg === null) {
                    unset($this->wg);
                } else {
                    $this->wg = $prevWg;
                }
            }
        }
    }
}
