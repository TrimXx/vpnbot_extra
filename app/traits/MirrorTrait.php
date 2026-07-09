<?php

trait MirrorTrait
{
    protected function getDefaultClashProxySuffixes(): array
    {
        return [
            'ws'    => '-ws',
            'xhttp' => '-xhttp',
            'hy2'   => '-hy2',
        ];
    }

    protected function getClashTransportSuffix(string $transport, ?array $pac = null): string
    {
        $pac = $pac ?? $this->getPacConf();
        $defaults = $this->getDefaultClashProxySuffixes();
        $custom = $pac['clash_proxy_suffixes'] ?? [];
        if (!is_array($custom)) {
            $custom = [];
        }

        return (string) ($custom[$transport] ?? $defaults[$transport] ?? '');
    }

    protected function getMainClashOutboundName(?array $pac = null): string
    {
        $pac = $pac ?? $this->getPacConf();
        $name = trim((string) ($pac['outbound'] ?? ''));

        return $name !== '' ? $name : 'proxy';
    }

    /**
     * @return array<string, string> mirrorlist key => child node id
     */
    protected function getMirrorNodesMap(?array $pac = null): array
    {
        $pac = $pac ?? $this->getPacConf();
        $map = $pac['mirror_nodes'] ?? [];
        if (!is_array($map)) {
            return [];
        }
        $out = [];
        foreach ($map as $mirrorKey => $nodeId) {
            $mirrorKey = trim((string) $mirrorKey);
            $nodeId = trim((string) $nodeId);
            if ($mirrorKey !== '' && $nodeId !== '') {
                $out[$mirrorKey] = $nodeId;
            }
        }

        return $out;
    }

