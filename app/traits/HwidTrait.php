<?php

trait HwidTrait
{
    protected function shouldExcludeParentFromXrayInbounds(array $client): bool
    {
        return empty($client['device_parent_id']) && $this->isPermanentHwidRuntime($client);
    }

    protected function isPermanentHwidRuntime(array $client): bool
    {
        if (!$this->isHwidRuntimeModeEnabled($client)) {
            return false;
        }
        $pac = $this->getPacConf();
        if (empty($pac['hwid_limit_enabled']) || !empty($client['hwid_disabled'])) {
            return false;
        }
        $limit = (int) ($client['hwid_limit'] ?? $pac['hwid_device_count'] ?? 0);

        return $limit > 0;
    }
    protected function retireParentRuntimeUuid(array &$xray, int $ownerIndex): bool
    {
        if (!isset($xray['inbounds'][0]['settings']['clients'][$ownerIndex])) {
            return false;
        }
        $owner = &$xray['inbounds'][0]['settings']['clients'][$ownerIndex];
        if (!empty($owner['device_parent_id']) || !empty($owner['runtime_parent_retired'])) {
            return false;
        }
        if (!$this->isPermanentHwidRuntime($owner)) {
            return false;
        }
        $newId = $this->createXrayUuid();
        while ($this->findXrayClientIndexById($xray, $newId) !== null) {
            $newId = $this->createXrayUuid();
        }
        $owner['id'] = $newId;
        $owner['runtime_parent_retired'] = 1;

        return true;
    }
    public function hwidLimit()
    {
        $pac     = $this->getPacConf();
        $enabled = !empty($pac['hwid_limit_enabled']);
        $count   = max(1, (int) ($pac['hwid_device_count'] ?: 1));

        $text[] = 'Settings -> ' . $this->i18n('hwid limit');
        $text[] = $this->i18n('hwid notice');
        $text[] = $this->i18n('hwid limit') . ': ' . ($enabled ? $count : $this->i18n('off'));

        $data[] = [
            [
                'text'          => $this->i18n($enabled ? 'on' : 'off'),
                'callback_data' => '/toggleHwidLimit',
            ],
        ];
        $data[] = [
            [
                'text'          => $this->i18n('set hwid devices count') . ': ' . $count,
                'callback_data' => '/setHwidDevices',
            ],
        ];
        $data[] = [
            [
                'text'          => $this->i18n('back'),
                'callback_data' => '/xray',
            ],
        ];

        $this->update(
            $this->input['chat'],
            $this->input['message_id'],
            implode("\n", $text ?: ['...']),
            $data ?: false,
        );
    }

    public function toggleHwidLimit($context = null)
    {
        $pac = $this->getPacConf();
        $pac['hwid_limit_enabled'] = $pac['hwid_limit_enabled'] ? 0 : 1;
        if (!empty($pac['hwid_limit_enabled']) && empty($pac['hwid_device_count'])) {
            $pac['hwid_device_count'] = 1;
        }
        $this->setPacConf($pac);
        $this->answer($this->input['callback_id'], $this->i18n('hwid notice'), true);
        if ($context === 'xray') {
            $this->xray();
        } else {
            $this->hwidLimit();
        }
    }

    public function toggleHwidRuntimeMode($context = null)
    {
        $pac = $this->getPacConf();
        $pac['hwid_runtime_mode_enabled'] = !empty($pac['hwid_runtime_mode_enabled']) ? 0 : 1;
        $this->setPacConf($pac);
        $this->answer($this->input['callback_id'], 'HWID runtime mode: ' . (!empty($pac['hwid_runtime_mode_enabled']) ? 'on' : 'off'), true);
        if ($context === 'xray') {
            $this->xray();
        } else {
            $this->hwidLimit();
        }
    }

    public function setHwidDevices($context = null)
    {
        $r = $this->send(
            $this->input['chat'],
            "@{$this->input['username']} enter hwid devices count",
            $this->input['message_id'],
            reply: 'enter hwid devices count',
        );
        $_SESSION['reply'][$r['result']['message_id']] = [
            'start_message' => $this->input['message_id'],
            'callback'      => 'saveHwidDevices',
            'args'          => [$context],
        ];
    }

    public function toggleRuntimeWgProfile()
    {
        $pac = $this->getPacConf();
        $pac['hwid_runtime_wg_profile_enabled'] = !empty($pac['hwid_runtime_wg_profile_enabled']) ? 0 : 1;
        $this->setPacConf($pac);
        $this->answer($this->input['callback_id'], 'runtime WG profile: ' . (!empty($pac['hwid_runtime_wg_profile_enabled']) ? 'on' : 'off'), true);
        $this->xray();
    }

    public function setRuntimeWgEndpoint()
    {
        $pac = $this->getPacConf();
        $current = trim((string) ($pac['hwid_runtime_wg_endpoint'] ?? ''));
        $r = $this->send(
            $this->input['chat'],
            "@{$this->input['username']} enter runtime WG endpoint host:port (0 to reset)\ncurrent: " . ($current ?: '(default by domain/ip)'),
            $this->input['message_id'],
            reply: 'enter runtime wg endpoint',
        );
        $_SESSION['reply'][$r['result']['message_id']] = [
            'start_message' => $this->input['message_id'],
            'callback'      => 'saveRuntimeWgEndpoint',
            'args'          => [],
        ];
    }

    public function saveRuntimeWgEndpoint($endpoint)
    {
        $pac = $this->getPacConf();
        $endpoint = trim((string) $endpoint);
        if ($endpoint === '0') {
            $endpoint = '';
        }
        $endpoint = preg_replace('~^\w+://~', '', $endpoint);
        $endpoint = preg_replace('~/.*$~', '', $endpoint);
        if ($endpoint !== '' && !preg_match('~:\d+$~', $endpoint)) {
            $endpoint .= ':' . getenv($this->getInstanceWG(1) ? 'WG1PORT' : 'WGPORT');
        }
        $pac['hwid_runtime_wg_endpoint'] = $endpoint;
        $this->setPacConf($pac);
        $clients = $this->readClients();
        $changed = false;
        foreach ($clients as $k => $client) {
            if (empty($client['interface']['## device_uuid'])) {
                continue;
            }
            if (($clients[$k]['interface']['## endpoint_custom'] ?? '') !== $endpoint) {
                $clients[$k]['interface']['## endpoint_custom'] = $endpoint;
                $changed = true;
            }
        }
        if ($changed) {
            $this->saveClients($clients);
        }
        $this->send($this->input['chat'], 'runtime wg endpoint saved', $this->input['message_id']);
        $this->xray();
    }

    public function saveHwidDevices($count, $context = null)
    {
        $count = (int) $count;
        if ($count <= 0) {
            $count = 1;
        }
        $pac = $this->getPacConf();
        $pac['hwid_device_count'] = $count;
        $this->setPacConf($pac);
        $this->send($this->input['chat'], $this->i18n('hwid notice'), $this->input['message_id']);
        if ($context === 'xray') {
            $this->xray();
        } else {
            $this->hwidLimit();
        }
    }

    public function getHwidStorage()
    {
        if (!file_exists($this->hwid)) {
            return [];
        }
        $data = json_decode(file_get_contents($this->hwid), true);
        if (!is_array($data)) {
            return [];
        }

        return $this->normalizeHwidStorage($data);
    }

