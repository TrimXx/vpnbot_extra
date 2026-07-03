<?php

trait UserPortalTrait
{
    protected function isUserPortalEnabled(): bool
    {
        $pac = $this->getPacConf();

        return !empty($pac['user_portal_enabled']);
    }

    protected function ensureUserPortalSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_id((string) ($this->input['from'] ?? ''));
            session_start();
        }
    }

    protected function isUserPortalReplyCallback(string $callback): bool
    {
        return in_array($callback, ['userPortalImportLink', 'userPortalDeleteDevicePassword'], true);
    }

    protected function getPendingUserPortalReply(): ?array
    {
        $this->ensureUserPortalSession();
        foreach ($_SESSION['reply'] ?? [] as $messageId => $reply) {
            if (!is_array($reply)) {
                continue;
            }
            $callback = (string) ($reply['callback'] ?? '');
            if ($this->isUserPortalReplyCallback($callback)) {
                return array_merge($reply, ['message_id' => $messageId]);
            }
        }

        return null;
    }

    protected function clearPendingUserPortalReply(?string $callback = null): void
    {
        $this->ensureUserPortalSession();
        foreach ($_SESSION['reply'] ?? [] as $messageId => $reply) {
            if (!is_array($reply)) {
                continue;
            }
            $cb = (string) ($reply['callback'] ?? '');
            if ($callback !== null && $cb !== $callback) {
                continue;
            }
            if ($this->isUserPortalReplyCallback($cb)) {
                $this->delete($this->input['chat'], $messageId);
                unset($_SESSION['reply'][$messageId]);
            }
        }
        if (empty($_SESSION['reply'])) {
            unset($_SESSION['reply']);
        }
    }

    protected function shouldHandleUserPortalTextInput(): bool
    {
        $message = trim((string) ($this->input['message'] ?? ''));
        if ($message === '' || preg_match('~^/~', $message)) {
            return false;
        }
        if ($this->parseSubscriptionLink($message) !== null) {
            return true;
        }

        return $this->getPendingUserPortalReply() !== null;
    }

    public function handleUserPortalTextInput(): void
    {
        $message = trim((string) ($this->input['message'] ?? ''));
        $pending = $this->getPendingUserPortalReply();
        if ($pending !== null) {
            $callback = (string) ($pending['callback'] ?? '');
            $args = $pending['args'] ?? [];
            $this->clearPendingUserPortalReply($callback);
            if ($callback === 'userPortalImportLink') {
                $this->userPortalImportLink($message);

                return;
            }
            if ($callback === 'userPortalDeleteDevicePassword') {
                $this->userPortalDeleteDevicePassword($message, ...$args);

                return;
            }
        }
        if ($this->parseSubscriptionLink($message) !== null) {
            $this->userPortalImportLink($message);
        }
    }

    protected function isUserPortalRequest(): bool
    {
        $message = (string) ($this->input['message'] ?? '');
        $callback = (string) ($this->input['callback'] ?? '');

        if (preg_match('~^/(?:start|menu)$~', $message)) {
            return true;
        }
        if (preg_match('~^/userPortal~', $callback)) {
            return true;
        }
        if (!empty($this->input['reply'])) {
            $this->ensureUserPortalSession();
            $reply = $_SESSION['reply'][$this->input['reply']] ?? null;
            if (is_array($reply)) {
                $cb = (string) ($reply['callback'] ?? '');
                if ($this->isUserPortalReplyCallback($cb)) {
                    return true;
                }
            }
        }
        $text = trim($message);
        if ($text !== '' && !preg_match('~^/~', $text)) {
            if ($this->parseSubscriptionLink($text) !== null) {
                return true;
            }
            if ($this->getPendingUserPortalReply() !== null) {
                return true;
            }
        }

        return false;
    }

    protected function getUserPortalTokenScope(): string
    {
        return 'userPortal_' . (string) ($this->input['from'] ?? '0');
    }

    protected function bindUserPortalSession(string $subscriptionId): bool
    {
        $resolved = $this->resolveSubscriptionClient($subscriptionId);
        if ($resolved === null) {
            return false;
        }
        $_SESSION['userPortal'] = [
            'subId' => $resolved['subscription_id'],
            'clientIndex' => $resolved['index'],
            'telegram_id' => (string) ($this->input['from'] ?? ''),
        ];

        return true;
    }

    protected function getUserPortalSession(): ?array
    {
        $portal = $_SESSION['userPortal'] ?? null;
        if (!is_array($portal)) {
            return null;
        }
        if ((string) ($portal['telegram_id'] ?? '') !== (string) ($this->input['from'] ?? '')) {
            return null;
        }
        $resolved = $this->resolveSubscriptionClient((string) ($portal['subId'] ?? ''));
        if ($resolved === null) {
            unset($_SESSION['userPortal']);

            return null;
        }

        return array_merge($portal, [
            'subscription_id' => $resolved['subscription_id'],
            'client' => $resolved['client'],
            'clientIndex' => $resolved['index'],
        ]);
    }

    protected function clearUserPortalSession(): void
    {
        unset($_SESSION['userPortal']);
    }

    protected function extractUrlFromText(string $text): string
    {
        $text = trim($text);
        if (preg_match('~https?://\S+~i', $text, $m)) {
            return rtrim($m[0], ".,;)>\"'");
        }
        if ($text !== '' && !preg_match('~^https?://~i', $text)) {
            return 'https://' . ltrim($text, '/');
        }

        return $text;
    }

    protected function parseSubscriptionLink(string $raw): ?string
    {
        $url = $this->extractUrlFromText($raw);
        if ($url === '') {
            return null;
        }
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return null;
        }

        $query = [];
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);
        }

        $subscriptionId = '';
        $path = (string) ($parts['path'] ?? '');

        if (str_contains($path, '/sub')) {
            $subscriptionId = (string) ($query['id'] ?? '');
        }
        if ($subscriptionId === '' && !empty($query['s'])) {
            $subscriptionId = (string) $query['s'];
        }
        if ($subscriptionId === '' && preg_match('~/(pac[^/?#]+)~', $path, $m)) {
            $decoded = $this->decodePacUrlPayload($m[1]);
            if (is_array($decoded) && !empty($decoded['s'])) {
                $subscriptionId = (string) $decoded['s'];
            }
        }

        $subscriptionId = trim($subscriptionId);

        return $subscriptionId !== '' ? $subscriptionId : null;
    }

    protected function resolveSubscriptionClient(string $subscriptionId): ?array
    {
        $subscriptionId = trim($subscriptionId);
        if ($subscriptionId === '') {
            return null;
        }
        $xr = $this->getXray();
        foreach ($xr['inbounds'][0]['settings']['clients'] as $k => $v) {
            if (!is_array($v) || !empty($v['device_parent_id'])) {
                continue;
            }
            if (!$this->isSubscriptionIdMatch($v, $subscriptionId)) {
                continue;
            }
            if (!empty($v['off'])) {
                return null;
            }

            return [
                'index' => $k,
                'client' => $v,
                'subscription_id' => $this->getClientSubscriptionId($v),
            ];
        }

        return null;
    }

    protected function buildUserSubscriptionLinks(string $subscriptionId): array
    {
        $pac = $this->getPacConf();
        $useCdnDomain = empty($this->getTransportRegistryGlobal($pac)['reality']);
        $domain = $this->getDomain($useCdnDomain);
        $scheme = empty($this->nginxGetTypeCert()) ? 'http' : 'https';
        $hash = $this->getHashBot();

        return [
            'page' => $this->buildSubscriptionPageUrl($scheme, $domain, $hash, $subscriptionId),
            'clash' => $this->buildPacUrl($scheme, $domain, $hash, [
                'h' => $hash,
                't' => 'cl',
                's' => $subscriptionId,
            ]),
        ];
    }

    public function userPortalMenu()
    {
        $session = $this->getUserPortalSession();
        $text = [$this->i18n('user portal title')];
        if ($session !== null) {
            $email = (string) ($session['client']['email'] ?? '');
            if ($email !== '') {
                $text[] = $this->i18n('user portal account') . ': <code>' . htmlspecialchars($email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>';
            }
        } else {
            $text[] = $this->i18n('user portal hint');
        }

        $data = [
            [
                [
                    'text' => $this->i18n('user portal restore link'),
                    'callback_data' => '/userPortalImport',
                ],
            ],
            [
                [
                    'text' => $this->i18n('user portal my devices'),
                    'callback_data' => '/userPortalDevices',
                ],
            ],
        ];
        if ($session !== null) {
            $links = $this->buildUserSubscriptionLinks($session['subscription_id']);
            $text[] = '';
            $text[] = $this->i18n('user portal new page link') . ':';
            $text[] = $links['page'];
            $text[] = '';
            $text[] = $this->i18n('user portal new clash link') . ':';
            $text[] = $links['clash'];
        }

        if (!empty($this->input['message_id']) && !empty($this->input['callback'])) {
            $this->update($this->input['chat'], $this->input['message_id'], implode("\n", $text), $data);
        } else {
            $this->send($this->input['chat'], implode("\n", $text), $this->input['message_id'] ?? false, $data);
        }
    }

    public function userPortalImport()
    {
        $r = $this->send(
            $this->input['chat'],
            $this->i18n('user portal send old link'),
            $this->input['message_id'],
            reply: $this->i18n('user portal send old link'),
        );
        $_SESSION['reply'][$r['result']['message_id']] = [
            'start_message' => $this->input['message_id'],
            'start_callback' => $this->input['callback_id'],
            'callback' => 'userPortalImportLink',
            'args' => [],
        ];
    }

    public function userPortalImportLink($text = '')
    {
        $text = trim((string) $text);
        if ($text === '') {
            $this->answer($this->input['callback_id'] ?? '', $this->i18n('user portal link not found'), true);

            return;
        }
        $telegramId = (string) ($this->input['from'] ?? '');
        if (!$this->checkSubscriptionActionRateLimit('portal:' . $telegramId, 'import_link', 8, 600)) {
            $this->send($this->input['chat'], $this->i18n('user portal rate limit'), $this->input['message_id'] ?? false);
            $this->userPortalMenu();

            return;
        }

        $subscriptionId = $this->parseSubscriptionLink($text);
        if ($subscriptionId === null || $this->resolveSubscriptionClient($subscriptionId) === null) {
            $this->send($this->input['chat'], $this->i18n('user portal link not found'), $this->input['message_id'] ?? false);
            $this->userPortalMenu();

            return;
        }

        $this->bindUserPortalSession($subscriptionId);
        $session = $this->getUserPortalSession();
        if ($session === null) {
            $this->send($this->input['chat'], $this->i18n('user portal link not found'), $this->input['message_id'] ?? false);
            $this->userPortalMenu();

            return;
        }

        $links = $this->buildUserSubscriptionLinks($session['subscription_id']);
        $lines = [
            $this->i18n('user portal link restored'),
            '',
            $this->i18n('user portal new page link') . ':',
            $links['page'],
            '',
            $this->i18n('user portal new clash link') . ':',
            $links['clash'],
        ];
        $this->send($this->input['chat'], implode("\n", $lines), $this->input['message_id'] ?? false);
        $this->userPortalMenu();
    }

    public function userPortalDevices($page = 0)
    {
        $session = $this->getUserPortalSession();
        if ($session === null) {
            $this->ackCallback($this->i18n('user portal bind first'), true);
            $this->userPortalImport();

            return;
        }

        $ownerSubId = $session['subscription_id'];
        $client = $session['client'];
        $devices = $this->getHwidDevicesByUser($ownerSubId);
        $scope = $this->getUserPortalTokenScope();
        if (!isset($_SESSION['hwidTokens'])) {
            $_SESSION['hwidTokens'] = [];
        }
        $_SESSION['hwidTokens'][$scope] = [];

        uasort($devices, fn($a, $b) => ($b['time'] ?? 0) <=> ($a['time'] ?? 0));
        $hwids = array_keys($devices);
        $perPage = max(1, $this->limit ?: 5);
        $total = count($hwids);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max((int) $page, 0), $pages - 1);
        $hwidsPage = array_slice($hwids, $page * $perPage, $perPage);

        $text = [
            $this->i18n('user portal my devices'),
            (string) ($client['email'] ?? ''),
            $this->i18n('hwid devices') . ': ' . $total,
        ];
        if (!$this->hasSubscriptionDevicePassword($client)) {
            $links = $this->buildUserSubscriptionLinks($ownerSubId);
            $text[] = '';
            $text[] = $this->i18n('user portal set password first');
            $text[] = $links['page'];
        }

        $data = [];
        if ($total === 0) {
            $text[] = $this->i18n('no devices');
        } else {
            $deviceTraffic = $this->getHwidDeviceTraffic($ownerSubId);
            foreach ($hwidsPage as $index => $hwid) {
                $info = $devices[$hwid];
                $number = $page * $perPage + $index + 1;
                $details = array_filter([
                    $info['device_os'] ?? '',
                    $info['os_version'] ?? '',
                    $info['device_model'] ?? '',
                ], fn($v) => $v !== '');
                $text[] = str_repeat('-', 40);
                $text[] = $number . '. <code>' . htmlspecialchars($hwid, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>';
                if ($details !== []) {
                    $text[] = htmlspecialchars(implode(' ', $details), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                }
                if (!empty($info['time'])) {
                    $text[] = date('d.m.Y H:i', (int) $info['time']);
                }
                $devDown = (int) ($deviceTraffic[$hwid]['download'] ?? 0);
                $devUp = (int) ($deviceTraffic[$hwid]['upload'] ?? 0);
                $text[] = $this->formatTrafficDisplayLine($devDown, $devUp);
                if ($this->hasSubscriptionDevicePassword($client)) {
                    $token = $this->rememberHwidToken($scope, $hwid);
                    $data[] = [[
                        'text' => $this->i18n('delete') . ' ' . $number,
                        'callback_data' => "/userPortalDel {$page}_{$token}",
                    ]];
                }
            }
        }

        if ($pages > 1) {
            $data[] = [
                [
                    'text' => '<<',
                    'callback_data' => '/userPortalDevices_' . ($page - 1 >= 0 ? $page - 1 : $pages - 1),
                ],
                [
                    'text' => ($page + 1) . '/' . $pages,
                    'callback_data' => "/userPortalDevices_{$page}",
                ],
                [
                    'text' => '>>',
                    'callback_data' => '/userPortalDevices_' . (($page + 1) % $pages),
                ],
            ];
        }

        $data[] = [[
            'text' => $this->i18n('back'),
            'callback_data' => '/userPortal',
        ]];

        $this->update(
            $this->input['chat'],
            $this->input['message_id'],
            implode("\n", $text),
            $data ?: false,
        );
    }

    public function userPortalDel($pageToken, $token)
    {
        $session = $this->getUserPortalSession();
        if ($session === null) {
            $this->ackCallback($this->i18n('user portal bind first'), true);
            $this->userPortalMenu();

            return;
        }
        if (!$this->hasSubscriptionDevicePassword($session['client'])) {
            $this->ackCallback($this->i18n('user portal set password first'), true);
            $this->userPortalDevices((int) explode('_', (string) $pageToken)[0]);

            return;
        }

        $scope = $this->getUserPortalTokenScope();
        $hwid = $this->resolveHwidToken($scope, $token);
        if ($hwid === '') {
            $this->ackCallback('device not found', true);
            $this->userPortalDevices((int) explode('_', (string) $pageToken)[0]);

            return;
        }

        $r = $this->send(
            $this->input['chat'],
            $this->i18n('user portal enter delete password'),
            $this->input['message_id'],
            reply: $this->i18n('user portal enter delete password'),
        );
        $_SESSION['reply'][$r['result']['message_id']] = [
            'start_message' => $this->input['message_id'],
            'start_callback' => $this->input['callback_id'],
            'callback' => 'userPortalDeleteDevicePassword',
            'args' => [$pageToken, $token],
        ];
    }

    public function userPortalDeleteDevicePassword($password, $pageToken, $token)
    {
        $session = $this->getUserPortalSession();
        if ($session === null) {
            $this->send($this->input['chat'], $this->i18n('user portal bind first'), $this->input['message_id'] ?? false);
            $this->userPortalMenu();

            return;
        }

        $scope = $this->getUserPortalTokenScope();
        $hwid = $this->resolveHwidToken($scope, $token);
        if ($hwid === '') {
            $this->send($this->input['chat'], 'device not found', $this->input['message_id'] ?? false);
            $this->userPortalDevices((int) explode('_', (string) $pageToken)[0]);

            return;
        }

        $ownerSubId = $session['subscription_id'];
        if (!$this->checkSubscriptionActionRateLimit($ownerSubId, 'device_delete', 10, 600)) {
            $this->send($this->input['chat'], $this->i18n('user portal rate limit'), $this->input['message_id'] ?? false);
            $this->userPortalDevices((int) explode('_', (string) $pageToken)[0]);

            return;
        }

        $result = $this->performSubscriptionDeviceDelete($ownerSubId, $hwid, trim((string) $password));
        if (empty($result['ok'])) {
            $this->send($this->input['chat'], (string) ($result['message'] ?? 'error'), $this->input['message_id'] ?? false);
            $this->userPortalDevices((int) explode('_', (string) $pageToken)[0]);

            return;
        }

        $this->send($this->input['chat'], $this->i18n('user portal device deleted'), $this->input['message_id'] ?? false);
        $this->userPortalDevices((int) explode('_', (string) $pageToken)[0]);
    }

    public function toggleUserPortal()
    {
        $pac = $this->getPacConf();
        $pac['user_portal_enabled'] = !empty($pac['user_portal_enabled']) ? 0 : 1;
        $this->setPacConf($pac);
        $this->ackCallback('user portal: ' . $this->i18n(!empty($pac['user_portal_enabled']) ? 'on' : 'off'), true);
        $this->menu('config');
    }
}