    protected function getMirrorlistKeyByIndex(int $index): ?string
    {
        $list = $this->getPacConf()['mirrorlist'] ?? [];
        if (!is_array($list)) {
            return null;
        }
        $i = 0;
        foreach ($list as $key => $enabled) {
            if ($i === $index) {
                return (string) $key;
            }
            $i++;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    protected function getNodeIdsBoundViaMirrors(?array $pac = null): array
    {
        $pac = $pac ?? $this->getPacConf();
        $list = $pac['mirrorlist'] ?? [];
        if (!is_array($list)) {
            return [];
        }
        $map = $this->getMirrorNodesMap($pac);
        $ids = [];
        foreach ($map as $mirrorKey => $nodeId) {
            if (!empty($list[$mirrorKey])) {
                $ids[] = $nodeId;
            }
        }

        return array_values(array_unique($ids));
    }

    protected function getMirrorNodeLabel(string $mirrorKey, ?array $pac = null): string
    {
        $pac = $pac ?? $this->getPacConf();
        $nodeId = $this->getMirrorNodesMap($pac)[$mirrorKey] ?? '';
        if ($nodeId === '') {
            return $this->i18n('mirror_mode_dnat');
        }
        $nodes = $pac['child_nodes'] ?? [];
        if (!is_array($nodes) || empty($nodes[$nodeId]) || !is_array($nodes[$nodeId])) {
            return $this->i18n('mirror_mode_node');
        }
        $name = trim((string) ($nodes[$nodeId]['name'] ?? $nodeId));

        return $this->i18n('mirror_mode_node') . ': ' . $name;
    }

    /**
     * @return list<array{key: string, host: string, port: ?int, label: string, node_id: ?string, node_domain: ?string}>
     */
    protected function getEnabledMirrors(?array $pac = null): array
    {
        $pac = $pac ?? $this->getPacConf();
        $list = $pac['mirrorlist'] ?? [];
        if (!is_array($list)) {
            return [];
        }
        $labels = $pac['mirror_labels'] ?? [];
        if (!is_array($labels)) {
            $labels = [];
        }
        $mirrorNodes = $this->getMirrorNodesMap($pac);
        $childNodes = $pac['child_nodes'] ?? [];
        if (!is_array($childNodes)) {
            $childNodes = [];
        }
        $mirrors = [];
        foreach ($list as $key => $enabled) {
            if (empty($enabled)) {
                continue;
            }
            $key = trim((string) $key);
            if ($key === '') {
                continue;
            }
            $nodeId = $mirrorNodes[$key] ?? null;
            $nodeDomain = null;
            if ($nodeId !== null && !empty($childNodes[$nodeId]) && is_array($childNodes[$nodeId])) {
                $nodeDomain = trim((string) ($childNodes[$nodeId]['domain'] ?? ''));
                if ($nodeDomain === '') {
                    $nodeId = null;
                }
            } elseif ($nodeId !== null) {
                $nodeId = null;
            }

            $parsed = $this->parseMirrorEndpoint($key);
            if ($parsed['host'] === '' && $nodeDomain === null) {
                continue;
            }
            $label = trim((string) ($labels[$key] ?? ''));
            if ($label === '') {
                $label = $nodeId !== null
                    ? trim((string) ($childNodes[$nodeId]['name'] ?? ''))
                    : $this->deriveMirrorLabel($parsed['host'] !== '' ? $parsed['host'] : (string) $nodeDomain);
            }
            $mirrors[] = [
                'key'          => $key,
                'host'         => $parsed['host'] !== '' ? $parsed['host'] : (string) $nodeDomain,
                'port'         => $parsed['port'],
                'label'        => $this->sanitizeMirrorProxyLabel($label),
                'node_id'      => $nodeId,
                'node_domain'  => $nodeDomain,
            ];
        }

        return $mirrors;
    }

    /**
     * Mirrors that use iptables DNAT on a forwarder VPS (not child-node bindings).
     *
     * @return list<array{key: string, host: string, port: ?int, label: string, node_id: ?string, node_domain: ?string}>
     */
    protected function getEnabledDnatMirrors(?array $pac = null): array
    {
        return array_values(array_filter(
            $this->getEnabledMirrors($pac),
            static fn(array $mirror): bool => empty($mirror['node_id']),
        ));
    }

    protected function parseMirrorEndpoint(string $entry): array
    {
        $entry = trim($entry);
        if ($entry === '') {
            return ['host' => '', 'port' => null];
        }
        if (preg_match('~^\[([^\]]+)\]:(\d+)$~', $entry, $m)) {
            return ['host' => $m[1], 'port' => (int) $m[2]];
        }
        if (preg_match('~^([^:]+):(\d+)$~', $entry, $m) && substr_count($entry, ':') === 1) {
            return ['host' => $m[1], 'port' => (int) $m[2]];
        }

        return ['host' => $entry, 'port' => null];
    }

    protected function deriveMirrorLabel(string $host): string
    {
        $host = trim($host);
        if ($host === '') {
            return 'm';
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $parts = explode('.', $host);
            if (count($parts) >= 2) {
                return 'm' . $parts[count($parts) - 2] . $parts[count($parts) - 1];
            }

            return 'm' . preg_replace('~[^a-zA-Z0-9]~', '', $host);
        }
        $parts = explode('.', $host);
        $label = (string) ($parts[0] ?? 'm');

        return $this->sanitizeMirrorProxyLabel($label);
    }

    protected function sanitizeMirrorProxyLabel(string $label): string
    {
        $label = preg_replace('~[^a-zA-Z0-9_-]~', '', $label) ?? '';
        if ($label === '') {
            return 'm';
        }
        if (function_exists('mb_substr')) {
            return mb_substr($label, 0, 16, 'UTF-8');
        }

        return substr($label, 0, 16);
    }

    protected function appendClashMirrorProxies(array &$c, ?array $pac = null, ?array $baseProxies = null): void
    {
        $pac = $pac ?? $this->getPacConf();
        $mirrors = $this->getEnabledMirrors($pac);
        if ($mirrors === [] || empty($c['proxies']) || !is_array($c['proxies'])) {
            return;
        }

        $originalProxies = $baseProxies ?? array_values($c['proxies']);
        $existingNames = [];
        foreach ($c['proxies'] as $proxy) {
            if (is_array($proxy) && !empty($proxy['name'])) {
                $existingNames[(string) $proxy['name']] = true;
            }
        }

        foreach ($mirrors as $mirror) {
            foreach ($originalProxies as $proxy) {
                if (!is_array($proxy)) {
                    continue;
                }
                $type = strtolower((string) ($proxy['type'] ?? ''));
                $origName = trim((string) ($proxy['name'] ?? ''));
                if ($origName === '') {
                    continue;
                }
                $mirrorName = $origName . '-' . $mirror['label'];
                if (isset($existingNames[$mirrorName])) {
                    continue;
                }

                $clone = $proxy;
                $clone['name'] = $mirrorName;
                if (!empty($mirror['node_id']) && !empty($mirror['node_domain'])) {
                    $clone = $this->rewriteClashProxyForChildNode($proxy, (string) $mirror['node_domain']);
                    $clone['name'] = $mirrorName;
                    if ($type === 'wireguard') {
                        $wgPort = (int) (getenv('WG1PORT') ?: 51821);
                        $clone['port'] = $wgPort > 0 ? $wgPort : 51821;
                    }
                } else {
                    $clone['server'] = $mirror['host'];
                    if ($type === 'wireguard') {
                        $wgPort = (int) (getenv('WG1PORT') ?: 51821);
                        $clone['port'] = $wgPort > 0 ? $wgPort : 51821;
                    } elseif ($mirror['port'] !== null && $mirror['port'] > 0) {
                        $clone['port'] = $mirror['port'];
                    }
                }

                $c['proxies'][] = $clone;
                $existingNames[$mirrorName] = true;
                $this->linkClashTransportProxyToGroups($c, $origName, $mirrorName);
            }
        }
    }

    protected function buildMirrorIptablesScript(): string
    {
        foreach ([__DIR__ . '/../mirror_iptables.sh', dirname(__DIR__, 2) . '/mirror/mirror_iptables.sh'] as $path) {
            if (is_readable($path)) {
                $script = (string) file_get_contents($path);
                break;
            }
        }
        if (empty($script)) {
            return "#!/bin/bash\nTARGET_IP=\"{$this->ip}\"\n";
        }

        $tgPort = (int) (getenv('TGPORT') ?: 4443);
        $wg1Port = (int) (getenv('WG1PORT') ?: 51821);
        $replacements = [
            '~ip~'  => (string) $this->ip,
            '~tg~'  => (string) ($tgPort > 0 ? $tgPort : 4443),
            '~wg1~' => (string) ($wg1Port > 0 ? $wg1Port : 51821),
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $script);
    }

    public function mirrors($page = 0)
    {
        $p = $this->getPacConf();
        $enabled = count($this->getEnabledMirrors($p));
        $dnat = count($this->getEnabledDnatMirrors($p));
        $nodeMirrors = $enabled - $dnat;
        $total = count(array_filter($p['mirrorlist'] ?? [], static fn($v) => $v !== null));
        $text[] = 'Menu -> ' . $this->i18n('xray') . ' -> ' . $this->i18n('mirrors');
        $text[] = $this->i18n('mirrors_help');
        $text[] = $this->i18n('enabled') . ": $enabled / $total (DNAT: $dnat, " . $this->i18n('mirror_mode_node') . ": $nodeMirrors)";
        $text[] = $this->i18n('main outbound name: ') . $this->getMainClashOutboundName($p);
        [$data, $listText] = $this->listPac('mirrorlist', $page, 'mirrors');
        if (!empty($listText)) {
            $text = array_merge($text, $listText);
        }
        $data[] = [
            [
                'text'          => $this->i18n('mirror_iptables_script'),
                'callback_data' => '/getMirror',
            ],
            [
                'text'          => $this->i18n('clash_proxy_names'),
                'callback_data' => '/clashProxyNames',
            ],
        ];
        $data[] = [
            [
                'text'          => $this->i18n('back'),
                'callback_data' => '/xrayCore',
            ],
        ];
        $this->replyMenu(
            $this->input['chat'],
            (int) ($this->input['message_id'] ?? 0),
            implode("\n", $text ?: ['...']),
            $data ?: false,
        );
    }

    public function clashProxyNames()
    {
        $p = $this->getPacConf();
        $suffixes = array_merge($this->getDefaultClashProxySuffixes(), is_array($p['clash_proxy_suffixes'] ?? null) ? $p['clash_proxy_suffixes'] : []);
        $text[] = 'Menu -> ' . $this->i18n('clash_proxy_names');
        $text[] = $this->i18n('clash_proxy_names_help');
        $text[] = $this->i18n('main outbound name: ') . '<code>' . $this->getMainClashOutboundName($p) . '</code>';
        $text[] = $this->i18n('proxy_group_type') . ': <code>' . $this->getProxyGroupType($p) . '</code>';
        $text[] = 'WS: <code>' . htmlspecialchars((string) $suffixes['ws'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>';
        $text[] = 'XHTTP: <code>' . htmlspecialchars((string) $suffixes['xhttp'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>';
        $text[] = 'HY2: <code>' . htmlspecialchars((string) $suffixes['hy2'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>';

        $data[] = [
            [
                'text'          => $this->i18n('main outbound name: ') . $this->getMainClashOutboundName($p),
                'callback_data' => '/mainOutbound',
            ],
        ];
        $data[] = [
            [
                'text'          => $this->i18n('proxy_group_type') . ': ' . $this->getProxyGroupType($p),
                'callback_data' => '/proxyGroupType',
            ],
        ];
        $data[] = [
            [
                'text'          => $this->i18n('edit_transport_suffixes'),
                'callback_data' => '/clashProxySuffixes',
            ],
        ];
        $data[] = [
            [
                'text'          => $this->i18n('back'),
                'callback_data' => '/mirrors',
            ],
        ];
        $this->update(
            $this->input['chat'],
            $this->input['message_id'],
            implode("\n", $text ?: ['...']),
            $data ?: false,
        );
    }

    public function clashProxySuffixes()
    {
        $p = $this->getPacConf();
        $suffixes = array_merge($this->getDefaultClashProxySuffixes(), is_array($p['clash_proxy_suffixes'] ?? null) ? $p['clash_proxy_suffixes'] : []);
        $example = 'ws=' . $suffixes['ws'] . ' xhttp=' . $suffixes['xhttp'] . ' hy2=' . $suffixes['hy2'];
        $r = $this->send(
            $this->input['chat'],
            "@{$this->input['username']} " . $this->i18n('clash_proxy_suffixes_prompt') . "\n<code>$example</code>",
            $this->input['message_id'],
            reply: 'ws=-ws xhttp=-xhttp hy2=-hy2',
        );
        $_SESSION['reply'][$r['result']['message_id']] = [
            'start_message' => $this->input['message_id'],
            'callback'      => 'setClashProxySuffixes',
            'args'          => [],
        ];
    }

    public function setClashProxySuffixes(string $text): void
    {
        $text = trim($text);
        $pac = $this->getPacConf();
        if ($text === '' || $text === '0') {
            unset($pac['clash_proxy_suffixes']);
            $this->setPacConf($pac);
            $this->send($this->input['chat'], 'saved', $this->input['message_id']);
            $this->clashProxyNames();
            return;
        }

        $suffixes = [];
        foreach (preg_split('~\s+~', $text) as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '' || !str_contains($chunk, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $chunk, 2));
            if (!in_array($key, ['ws', 'xhttp', 'hy2'], true)) {
                continue;
            }
            $suffixes[$key] = $value;
        }
        if ($suffixes === []) {
            $this->send($this->input['chat'], 'wrong pattern, use ws=-ws xhttp=-xhttp hy2=-hy2', $this->input['message_id']);
            return;
        }
        $pac['clash_proxy_suffixes'] = array_merge($this->getDefaultClashProxySuffixes(), $suffixes);
        $this->setPacConf($pac);
        $this->send($this->input['chat'], 'saved', $this->input['message_id']);
        $this->clashProxyNames();
    }

    public function getMirror()
    {
        $script = $this->buildMirrorIptablesScript();
        $this->upload('mirror_iptables.sh', $script);
        if (!empty($this->input['callback_id'])) {
            $this->answer($this->input['callback_id'], $this->i18n('mirror_iptables_script'), true);
        }
    }

    public function mirrorNodeMenu(int $mirrorIndex, int $page = 0)
    {
        $mirrorKey = $this->getMirrorlistKeyByIndex($mirrorIndex);
        if ($mirrorKey === null) {
            $this->mirrors($page);
            return;
        }
        $pac = $this->getPacConf();
        $bound = $this->getMirrorNodesMap($pac)[$mirrorKey] ?? '';
        $text[] = 'Menu -> ' . $this->i18n('mirrors') . ' -> ' . $this->i18n('mirror_pick_node');
        $text[] = '<code>' . htmlspecialchars($mirrorKey, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>';
        $text[] = $this->i18n('mirror_current_mode') . ': ' . $this->getMirrorNodeLabel($mirrorKey, $pac);

        $data[] = [
            [
                'text'          => ($bound === '' ? '✅ ' : '') . $this->i18n('mirror_mode_dnat'),
                'callback_data' => "/mirrorSetNode $mirrorIndex 0 $page",
            ],
        ];
        foreach ($this->getChildNodesRaw($pac) as $nodeId => $node) {
            $name = trim((string) ($node['name'] ?? $nodeId));
            $domain = trim((string) ($node['domain'] ?? ''));
            $mark = ($bound === (string) $nodeId) ? '✅ ' : '';
            $label = $mark . $name;
            if ($domain !== '') {
                $label .= ' (' . $domain . ')';
            }
            $data[] = [
                [
                    'text'          => $label,
                    'callback_data' => "/mirrorSetNode $mirrorIndex $nodeId $page",
                ],
            ];
        }
        $data[] = [
            [
                'text'          => $this->i18n('back'),
                'callback_data' => "/mirrors $page",
            ],
        ];
        $this->replyMenu(
            $this->input['chat'],
            (int) ($this->input['message_id'] ?? 0),
            implode("\n", $text),
            $data,
        );
    }

    public function mirrorSetNode(int $mirrorIndex, string $nodeId, int $page = 0)
    {
        $mirrorKey = $this->getMirrorlistKeyByIndex($mirrorIndex);
        if ($mirrorKey === null) {
            $this->mirrors($page);
            return;
        }
        $pac = $this->getPacConf();
        if (!isset($pac['mirror_nodes']) || !is_array($pac['mirror_nodes'])) {
            $pac['mirror_nodes'] = [];
        }
        $nodeId = trim($nodeId);
        if ($nodeId === '' || $nodeId === '0') {
            unset($pac['mirror_nodes'][$mirrorKey]);
        } else {
            $nodes = $pac['child_nodes'] ?? [];
            if (!is_array($nodes) || empty($nodes[$nodeId])) {
                $this->mirrors($page);
                return;
            }
            $pac['mirror_nodes'][$mirrorKey] = $nodeId;
        }
        $this->setPacConf($pac);
        $this->mirrors($page);
    }
}