    public function setHwidStorage(array $storage)
    {
        $normalized = $this->normalizeHwidStorage($storage);
        file_put_contents($this->hwid, json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    protected function normalizeHwidStorage(array $storage)
    {
        $normalized = [];

        foreach ($storage as $uid => $devices) {
            if (!is_array($devices)) {
                continue;
            }

            foreach ($devices as $hwid => $info) {
                $hwidKey = trim((string) $hwid);
                if ($hwidKey === '') {
                    continue;
                }

                $normalized[$uid][$hwidKey] = is_array($info) ? $info : [];
            }
        }

        return $normalized;
    }

    public function getHwidDevicesByUser($uid)
    {
        $storage = $this->getHwidStorage();
        return $storage[$uid] ?? [];
    }

    protected function sanitizeHwidDeviceName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        if (function_exists('mb_substr')) {
            return mb_substr($name, 0, 64, 'UTF-8');
        }

        return substr($name, 0, 64);
    }

    protected function getHwidDeviceDisplayName(array $info): string
    {
        $custom = trim((string) ($info['device_name'] ?? ''));
        if ($custom !== '') {
            return $custom;
        }
        $parts = array_filter([
            (string) ($info['device_model'] ?? ''),
            (string) ($info['device_os'] ?? ''),
            (string) ($info['os_version'] ?? ''),
        ], static fn($v) => $v !== '');

        return implode(' ', $parts);
    }

    public function renameHwidDevice(string $ownerSubId, string $hwid, string $name): array
    {
        $ownerSubId = trim($ownerSubId);
        $hwid = trim($hwid);
        $name = $this->sanitizeHwidDeviceName($name);
        if ($ownerSubId === '' || $hwid === '') {
            return ['ok' => false, 'message' => 'device not found'];
        }
        if ($name === '') {
            return ['ok' => false, 'message' => 'empty name'];
        }

        $storage = $this->getHwidStorage();
        if (!isset($storage[$ownerSubId][$hwid]) || !is_array($storage[$ownerSubId][$hwid])) {
            return ['ok' => false, 'message' => 'device not found'];
        }

        $storage[$ownerSubId][$hwid]['device_name'] = $name;
        $this->setHwidStorage($storage);

        return ['ok' => true, 'device_name' => $name];
    }

    public function getHwidDeviceTraffic(string $ownerSubId): array
    {
        $ownerSubId = trim($ownerSubId);
        if ($ownerSubId === '') {
            return [];
        }

        $xray = $this->getXray();
        $stats = $this->getXrayStats();
        $trafficByHwid = [];

        foreach ($xray['inbounds'][0]['settings']['clients'] as $index => $client) {
            if (($client['device_parent_id'] ?? '') !== $ownerSubId) {
                continue;
            }

            $hwid = (string) ($client['device_hwid'] ?? '');
            if ($hwid === '') {
                continue;
            }

            $traffic = $this->getClientTrafficStats($stats, $client, $index);
            $download = (int) $traffic['download'];
            $upload = (int) $traffic['upload'];

            $trafficByHwid[$hwid] = [
                'download' => $download,
                'upload' => $upload,
                'total' => $download + $upload,
                'device_uuid' => (string) ($client['id'] ?? ''),
            ];
        }

        return $trafficByHwid;
    }

    protected function isRuntimeParentRetired(array $client): bool
    {
        return !empty($client['runtime_parent_retired']);
    }

    protected function getRuntimeParentRetiredEmoji(array $client): string
    {
        return $this->isRuntimeParentRetired($client) ? ' ✅' : '';
    }

    /**
     * Xray-трафик для отображения: сумма устройств; при активном runtime без retirement — плюс родительский UUID.
     *
     * @return array{download:int, upload:int, include_parent:bool, legacy_parent_only:bool}
     */
    protected function getSubscriptionXrayTrafficTotals(array $stats, array $owner, ?int $ownerIndex): array
    {
        $ownerSubId = $this->getClientSubscriptionId($owner);
        $deviceMap = $this->getHwidDeviceTraffic($ownerSubId);
        $download = 0;
        $upload = 0;
        foreach ($deviceMap as $row) {
            $download += (int) ($row['download'] ?? 0);
            $upload += (int) ($row['upload'] ?? 0);
        }
        if ($deviceMap === [] && $ownerIndex !== null && !$this->isPermanentHwidRuntime($owner)) {
            $parent = $this->getClientTrafficStats($stats, $owner, $ownerIndex);

            return [
                'download'            => (int) $parent['download'],
                'upload'              => (int) $parent['upload'],
                'include_parent'      => false,
                'legacy_parent_only'  => true,
            ];
        }

        return [
            'download'           => $download,
            'upload'             => $upload,
            'include_parent'     => false,
            'legacy_parent_only' => false,
        ];
    }

    protected function formatTrafficUpDown(int $download, int $upload): string
    {
        return '↑' . $this->getBytes($upload) . '  ↓' . $this->getBytes($download);
    }

    protected function formatTrafficDisplayLine(int $download, int $upload, ?array $awg = null): string
    {
        $line = 'Traffic: ' . $this->formatTrafficUpDown($download, $upload);
        if ($awg !== null) {
            $line .= '    AWG Traffic: ' . $this->formatTrafficUpDown((int) ($awg['download'] ?? 0), (int) ($awg['upload'] ?? 0));
        }

        return $line;
    }

    protected function parseWgSizeToBytes(float $value, string $unit): int
    {
        $unit = strtoupper(trim($unit));
        $powers = [
            'B' => 0, 'KB' => 1, 'MB' => 2, 'GB' => 3, 'TB' => 4,
            'KIB' => 1, 'MIB' => 2, 'GIB' => 3, 'TIB' => 4,
        ];
        if (!isset($powers[$unit])) {
            return (int) round($value);
        }
        $decimal = in_array($unit, ['KB', 'MB', 'GB', 'TB'], true);

        return (int) round($value * pow($decimal ? 1000 : 1024, $powers[$unit]));
    }

    protected function parseWgPeerTransfer(string $transfer): array
    {
        $download = 0;
        $upload = 0;
        if (preg_match('~([\d.]+)\s*([KMGTP]?i?B)\s+received~i', $transfer, $m)) {
            $upload = $this->parseWgSizeToBytes((float) $m[1], $m[2]);
        }
        if (preg_match('~([\d.]+)\s*([KMGTP]?i?B)\s+sent~i', $transfer, $m)) {
            $download = $this->parseWgSizeToBytes((float) $m[1], $m[2]);
        }

        return ['download' => $download, 'upload' => $upload];
    }

    /** @var array|null */
    protected $runtimeWgStatusSnapshot = null;

    protected function getRuntimeWgStatusSnapshot(): array
    {
        if ($this->runtimeWgStatusSnapshot !== null) {
            return $this->runtimeWgStatusSnapshot;
        }
        $snapshot = $this->runInRuntimeWgContext(function () {
            $status = $this->readStatus();

            return is_array($status) ? $status : [];
        });
        $this->runtimeWgStatusSnapshot = is_array($snapshot) ? $snapshot : [];

        return $this->runtimeWgStatusSnapshot;
    }

    protected function findRuntimeWgPeerPublicKey(string $deviceUuid): string
    {
        if ($deviceUuid === '') {
            return '';
        }
        if ($this->wgServerConfigSnapshot === null) {
            $this->wgServerConfigSnapshot = $this->runInRuntimeWgContext(function () {
                return $this->readConfig();
            }) ?? [];
        }
        foreach (($this->wgServerConfigSnapshot['peers'] ?? []) as $peer) {
            if (!is_array($peer)) {
                continue;
            }
            if ((string) ($peer['## device_uuid'] ?? '') === $deviceUuid) {
                return (string) ($peer['PublicKey'] ?? '');
            }
        }

        return '';
    }

    /**
     * @return array{download:int, upload:int, online:bool, enabled:bool}
     */
    protected function getRuntimeDeviceAwgStats(string $deviceUuid, bool $wgEnabled): array
    {
        $empty = ['download' => 0, 'upload' => 0, 'online' => false, 'enabled' => $wgEnabled];
        if (!$wgEnabled || $deviceUuid === '') {
            return $empty;
        }
        $pub = $this->findRuntimeWgPeerPublicKey($deviceUuid);
        if ($pub === '') {
            return $empty;
        }
        $status = $this->getRuntimeWgStatusSnapshot();
        $peerStatus = $this->getStatusPeer($pub, $status['peers'] ?? []);
        if (!is_array($peerStatus)) {
            return $empty;
        }
        $transfer = $this->parseWgPeerTransfer((string) ($peerStatus['transfer'] ?? ''));
        $handshake = (string) ($peerStatus['latest handshake'] ?? '');
        $online = $handshake !== '' && preg_match('~^(\d+ seconds?|[12] minute)~', $handshake);

        return [
            'download' => (int) $transfer['download'],
            'upload'   => (int) $transfer['upload'],
            'online'   => (bool) $online,
            'enabled'  => true,
        ];
    }

    /**
     * @return array{download:int, upload:int, any_online:bool}
     */
    protected function getSubscriptionAwgTrafficTotals(array $owner, array $deviceTrafficMap): array
    {
        $download = 0;
        $upload = 0;
        $anyOnline = false;
        if (!$this->isRuntimeDeviceWgEnabled($owner)) {
            return ['download' => 0, 'upload' => 0, 'any_online' => false];
        }
        foreach ($deviceTrafficMap as $row) {
            $deviceUuid = (string) ($row['device_uuid'] ?? '');
            if ($deviceUuid === '') {
                continue;
            }
            $awg = $this->getRuntimeDeviceAwgStats($deviceUuid, true);
            $download += (int) $awg['download'];
            $upload += (int) $awg['upload'];
            if (!empty($awg['online'])) {
                $anyOnline = true;
            }
        }

        return ['download' => $download, 'upload' => $upload, 'any_online' => $anyOnline];
    }

    public function setHwidDevice($uid, $hwid, array $info)
    {
        $storage = $this->getHwidStorage();
        $storage[$uid][$hwid] = $info;
        $this->setHwidStorage($storage);
    }

    public function deleteHwidDevice($uid, $hwid)
    {
        $storage = $this->getHwidStorage();
        if (isset($storage[$uid][$hwid])) {
            unset($storage[$uid][$hwid]);
            if (empty($storage[$uid])) {
                unset($storage[$uid]);
            }
            $this->setHwidStorage($storage);
        }
    }

    public function deleteHwidUser($uid)
    {
        $storage = $this->getHwidStorage();
        if (isset($storage[$uid])) {
            unset($storage[$uid]);
            $this->setHwidStorage($storage);
        }
    }

    protected function getHwidTokenScope($index)
    {
        return ($this->input['chat'] ?? 'global') . ':' . $index;
    }

    protected function rememberHwidToken($scope, $hwid)
    {
        if (!isset($_SESSION['hwidTokens'])) {
            $_SESSION['hwidTokens'] = [];
        }
        if (!isset($_SESSION['hwidTokens'][$scope])) {
            $_SESSION['hwidTokens'][$scope] = [];
        }
        do {
            try {
                $token = bin2hex(random_bytes(5));
            } catch (\Throwable $e) {
                $token = substr(hash('sha256', $hwid . microtime(true)), 0, 10);
            }
        } while (isset($_SESSION['hwidTokens'][$scope][$token]));

        $_SESSION['hwidTokens'][$scope][$token] = $hwid;

        return $token;
    }

    protected function resolveHwidToken($scope, $token)
    {
        if (isset($_SESSION['hwidTokens'][$scope][$token])) {
            $hwid = $_SESSION['hwidTokens'][$scope][$token];
            unset($_SESSION['hwidTokens'][$scope][$token]);
            return $hwid;
        }

        $decoded = base64_decode($token, true);

        return $decoded !== false ? $decoded : '';
    }
    protected function getClientSubscriptionId(array $client): string
    {
        return (string) ($client['subscription_id'] ?? $client['id'] ?? '');
    }

    protected function isSubscriptionIdMatch(array $client, string $requestedId): bool
    {
        if ($requestedId === '') {
            return false;
        }

        if ($this->getClientSubscriptionId($client) === $requestedId) {
            return true;
        }

        if (($client['id'] ?? '') === $requestedId) {
            return true;
        }

        $legacy = $client['subscription_legacy_ids'] ?? [];
        if (is_array($legacy) && in_array($requestedId, $legacy, true)) {
            return true;
        }

        return false;
    }
    protected function ensureOwnerSubscriptionAnchor(array &$xray, int $ownerIndex): bool
    {
        if (!isset($xray['inbounds'][0]['settings']['clients'][$ownerIndex])) {
            return false;
        }

        $owner = &$xray['inbounds'][0]['settings']['clients'][$ownerIndex];
        if (!empty($owner['subscription_id'])) {
            return false;
        }

        $owner['subscription_id'] = (string) $owner['id'];
        if (!isset($owner['subscription_legacy_ids']) || !is_array($owner['subscription_legacy_ids'])) {
            $owner['subscription_legacy_ids'] = [];
        }
        if (!in_array($owner['id'], $owner['subscription_legacy_ids'], true)) {
            $owner['subscription_legacy_ids'][] = (string) $owner['id'];
        }
        // Parent UUID is retired when the first runtime device is registered.
        if (!array_key_exists('runtime_parent_retired', $owner)) {
            $owner['runtime_parent_retired'] = 0;
        }
        return true;
    }

    protected function createRuntimeDeviceClient(array $owner, string $ownerSubId, string $deviceUuid, string $hwid): array
    {
        $pac = $this->getPacConf();
        $email = (string) ($owner['email'] ?? 'user');
        $ownerSuffix = substr(hash('sha1', $ownerSubId), 0, 6);
        $suffix = substr(hash('sha1', $hwid), 0, 8);
        $deviceEmail = $email . '#dev-' . $ownerSuffix . '-' . $suffix;

        $client = [
            'id' => $deviceUuid,
            'email' => $deviceEmail,
            'device_parent_id' => $ownerSubId,
            'device_hwid' => $hwid,
            'device_runtime' => 1,
        ];

        $global = $this->getTransportRegistryGlobal($pac);
        if (!empty($global['reality']) && empty($global['ws']) && empty($global['xhttp'])) {
            $client['flow'] = 'xtls-rprx-vision';
        }

        return $client;
    }

    protected function findDeviceWgClientIndex(array $clients, string $ownerSubId, string $hwid = '', string $deviceUuid = ''): ?int
    {
        foreach ($clients as $idx => $client) {
            if (!is_array($client)) {
                continue;
            }
            $iface = $client['interface'] ?? [];
            if (!is_array($iface)) {
                continue;
            }
            if (($iface['## owner_sub_id'] ?? '') !== $ownerSubId) {
                continue;
            }
            if ($deviceUuid !== '' && ($iface['## device_uuid'] ?? '') === $deviceUuid) {
                return $idx;
            }
            if ($hwid !== '' && ($iface['## device_hwid'] ?? '') === $hwid) {
                return $idx;
            }
        }
        return null;
    }

    protected function isRuntimeDeviceWgEnabled(array $client): bool
    {
        $pac = $this->getPacConf();
        $flags = $this->getClientTransportFlags($client, $pac);
        return !empty($flags['awg']) && !empty($pac['hwid_runtime_wg_profile_enabled']) && $this->isHwidRuntimeModeEnabled($client);
    }
    protected function getRuntimeDeviceWgEndpoint(): string
    {
        $pac = $this->getPacConf();
        $endpoint = trim((string) ($pac['hwid_runtime_wg_endpoint'] ?? ''));
        if ($endpoint === '') {
            return '';
        }
        $endpoint = preg_replace('~^\w+://~', '', $endpoint);
        $endpoint = preg_replace('~/.*$~', '', $endpoint);
        if (!preg_match('~:\d+$~', $endpoint)) {
            $endpoint .= ':' . getenv($this->getInstanceWG(1) ? 'WG1PORT' : 'WGPORT');
        }
        return $endpoint;
    }

    protected function getAwgClientMtu(): int
    {
        return 1280;
    }

    protected function wgShellQuote(string $value): string
    {
        return "'" . str_replace("'", "'\\''", $value) . "'";
    }

    protected function wgPublicKeyFromPrivate(string $privateKey): string
    {
        $privateKey = trim($privateKey);
        if ($privateKey === '') {
            return '';
        }
        $quoted = $this->wgShellQuote($privateKey);
        return trim($this->ssh("printf '%s' {$quoted} | {$this->getWGType()} pubkey", $this->getInstanceWG()));
    }

    protected function isAwgClientConfig(array $data): bool
    {
        $iface = $data['interface'] ?? [];
        if (!is_array($iface)) {
            return false;
        }
        if (!empty($iface['H1']) || !empty($iface['S1'])) {
            return true;
        }

        return !empty($this->getPacConf()[$this->getInstanceWG(1) . 'amnezia']) && empty($iface['ListenPort']);
    }

    protected function parseWgIpLong(string $addressOrCidr): ?int
    {
        $addressOrCidr = trim($addressOrCidr);
        if ($addressOrCidr === '') {
            return null;
        }
        $ip = ip2long(explode('/', $addressOrCidr)[0] ?? '');
        return $ip === false ? null : $ip;
    }

    protected function collectWgUsedIpLongs(array $server, array $clients, ?int $exceptClientIdx = null): array
    {
        $ips = [];
        $serverIp = $this->parseWgIpLong((string) ($server['interface']['Address'] ?? ''));
        if ($serverIp !== null) {
            $ips[] = $serverIp;
        }
        foreach (($server['peers'] ?? []) as $peer) {
            $peerIp = $this->parseWgIpLong((string) ($peer['AllowedIPs'] ?? $peer['# AllowedIPs'] ?? ''));
            if ($peerIp !== null) {
                $ips[] = $peerIp;
            }
        }
        foreach ($clients as $idx => $client) {
            if ($exceptClientIdx !== null && $idx === $exceptClientIdx) {
                continue;
            }
            if (!is_array($client)) {
                continue;
            }
            $clientIp = $this->parseWgIpLong((string) ($client['interface']['Address'] ?? ''));
            if ($clientIp !== null) {
                $ips[] = $clientIp;
            }
        }

        return array_values(array_unique($ips));
    }

    protected function allocateWgClientIp(array $server, array $clients, ?int $exceptClientIdx = null): ?string
    {
        $ipnet     = explode('/', (string) ($server['interface']['Address'] ?? ''));
        $server_ip = ip2long($ipnet[0] ?? '');
        $bitmask   = (int) ($ipnet[1] ?? 24);
        if ($server_ip === false || $bitmask <= 0 || $bitmask > 32) {
            return null;
        }

        $ips = $this->collectWgUsedIpLongs($server, $clients, $exceptClientIdx);
        $ip_count = (1 << (32 - $bitmask)) - count($ips) - 1;
        for ($i = 1; $i < $ip_count; $i++) {
            $ip = $i + $server_ip;
            if (!in_array($ip, $ips, true)) {
                return long2ip($ip);
            }
        }

        return null;
    }

    protected function isWgClientIpConflict(array $clients, string $clientIp, string $deviceUuid, ?int $exceptClientIdx = null): bool
    {
        $clientIpLong = $this->parseWgIpLong($clientIp);
        if ($clientIpLong === null) {
            return true;
        }
        foreach ($clients as $idx => $client) {
            if ($exceptClientIdx !== null && $idx === $exceptClientIdx) {
                continue;
            }
            if (!is_array($client)) {
                continue;
            }
            $iface = $client['interface'] ?? [];
            if (!is_array($iface)) {
                continue;
            }
            if ($this->parseWgIpLong((string) ($iface['Address'] ?? '')) !== $clientIpLong) {
                continue;
            }
            $otherUuid = (string) ($iface['## device_uuid'] ?? '');
            if ($otherUuid !== '' && $otherUuid !== $deviceUuid) {
                return true;
            }
        }

        return false;
    }

    protected function findDeviceWgPeerIndex(array $peers, string $deviceUuid, string $publicPeerKey = ''): ?int
    {
        foreach ($peers as $idx => $peer) {
            if (!is_array($peer)) {
                continue;
            }
            if ($deviceUuid !== '' && ($peer['## device_uuid'] ?? '') === $deviceUuid) {
                return $idx;
            }
            if ($publicPeerKey !== '' && ($peer['PublicKey'] ?? '') === $publicPeerKey) {
                return $idx;
            }
        }

        return null;
    }

    protected function syncDeviceWgPeerOnServer(array &$server, array $clientConf, string $ownerSubId, string $hwid, string $deviceUuid, string $clientIp): bool
    {
        if (!isset($server['peers']) || !is_array($server['peers'])) {
            $server['peers'] = [];
        }

        $privatePeerKey = trim((string) ($clientConf['interface']['PrivateKey'] ?? ''));
        if ($privatePeerKey === '') {
            return false;
        }
        $publicPeerKey = $this->wgPublicKeyFromPrivate($privatePeerKey);
        if ($publicPeerKey === '') {
            error_log("syncDeviceWgPeerOnServer: empty pubkey for device $deviceUuid");
            return false;
        }

        $peerIdx = $this->findDeviceWgPeerIndex($server['peers'], $deviceUuid, $publicPeerKey);
        $expectedAllowed = "$clientIp/32";
        $name = (string) ($clientConf['interface']['## name'] ?? ('dev-' . substr(hash('sha1', $ownerSubId), 0, 6) . '-' . substr(hash('sha1', $hwid), 0, 8)));
        if ($peerIdx === null) {
            $serverPeer = [
                '## name'         => $name,
                '## owner_sub_id' => $ownerSubId,
                '## device_uuid'  => $deviceUuid,
                'PublicKey'       => $publicPeerKey,
                'AllowedIPs'      => $expectedAllowed,
            ];
            if (!empty($this->getPacConf()[$this->getInstanceWG(1) . 'amnezia'])) {
                $psk = (string) ($clientConf['peers'][0]['PresharedKey'] ?? '');
                if ($psk === '') {
                    $psk = $this->presharedKey();
                }
                $serverPeer['PresharedKey'] = $psk;
            }
            $server['peers'][] = $serverPeer;

            return true;
        }

        $changed = false;
        foreach ([
            'AllowedIPs'      => $expectedAllowed,
            '## name'         => $name,
            '## owner_sub_id' => $ownerSubId,
            '## device_uuid'  => $deviceUuid,
        ] as $field => $value) {
            if (($server['peers'][$peerIdx][$field] ?? '') !== $value) {
                $server['peers'][$peerIdx][$field] = $value;
                $changed = true;
            }
        }
        if (!empty($this->getPacConf()[$this->getInstanceWG(1) . 'amnezia'])) {
            $psk = (string) ($clientConf['peers'][0]['PresharedKey'] ?? '');
            if ($psk !== '' && ($server['peers'][$peerIdx]['PresharedKey'] ?? '') !== $psk) {
                $server['peers'][$peerIdx]['PresharedKey'] = $psk;
                $changed = true;
            }
        }

        return $changed;
    }

    protected function ensureDeviceWgProfile(string $ownerSubId, string $hwid, string $deviceUuid, string $allowedIps = '0.0.0.0/0'): ?array
    {
        if ($ownerSubId === '' || $hwid === '' || $deviceUuid === '') {
            return null;
        }

        $clients = $this->readClients();
        $server  = $this->readConfig();
        if (!is_array($server)) {
            return null;
        }
        if (empty($server['interface']['PrivateKey'])) {
            return null;
        }
        if (!isset($server['peers']) || !is_array($server['peers'])) {
            $server['peers'] = [];
        }

        $changedClients = false;
        $changedServer = false;
        $endpointCustom = $this->getRuntimeDeviceWgEndpoint();

        $idx = $this->findDeviceWgClientIndex($clients, $ownerSubId, $hwid, $deviceUuid);
        if ($idx !== null && isset($clients[$idx])) {
            if (($clients[$idx]['interface']['## device_uuid'] ?? '') !== $deviceUuid) {
                $clients[$idx]['interface']['## device_uuid'] = $deviceUuid;
                $changedClients = true;
            }
            if (($clients[$idx]['interface']['## device_hwid'] ?? '') !== $hwid) {
                $clients[$idx]['interface']['## device_hwid'] = $hwid;
                $changedClients = true;
            }
            if (($clients[$idx]['interface']['## owner_sub_id'] ?? '') !== $ownerSubId) {
                $clients[$idx]['interface']['## owner_sub_id'] = $ownerSubId;
                $changedClients = true;
            }
            if (($clients[$idx]['interface']['## endpoint_custom'] ?? '') !== $endpointCustom) {
                $clients[$idx]['interface']['## endpoint_custom'] = $endpointCustom;
                $changedClients = true;
            }
            if (($clients[$idx]['peers'][0]['AllowedIPs'] ?? '') !== $allowedIps) {
                $clients[$idx]['peers'][0]['AllowedIPs'] = $allowedIps;
                $changedClients = true;
            }
            if (!empty($this->getPacConf()[$this->getInstanceWG(1) . 'amnezia'])) {
                $awgMtu = (string) $this->getAwgClientMtu();
                if ((string) ($clients[$idx]['interface']['MTU'] ?? '') !== $awgMtu) {
                    $clients[$idx]['interface']['MTU'] = $awgMtu;
                    $changedClients = true;
                }
            }
            $clientIp = explode('/', (string) ($clients[$idx]['interface']['Address'] ?? ''))[0];
            if ($clientIp === '' || $this->isWgClientIpConflict($clients, $clientIp, $deviceUuid, $idx)) {
                $newIp = $this->allocateWgClientIp($server, $clients, $idx);
                if ($newIp === null) {
                    return null;
                }
                $clients[$idx]['interface']['Address'] = "$newIp/32";
                $clientIp = $newIp;
                $changedClients = true;
            }
            $clientConf = $clients[$idx];
            if ($this->syncDeviceWgPeerOnServer($server, $clientConf, $ownerSubId, $hwid, $deviceUuid, $clientIp)) {
                $changedServer = true;
            }
        } else {
            $client_ip = $this->allocateWgClientIp($server, $clients);
            if ($client_ip === null) {
                return null;
            }

            $publicServerKey = trim($this->ssh("printf '%s' {$this->wgShellQuote($server['interface']['PrivateKey'])} | {$this->getWGType()} pubkey", $this->getInstanceWG()));
            $privatePeerKey  = trim($this->ssh("{$this->getWGType()} genkey", $this->getInstanceWG()));
            $publicPeerKey   = $this->wgPublicKeyFromPrivate($privatePeerKey);
            if ($privatePeerKey === '' || $publicPeerKey === '' || $publicServerKey === '') {
                return null;
            }

            $name = 'dev-' . substr(hash('sha1', $ownerSubId), 0, 6) . '-' . substr(hash('sha1', $hwid), 0, 8);
            $serverPeer = [
                '## name'    => $name,
                '## owner_sub_id' => $ownerSubId,
                '## device_uuid'  => $deviceUuid,
                'PublicKey'  => $publicPeerKey,
                'AllowedIPs' => "$client_ip/32",
            ];
            $clientPeer = [
                'PublicKey'           => $publicServerKey,
                'AllowedIPs'          => $allowedIps,
                'PersistentKeepalive' => 20,
            ];
            if (!empty($this->getPacConf()[$this->getInstanceWG(1) . 'amnezia'])) {
                $psk = $this->presharedKey();
                $serverPeer['PresharedKey'] = $psk;
                $clientPeer['PresharedKey'] = $psk;
            }
            $server['peers'][] = $serverPeer;
            $interface = [
                '## name'         => $name,
                '## owner_sub_id' => $ownerSubId,
                '## device_uuid'  => $deviceUuid,
                '## device_hwid'  => $hwid,
                '## endpoint_custom' => $endpointCustom,
                'PrivateKey'      => $privatePeerKey,
                'Address'         => "$client_ip/32",
            ];
            if (!empty($this->getPacConf()[$this->getInstanceWG(1) . 'amnezia'])) {
                $interface['MTU'] = (string) $this->getAwgClientMtu();
            }
            $clientConf = [
                'interface' => array_merge(
                    $interface,
                    !empty($this->getPacConf()[$this->getInstanceWG(1) . 'amnezia']) ? $this->amneziaKeys() : []
                ),
                'peers' => [$clientPeer],
            ];
            $clients[] = $clientConf;
            $changedClients = true;
            $changedServer = true;
        }

        if ($changedClients) {
            $this->saveClients($clients);
        }
        if ($changedServer && !$this->restartWG($this->createConfig($server))) {
            error_log("ensureDeviceWgProfile: restartWG failed for device $deviceUuid");
        }

        return $clientConf ?? null;
    }

    protected function splitEndpointHostPort(string $endpoint): array
    {
        $endpoint = trim($endpoint);
        if ($endpoint === '') {
            return ['', 0];
        }
        if (preg_match('~^\[([^\]]+)\]:(\d+)$~', $endpoint, $m)) {
            return [$m[1], (int) $m[2]];
        }
        if (preg_match('~^([^:]+):(\d+)$~', $endpoint, $m)) {
            return [$m[1], (int) $m[2]];
        }
        return [$endpoint, 0];
    }

    protected function runInRuntimeWgContext(callable $callback)
    {
        $hadWgContext = isset($this->wg);
        $previousWgContext = $hadWgContext ? $this->wg : null;
        // Runtime device WG/AWG must always use WG1 context.
        $this->wg = 1;
        try {
            return $callback();
        } finally {
            if ($hadWgContext) {
                $this->wg = $previousWgContext;
            } else {
                unset($this->wg);
            }
        }
    }

    protected function readClientsRaw(string $path): array
    {
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    protected function getRuntimeDeviceWgClientByRefs(string $deviceUuid = ''): ?array
    {
        if ($deviceUuid === '') {
            return null;
        }
        // Runtime device AWG/WG profiles are stored only in WG1 (clients1.json).
        foreach ($this->readClientsRaw($this->clients1) as $client) {
            if (!is_array($client) || !is_array($client['interface'] ?? null)) {
                continue;
            }
            if (($client['interface']['## device_uuid'] ?? '') === $deviceUuid) {
                return $client;
            }
        }
        return null;
    }

    /**
     * Строка host:port для runtime AWG/WG в подписке (Mihomo и т.д.).
     * Должна совпадать с логикой createConfig(): сначала ## endpoint_custom / Peer.Endpoint, иначе домен или IP из настроек PAC.
     */
    protected function resolveRuntimeDeviceWgEndpointString(array $iface, array $peer): string
    {
        $endpoint = trim((string) ($iface['## endpoint_custom'] ?? $peer['Endpoint'] ?? ''));
        if ($endpoint !== '') {
            return $endpoint;
        }
        $pac  = $this->getPacConf();
        $host = !empty($pac[$this->getInstanceWG(1) . 'endpoint']) ? $this->ip : $this->getDomain();
        $port = getenv($this->getInstanceWG(1) ? 'WG1PORT' : 'WGPORT');

        return $host !== '' && $port !== false && $port !== '' ? $host . ':' . $port : '';
    }

    protected function buildRuntimeWgClashProxy(array $ownerClient, string $hwid = '', string $deviceUuid = ''): ?array
    {
        if (!$this->isRuntimeDeviceWgEnabled($ownerClient)) {
            return null;
        }
        // AWG/WG device proxy is strictly bound to a runtime device UUID.
        // If UUID isn't resolved for current request, don't expose it in subscription.
        if ($deviceUuid === '') {
            return null;
        }

        $proxy = $this->runInRuntimeWgContext(function () use ($deviceUuid) {
            return $this->buildRuntimeWgClashProxyInWg1Context($deviceUuid);
        });

        return is_array($proxy) ? $proxy : null;
    }

    protected function buildRuntimeWgClashProxyInWg1Context(string $deviceUuid): ?array
    {
        $wgClient = $this->getRuntimeDeviceWgClientByRefs($deviceUuid);
        if (!is_array($wgClient)) {
            return null;
        }
        $iface = $wgClient['interface'] ?? [];
        $peer = $wgClient['peers'][0] ?? [];
        if (!is_array($iface) || !is_array($peer)) {
            return null;
        }

        $privateKey = (string) ($iface['PrivateKey'] ?? '');
        $publicKey = (string) ($peer['PublicKey'] ?? '');
        if ($privateKey === '' || $publicKey === '') {
            return null;
        }

        $endpoint = $this->resolveRuntimeDeviceWgEndpointString($iface, $peer);
        [$server, $port] = $this->splitEndpointHostPort($endpoint);
        if ($server === '' || $port <= 0) {
            return null;
        }

        $address = (string) ($iface['Address'] ?? '');
        $ip = '';
        $ipv6 = '';
        foreach (array_map('trim', explode(',', $address)) as $entry) {
            $entry = preg_replace('~/.*$~', '', $entry);
            if ($entry === '') {
                continue;
            }
            if (strpos($entry, ':') !== false) {
                $ipv6 = $entry;
            } else {
                $ip = $entry;
            }
        }

        $allowedIpsRaw = (string) ($peer['AllowedIPs'] ?? '0.0.0.0/0');
        $allowedIps = array_values(array_filter(array_map('trim', explode(',', $allowedIpsRaw))));
        if (empty($allowedIps)) {
            $allowedIps = ['0.0.0.0/0'];
        }

        $proxyName = !empty($this->getPacConf()[$this->getInstanceWG(1) . 'amnezia']) ? 'AWG Device' : 'WG Device';
        $proxy = [
            'name' => $proxyName,
            'type' => 'wireguard',
            'server' => $server,
            'port' => $port,
            'private-key' => $privateKey,
            'public-key' => $publicKey,
            'allowed-ips' => $allowedIps,
            'udp' => true,
        ];
        if ($ip !== '') {
            $proxy['ip'] = $ip;
        }
        if ($ipv6 !== '') {
            $proxy['ipv6'] = $ipv6;
        }
        if (!empty($peer['PresharedKey'])) {
            $proxy['pre-shared-key'] = (string) $peer['PresharedKey'];
        }
        if (!empty($peer['PersistentKeepalive'])) {
            $proxy['persistent-keepalive'] = (int) $peer['PersistentKeepalive'];
        }
        if (!empty($iface['MTU'])) {
            $proxy['mtu'] = (int) $iface['MTU'];
        } elseif (!empty($this->getPacConf()[$this->getInstanceWG(1) . 'amnezia'])) {
            $proxy['mtu'] = $this->getAwgClientMtu();
        }

        $amneziaMap = [
            'S1' => 's1',
            'S2' => 's2',
            'S3' => 's3',
            'S4' => 's4',
            'H1' => 'h1',
            'H2' => 'h2',
            'H3' => 'h3',
            'H4' => 'h4',
            'I1' => 'i1',
            'I2' => 'i2',
            'I3' => 'i3',
            'I4' => 'i4',
            'I5' => 'i5',
        ];
        $amneziaOption = [];
        foreach ($amneziaMap as $src => $dst) {
            if (array_key_exists($src, $iface) && $iface[$src] !== '' && $iface[$src] !== null) {
                $amneziaOption[$dst] = is_numeric($iface[$src]) ? (int) $iface[$src] : $iface[$src];
            }
        }
        if (!empty($amneziaOption)) {
            $proxy['amnezia-wg-option'] = $amneziaOption;
        }

        return $proxy;
    }

    protected function deleteDeviceWgProfileByUuid(string $deviceUuid): void
    {
        if ($deviceUuid === '') {
            return;
        }
        $clients = $this->readClients();
        $idx = null;
        $client = null;
        foreach ($clients as $k => $v) {
            if (($v['interface']['## device_uuid'] ?? '') === $deviceUuid) {
                $idx = $k;
                $client = $v;
                break;
            }
        }
        if ($idx === null || !is_array($client)) {
            return;
        }
        $private = (string) ($client['interface']['PrivateKey'] ?? '');
        $pub = $private !== '' ? $this->wgPublicKeyFromPrivate($private) : '';

        unset($clients[$idx]);
        $this->saveClients(array_values($clients));

        $server = $this->readConfig();
        if (!empty($server['peers']) && is_array($server['peers'])) {
            $changed = false;
            foreach ($server['peers'] as $k => $peer) {
                if (($peer['## device_uuid'] ?? '') === $deviceUuid || (!empty($pub) && ($peer['PublicKey'] ?? '') === $pub)) {
                    unset($server['peers'][$k]);
                    $changed = true;
                }
            }
            if ($changed) {
                $server['peers'] = array_values($server['peers']);
                $this->restartWG($this->createConfig($server));
            }
        }
    }
    protected function ensureRuntimeDeviceUuid(array $ownerClient, int $ownerIndex, string $hwid, int $limit): ?string
    {
        $xray = $this->getXray();
        if (!isset($xray['inbounds'][0]['settings']['clients'][$ownerIndex])) {
            return null;
        }

        $changed = $this->ensureOwnerSubscriptionAnchor($xray, $ownerIndex);
        $owner = $xray['inbounds'][0]['settings']['clients'][$ownerIndex];
        $ownerSubId = $this->getClientSubscriptionId($owner);

        $storage = $this->getHwidStorage();
        $devices = $storage[$ownerSubId] ?? [];
        if (!is_array($devices)) {
            $devices = [];
        }

        // Build an index of existing runtime device clients for this subscription.
        // One (parent_id, hwid) must map to exactly one device UUID.
        $existingByHwid = [];
        foreach (($xray['inbounds'][0]['settings']['clients'] ?? []) as $idx => $client) {
            if (($client['device_parent_id'] ?? '') !== $ownerSubId) {
                continue;
            }
            $childHwid = (string) ($client['device_hwid'] ?? '');
            $childId = (string) ($client['id'] ?? '');
            if ($childHwid === '' || $childId === '') {
                continue;
            }
            if (!isset($existingByHwid[$childHwid])) {
                $existingByHwid[$childHwid] = [
                    'id' => $childId,
                    'idx' => $idx,
                ];
                continue;
            }
            // Duplicate child for same parent+hwid: keep first, remove the rest.
            unset($xray['inbounds'][0]['settings']['clients'][$idx]);
            $changed = true;
        }

        // Sync storage with already existing runtime children.
        foreach ($existingByHwid as $existingHwid => $meta) {
            if (!isset($devices[$existingHwid]) || !is_array($devices[$existingHwid])) {
                $devices[$existingHwid] = [
                    'time' => time(),
                    'user_agent' => '',
                    'device_os' => '',
                    'os_version' => '',
                    'device_model' => '',
                    'device_uuid' => (string) $meta['id'],
                    'runtime_confirmed' => 0,
                ];
                continue;
            }
            if (($devices[$existingHwid]['device_uuid'] ?? '') !== (string) $meta['id']) {
                $devices[$existingHwid]['device_uuid'] = (string) $meta['id'];
            }
        }

        foreach ($devices as $storedHwid => $info) {
            if (!is_array($info)) {
                continue;
            }
            if (!empty($info['device_uuid'])) {
                if ($this->findXrayClientIndexById($xray, (string) $info['device_uuid']) === null) {
                    $xray['inbounds'][0]['settings']['clients'][] = $this->createRuntimeDeviceClient($owner, $ownerSubId, (string) $info['device_uuid'], (string) $storedHwid);
                    $changed = true;
                }
                continue;
            }

            // If runtime client already exists for this HWID, reuse it.
            if (!empty($existingByHwid[$storedHwid]['id'])) {
                $devices[$storedHwid]['device_uuid'] = (string) $existingByHwid[$storedHwid]['id'];
                continue;
            }

            $deviceUuid = $this->createXrayUuid();
            while ($this->findXrayClientIndexById($xray, $deviceUuid) !== null) {
                $deviceUuid = $this->createXrayUuid();
            }
            $devices[$storedHwid]['device_uuid'] = $deviceUuid;
            $xray['inbounds'][0]['settings']['clients'][] = $this->createRuntimeDeviceClient($owner, $ownerSubId, $deviceUuid, (string) $storedHwid);
            $changed = true;
        }

        if (!isset($devices[$hwid])) {
            if ($limit > 0 && count($devices) >= $limit) {
                return null;
            }
            $deviceUuid = '';
            if (!empty($existingByHwid[$hwid]['id'])) {
                $deviceUuid = (string) $existingByHwid[$hwid]['id'];
            } else {
                $deviceUuid = $this->createXrayUuid();
                while ($this->findXrayClientIndexById($xray, $deviceUuid) !== null) {
                    $deviceUuid = $this->createXrayUuid();
                }
            }
            $devices[$hwid] = [
                'time' => time(),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'device_os' => $_SERVER['HTTP_X_DEVICE_OS'] ?? '',
                'os_version' => $_SERVER['HTTP_X_VER_OS'] ?? '',
                'device_model' => $_SERVER['HTTP_X_DEVICE_MODEL'] ?? '',
                'device_uuid' => $deviceUuid,
                'runtime_confirmed' => 1,
            ];
            if (empty($existingByHwid[$hwid]['id'])) {
                $xray['inbounds'][0]['settings']['clients'][] = $this->createRuntimeDeviceClient($owner, $ownerSubId, $deviceUuid, $hwid);
                $changed = true;
            }
        } else {
            if (empty($devices[$hwid]['device_uuid'])) {
                if (!empty($existingByHwid[$hwid]['id'])) {
                    $deviceUuid = (string) $existingByHwid[$hwid]['id'];
                } else {
                    $deviceUuid = $this->createXrayUuid();
                    while ($this->findXrayClientIndexById($xray, $deviceUuid) !== null) {
                        $deviceUuid = $this->createXrayUuid();
                    }
                    $xray['inbounds'][0]['settings']['clients'][] = $this->createRuntimeDeviceClient($owner, $ownerSubId, $deviceUuid, $hwid);
                    $changed = true;
                }
                $devices[$hwid]['device_uuid'] = $deviceUuid;
            }
            $devices[$hwid]['time'] = time();
            $devices[$hwid]['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $devices[$hwid]['device_os'] = $_SERVER['HTTP_X_DEVICE_OS'] ?? '';
            $devices[$hwid]['os_version'] = $_SERVER['HTTP_X_VER_OS'] ?? '';
            $devices[$hwid]['device_model'] = $_SERVER['HTTP_X_DEVICE_MODEL'] ?? '';
            $devices[$hwid]['runtime_confirmed'] = 1;
        }

        // Parent UUID is retired as soon as the first runtime device is registered.
        if ($this->retireParentRuntimeUuid($xray, $ownerIndex)) {
            $changed = true;
        }

        $storage[$ownerSubId] = $devices;
        $this->setHwidStorage($storage);

        if ($changed) {
            $this->restartXray($xray);
        } else {
            $this->writeXrayConfig($xray);
        }

        return (string) ($devices[$hwid]['device_uuid'] ?? '');
    }

    public function processHwidRequest(array $client, ?int $clientIndex = null)
    {
        $pac = $this->getPacConf();
        $runtimeModeEnabled = $this->isHwidRuntimeModeEnabled($client);
        header('X-HWID-Runtime-Mode: ' . ($runtimeModeEnabled ? 'on' : 'off'));

        $hwidLimitActive = !empty($pac['hwid_limit_enabled']) && empty($client['hwid_disabled']);
        $hwidNotSupported = false;
        $hwidMaxReached = false;

        if (!$hwidLimitActive) {
            header('x-hwid-active: false');
            header('x-hwid-not-supported: false');
            header('x-hwid-max-devices-reached: false');
            header('x-hwid-limit: false');
            return true;
        }

        $limit = (int) ($client['hwid_limit'] ?? $pac['hwid_device_count'] ?? 0);
        if ($limit <= 0) {
            header('x-hwid-active: true');
            header('x-hwid-not-supported: false');
            header('x-hwid-max-devices-reached: false');
            header('x-hwid-limit: false');
            return true;
        }

        $ownerSubId = $this->getClientSubscriptionId($client);
        $devices   = $this->getHwidDevicesByUser($ownerSubId);
        $hwid      = trim($_SERVER['HTTP_X_HWID'] ?? '');
        $isBrowser = $this->isBrowserRequest();
	$path      = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
	$segments  = explode('/', trim($path, '/'));
        $token     = end($segments);
        $params    = $this->decodePacUrlPayload((string) $token);
        $isRuleRequest = is_array($params) && !empty($params['r']);

        $hwidNotSupported = !$isRuleRequest && !$isBrowser && $hwid === '';
        $hwidMaxReached = count($devices) >= $limit;
        header('x-hwid-active: true');
        header('x-hwid-not-supported: ' . ($hwidNotSupported ? 'true' : 'false'));
        header('x-hwid-max-devices-reached: ' . ($hwidMaxReached ? 'true' : 'false'));
        header('x-hwid-limit: ' . ($hwidMaxReached ? 'true' : 'false'));

        if (!$isRuleRequest && $hwid === '') {
            if ($isBrowser) {
                if ($this->isPermanentHwidRuntime($client)) {
                    $_SERVER['VPNBOT_SUBSCRIPTION_BROWSER'] = '1';
                }
                return true;
            }

            $message = 'HWID device limit exceeded';
            header('announce: base64:' . base64_encode($message));
            header('X-HWID-Status: ' . $message);
            header('HTTP/1.1 403 Forbidden', true, 403);

            return false;
        }
	if ($isRuleRequest) {
  return true;
}
        $isNew = !isset($devices[$hwid]);

        if ($isNew && count($devices) >= $limit) {
            $message = 'HWID device limit exceeded';
            header('announce: base64:' . base64_encode($message));
            header('X-HWID-Status: ' . $message);
            header('HTTP/1.1 429 Too Many Requests', true, 429);
            return false;
        }

        $this->setHwidDevice($ownerSubId, $hwid, [
            'time'         => time(),
            'user_agent'   => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'device_os'    => $_SERVER['HTTP_X_DEVICE_OS'] ?? '',
            'os_version'   => $_SERVER['HTTP_X_VER_OS'] ?? '',
            'device_model' => $_SERVER['HTTP_X_DEVICE_MODEL'] ?? '',
        ]);

        if ($runtimeModeEnabled && $clientIndex !== null) {
            $deviceUuid = $this->ensureRuntimeDeviceUuid($client, $clientIndex, $hwid, $limit);
            if ($deviceUuid === null || $deviceUuid === '') {
                $message = 'HWID device limit exceeded';
                header('announce: base64:' . base64_encode($message));
                header('X-HWID-Status: ' . $message);
                header('HTTP/1.1 429 Too Many Requests', true, 429);
                return false;
            }
            $_SERVER['VPNBOT_DEVICE_UUID'] = $deviceUuid;
            if ($this->isRuntimeDeviceWgEnabled($client)) {
                $this->runInRuntimeWgContext(function () use ($ownerSubId, $hwid, $deviceUuid) {
                    $this->ensureDeviceWgProfile($ownerSubId, $hwid, $deviceUuid);
                });
            }
        } else {
            unset($_SERVER['VPNBOT_DEVICE_UUID']);
        }

        return true;
    }

    public function isHwidRuntimeModeEnabled(array $client): bool
    {
        $pac = $this->getPacConf();
        $globalEnabled = !empty($pac['hwid_runtime_mode_enabled']);

        // Per-subscription override has higher priority than global setting.
        // 1/0 are used as explicit values, absence means "use global".
        if (array_key_exists('hwid_runtime_mode', $client)) {
            return !empty($client['hwid_runtime_mode']);
        }

        // Backward-compatible explicit per-client disable switch.
        if (!empty($client['hwid_runtime_disabled'])) {
            return false;
        }

        return $globalEnabled;
    }

    protected function isBrowserRequest()
    {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $accept    = $_SERVER['HTTP_ACCEPT'] ?? '';

        if ($userAgent === '' && $accept === '') {
            return false;
        }

        $browserPatterns = [
            'Mozilla/',
            'Chrome/',
            'Safari/',
            'Firefox/',
            'Edge/',
            'Edg/',
            'MSIE ',
            'Trident/',
            'Opera/',
            'OPR/',
        ];

        foreach ($browserPatterns as $pattern) {
            if (stripos($userAgent, $pattern) !== false) {
                return true;
            }
        }

        if (stripos($accept, 'text/html') !== false) {
            return true;
        }

        return false;
    }
    public function xrayHwid()
    {
        $p = $this->getPacConf();
        $text[] = "Menu -> " . $this->i18n('xray') . " -> limits & HWID/runtime";
        $ipCount = (int) ($p['ip_count'] ?? 1);
        $hwidEnabled = !empty($p['hwid_limit_enabled']);
        $runtimeGlobal = !empty($p['hwid_runtime_mode_enabled']);
        $runtimeWgEnabled = !empty($p['hwid_runtime_wg_profile_enabled']);
        $runtimeWgEndpoint = trim((string) ($p['hwid_runtime_wg_endpoint'] ?? ''));
        $defaultHwids = max(1, (int) ($p['hwid_device_count'] ?: 1));

        $text[] = 'ip limit: ' . (!empty($p['ip_limit']) ? "{$p['ip_limit']} sec & {$ipCount}" : 'off');
        $text[] = 'hwid limit: ' . ($hwidEnabled ? 'on' : 'off') . " ({$defaultHwids})";
        $text[] = 'runtime mode: ' . ($runtimeGlobal ? 'on' : 'off');
        $text[] = 'runtime WG/AWG profile: ' . ($runtimeWgEnabled ? 'on' : 'off');
        $text[] = 'runtime endpoint: ' . ($runtimeWgEndpoint ?: 'default');

        $data[] = [[
            'text'          => $this->i18n('ip limit') . ' ' . (!empty($p['ip_limit']) ? ": {$p['ip_limit']} sec & {$ipCount}" : $this->i18n('off')),
            'callback_data' => "/setIpLimit",
        ]];
        $data[] = [
            [
                'text'          => $this->i18n('hwid limit') . ': ' . $this->i18n($hwidEnabled ? 'on' : 'off') . " ({$defaultHwids})",
                'callback_data' => '/toggleHwidLimit xray',
            ],
            [
                'text'          => $this->i18n('set hwid devices count'),
                'callback_data' => '/setHwidDevices xray',
            ],
        ];
        $data[] = [[
            'text' => 'HWID runtime mode: ' . $this->i18n($runtimeGlobal ? 'on' : 'off'),
            'callback_data' => '/toggleHwidRuntimeMode xray',
        ]];
            $data[] = [
                [
                'text'          => 'runtime WG/AWG profile: ' . $this->i18n($runtimeWgEnabled ? 'on' : 'off'),
                'callback_data' => '/toggleRuntimeWgProfile',
            ],
            [
                'text'          => 'runtime endpoint: ' . ($runtimeWgEndpoint ?: 'default'),
                'callback_data' => '/setRuntimeWgEndpoint',
            ],
        ];
        $data[] = [[
            'text' => $this->i18n('back'),
            'callback_data' => '/xray',
        ]];
        $this->update(
            $this->input['chat'],
            $this->input['message_id'],
            implode("\n", $text ?: ['...']),
            $data ?: false,
        );
    }
    public function hwidUser($i, $page = 0)
    {
        $xray   = $this->getXray();
        $client = $xray['inbounds'][0]['settings']['clients'][$i];
        $pac    = $this->getPacConf();

        $ownerSubId = $this->getClientSubscriptionId($client);
        $devices = $this->getHwidDevicesByUser($ownerSubId);
        $scope   = $this->getHwidTokenScope($i);
        if (!isset($_SESSION['hwidTokens'])) {
            $_SESSION['hwidTokens'] = [];
        }
        $_SESSION['hwidTokens'][$scope] = [];
        uasort($devices, fn($a, $b) => ($b['time'] ?? 0) <=> ($a['time'] ?? 0));
        $hwids        = array_keys($devices);
        $perPage      = max(1, $this->limit ?: 5);
        $total        = count($hwids);
        $pages        = max(1, (int) ceil($total / $perPage));
        $page         = min(max((int) $page, 0), $pages - 1);
        $hwidsPage    = array_slice($hwids, $page * $perPage, $perPage);
        $defaultHwid  = max(1, (int) ($pac['hwid_device_count'] ?: 1));

        $text[] = "Menu -> " . $this->i18n('xray') . " -> {$client['email']} -> " . $this->i18n('hwid devices');
        $text[] = $this->i18n('hwid notice');
        if (empty($pac['hwid_limit_enabled'])) {
            $status = $this->i18n('off');
        } elseif (!empty($client['hwid_disabled'])) {
            $status = $this->i18n('off');
        } elseif (!empty($client['hwid_limit'])) {
            $status = (int) $client['hwid_limit'];
        } else {
            $status = "default($defaultHwid)";
        }
        $text[] = $this->i18n('hwid limit') . ': ' . $status;
        $text[] = $this->i18n('hwid devices') . ': ' . $total;
        $runtimeStatus = array_key_exists('hwid_runtime_mode', $client)
            ? ($this->i18n(!empty($client['hwid_runtime_mode']) ? 'on' : 'off') . ' (override)')
            : ('default(' . $this->i18n(!empty($pac['hwid_runtime_mode_enabled']) ? 'on' : 'off') . ')');
        $text[] = 'HWID runtime mode: ' . $runtimeStatus . $this->getRuntimeParentRetiredEmoji($client);
        if ($this->isRuntimeParentRetired($client)) {
            $text[] = $this->i18n('hwid runtime parent retired') . ' ✅';
        }

        $data[] = [
            [
                'text'          => $this->i18n(!empty($client['hwid_disabled']) ? 'off' : 'on'),
                'callback_data' => "/hwidUserToggle $i",
            ],
        ];
        $data[] = [
            [
                'text'          => $this->i18n('set hwid devices count'),
                'callback_data' => "/setHwidUserLimit $i",
            ],
            [
                'text'          => 'HWID runtime mode',
                'callback_data' => "/hwidUserRuntimeMode $i",
            ],
        ];
        if (!empty($client['hwid_limit'])) {
            $data[] = [
                [
                    'text'          => $this->i18n('use default hwid limit'),
                    'callback_data' => "/hwidUserDefault $i",
                ],
            ];
        }

        if ($total == 0) {
            $text[] = $this->i18n('no devices');
        }

        $deviceTraffic = $this->getHwidDeviceTraffic($ownerSubId);
        $wgProfileEnabled = $this->isRuntimeDeviceWgEnabled($client);
        foreach ($hwidsPage as $index => $hwid) {
            $info    = $devices[$hwid];
            $number  = $page * $perPage + $index + 1;
            $details = array_filter([
                $info['device_os'] ?? '',
                $info['os_version'] ?? '',
                $info['device_model'] ?? '',
            ], fn($v) => $v !== '');
            $text[] = str_repeat('-', 50);
            $text[] = $number . '. HWID: <code>' . htmlspecialchars($hwid, ENT_HTML5, 'UTF-8') . '</code>';
            $osLine = htmlspecialchars(implode(' ', $details), ENT_HTML5, 'UTF-8');
            if (!empty($info['time'])) {
                $osLine .= ($osLine !== '' ? ' ' : '') . '(' . date('d.m.Y H:i', $info['time']) . ')';
            }
            if ($osLine !== '') {
                $text[] = '  OS: ' . $osLine;
            }
            if (!empty($info['user_agent'])) {
                $text[] = '  UA: ' . htmlspecialchars($info['user_agent'], ENT_HTML5, 'UTF-8');
            }
            $devDown = (int) ($deviceTraffic[$hwid]['download'] ?? 0);
            $devUp = (int) ($deviceTraffic[$hwid]['upload'] ?? 0);
            $deviceUuid = (string) ($deviceTraffic[$hwid]['device_uuid'] ?? ($info['device_uuid'] ?? ''));
            $awg = $this->getRuntimeDeviceAwgStats($deviceUuid, $wgProfileEnabled);
            $trafficLine = '  ' . $this->formatTrafficDisplayLine($devDown, $devUp, $wgProfileEnabled ? $awg : null);
            if ($wgProfileEnabled) {
                $trafficLine .= '     AWG:' . (!empty($awg['online']) ? 'On' : 'Off');
            }
            $text[] = $trafficLine;
            $token = $this->rememberHwidToken($scope, $hwid);
            $data[] = [
                [
                    'text'          => 'del ' . $number,
                    'callback_data' => "/hwidUserDel {$i}_{$page} $token",
                ],
            ];
        }

        if ($pages > 1) {
            $data[] = [
                [
                    'text'          => '<<',
                    'callback_data' => "/hwidUser {$i}_" . ($page - 1 >= 0 ? $page - 1 : $pages - 1),
                ],
                [
                    'text'          => ($page + 1) . '/' . $pages,
                    'callback_data' => "/hwidUser {$i}_$page",
                ],
                [
                    'text'          => '>>',
                    'callback_data' => "/hwidUser {$i}_" . (($page + 1) % $pages),
                ],
            ];
        }

        $data[] = [
            [
                'text'          => $this->i18n('back'),
                'callback_data' => "/userXr $i",
            ],
        ];

        $this->update(
            $this->input['chat'],
            $this->input['message_id'],
            implode("\n", $text ?: ['...']),
            $data ?: false,
        );
    }

    public function hwidUserToggle($i)
    {
        $xray = $this->getXray();
        if (!empty($xray['inbounds'][0]['settings']['clients'][$i]['hwid_disabled'])) {
            unset($xray['inbounds'][0]['settings']['clients'][$i]['hwid_disabled']);
        } else {
            $xray['inbounds'][0]['settings']['clients'][$i]['hwid_disabled'] = 1;
        }
        $this->writeXrayConfig($xray);
        $this->answer($this->input['callback_id'], $this->i18n('hwid notice'), true);
        $this->hwidUser($i);
    }

    public function hwidUserRuntimeMode($i)
    {
        $xray = $this->getXray();
        $client = &$xray['inbounds'][0]['settings']['clients'][$i];

        // Cycle mode: default -> on -> off -> default
        if (!array_key_exists('hwid_runtime_mode', $client)) {
            $client['hwid_runtime_mode'] = 1;
        } elseif (!empty($client['hwid_runtime_mode'])) {
            $client['hwid_runtime_mode'] = 0;
        } else {
            unset($client['hwid_runtime_mode']);
        }

        $this->writeXrayConfig($xray);
        $this->hwidUser($i);
    }

    public function hwidUserDefault($i)
    {
        $xray = $this->getXray();
        unset($xray['inbounds'][0]['settings']['clients'][$i]['hwid_limit']);
        $this->writeXrayConfig($xray);
        $this->hwidUser($i);
    }

    public function setHwidUserLimit($i)
    {
        $r = $this->send(
            $this->input['chat'],
            "@{$this->input['username']} enter hwid devices count",
            $this->input['message_id'],
            reply: 'enter hwid devices count',
        );
        $_SESSION['reply'][$r['result']['message_id']] = [
            'start_message' => $this->input['message_id'],
            'callback'      => 'saveHwidUserLimit',
            'args'          => [$i],
        ];
    }

    public function saveHwidUserLimit($count, $i)
    {
        $xray = $this->getXray();
        $count = (int) $count;
        if ($count > 0) {
            $xray['inbounds'][0]['settings']['clients'][$i]['hwid_limit'] = $count;
        } else {
            unset($xray['inbounds'][0]['settings']['clients'][$i]['hwid_limit']);
        }
        $this->writeXrayConfig($xray);
        $this->send($this->input['chat'], $this->i18n('hwid notice'), $this->input['message_id']);
        $this->hwidUser($i);
    }

    public function hwidUserDel($i, $page, $hwid)
    {
        $xray = $this->getXray();
        $ownerSubId = $this->getClientSubscriptionId($xray['inbounds'][0]['settings']['clients'][$i]);
        $scope  = $this->getHwidTokenScope($i);
        $decoded = $this->resolveHwidToken($scope, $hwid);
        if ($decoded !== '') {
            $devices = $this->getHwidDevicesByUser($ownerSubId);
            $deviceUuid = (string) ($devices[$decoded]['device_uuid'] ?? '');
            $this->deleteHwidDevice($ownerSubId, $decoded);
            if ($deviceUuid !== '') {
                $idx = $this->findXrayClientIndexById($xray, $deviceUuid);
                if ($idx !== null) {
                    unset($xray['inbounds'][0]['settings']['clients'][$idx]);
                    $this->restartXray($xray);
                }
                $this->runInRuntimeWgContext(function () use ($deviceUuid) {
                    $this->deleteDeviceWgProfileByUuid($deviceUuid);
                });
            }
        }
        $this->hwidUser($i, $page);
    }
}
