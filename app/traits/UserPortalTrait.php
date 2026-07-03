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
        return in_array($callback, [
            'userPortalImportLink',
            'userPortalDeleteDevicePassword',
            'userPortalSavePassword',
            'userPortalChangePasswordVerify',
            'userPortalRenameDeviceSave',
        ], true);
    }

    protected function getUserPortalUiMessageId(): ?int
    {
        $this->ensureUserPortalSession();
        $id = (int) ($_SESSION['userPortalUi']['message_id'] ?? 0);

        return $id > 0 ? $id : null;
    }

    protected function setUserPortalUiMessageId(int $messageId): void
    {
        $this->ensureUserPortalSession();
        $_SESSION['userPortalUi']['message_id'] = $messageId;
    }

    protected function userPortalSetFlash(string $text): void
    {
        $this->ensureUserPortalSession();
        $_SESSION['userPortalUi']['flash'] = $text;
    }

    protected function userPortalTakeFlash(): string
    {
        $this->ensureUserPortalSession();
        $flash = trim((string) ($_SESSION['userPortalUi']['flash'] ?? ''));
        unset($_SESSION['userPortalUi']['flash']);

        return $flash;
    }

    protected function resolveUserPortalMessageId(): int
    {
        if (!empty($this->input['callback']) && !empty($this->input['message_id'])) {
            return (int) $this->input['message_id'];
        }

        return (int) ($this->getUserPortalUiMessageId() ?? 0);
    }

    protected function userPortalShow(string $text, $buttons = false, $replyPlaceholder = false, ?array $replyState = null): void
    {
        $chat = $this->input['chat'];
        $messageId = $this->resolveUserPortalMessageId();

        if ($replyState !== null && $messageId > 0) {
            $_SESSION['reply'][$messageId] = array_merge($replyState, [
                'start_message'  => $messageId,
                'start_callback' => $this->input['callback_id'] ?? false,
                'keep_message' => true,
            ]);
        }

        if ($messageId > 0) {
            $r = $this->update($chat, $messageId, $text, $buttons ?: false, $replyPlaceholder !== false ? $replyPlaceholder : false);
            if (!empty($r['ok'])) {
                $this->setUserPortalUiMessageId($messageId);

                return;
            }
            unset($_SESSION['userPortalUi']['message_id'], $_SESSION['reply'][$messageId]);
        }

        $r = $this->send($chat, $text, false, $buttons ?: false, $replyPlaceholder !== false ? $replyPlaceholder : false);
        if (!empty($r['result']['message_id'])) {
            $newId = (int) $r['result']['message_id'];
            $this->setUserPortalUiMessageId($newId);
            if ($replyState !== null) {
                $_SESSION['reply'][$newId] = array_merge($replyState, [
                    'start_message'  => $newId,
                    'start_callback' => $this->input['callback_id'] ?? false,
                    'keep_message' => true,
                ]);
            }
        }
    }

    protected function userPortalPromptInput(string $text, string $callback, array $args = [], $buttons = false): void
    {
        $data = is_array($buttons) ? $buttons : [];
        $data[] = [[
            'text'          => $this->i18n('back'),
            'callback_data' => '/userPortal',
        ]];
        $placeholder = match ($callback) {
            'userPortalImportLink'            => $this->i18n('user portal send old link'),
            'userPortalDeleteDevicePassword'  => $this->i18n('user portal enter delete password'),
            'userPortalSavePassword'          => $this->i18n('user portal enter new password'),
            'userPortalChangePasswordVerify'  => $this->i18n('user portal enter current password'),
            'userPortalRenameDeviceSave'      => $this->i18n('user portal enter device name'),
            default                           => '',
        };
        $this->userPortalShow($text, $data, $placeholder, [
            'callback' => $callback,
            'args'     => $args,
        ]);
    }

    protected function userPortalDeleteUserMessage(): void
    {
        $messageId = (int) ($this->input['message_id'] ?? 0);
        if ($messageId > 0 && empty($this->input['callback'])) {
            $this->delete($this->input['chat'], $messageId);
        }
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
                if (empty($reply['keep_message'])) {
                    $this->delete($this->input['chat'], $messageId);
                }
                unset($_SESSION['reply'][$messageId]);
            }
        }
        if (empty($_SESSION['reply'])) {
            unset($_SESSION['reply']);
        }
    }

    protected function buildUserPortalAccountInfoLines(array $session): array
    {
        $client = $session['client'];
        $pac = $this->getPacConf();
        $st = $this->getXrayStats();
        $lines = [];
        $email = (string) ($client['email'] ?? '');
        if ($email !== '') {
            $lines[] = $this->i18n('user portal account') . ': <code>' . htmlspecialchars($email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>';
        }

        $time = (int) ($client['time'] ?? 0);
        if ($time > 0) {
            $date = date('d.m.Y H:i', $time);
            if ($time > time()) {
                $lines[] = $this->i18n('user portal expires') . ': ' . $date . ' (' . $this->getTime($time) . ')';
            } else {
                $lines[] = $this->i18n('user portal expired') . ': ' . $date;
            }
        } else {
            $lines[] = $this->i18n('user portal expires') . ': ' . $this->i18n('user portal no expiry');
        }

        $totals = $this->getSubscriptionXrayTrafficTotals($st, $client, $session['clientIndex']);
        $trafficLine = $this->i18n('user portal traffic') . ': ' . $this->formatTrafficUpDown((int) $totals['download'], (int) $totals['upload']);
        $limit = $this->getClientTrafficLimitBytes($client, $pac);
        if ($limit > 0) {
            $trafficLine .= ' / ' . $this->getBytes($limit);
        }
        $lines[] = $trafficLine;

        $ownerSubId = $session['subscription_id'];
        $deviceCount = count($this->getHwidDevicesByUser($ownerSubId));
        $lines[] = $this->i18n('hwid devices') . ': ' . $deviceCount;
        $lines[] = $this->i18n('user portal delete password') . ': ' . $this->i18n(
            $this->hasSubscriptionDevicePassword($client) ? 'user portal password set' : 'user portal password not set'
        );

        return $lines;
    }

    protected function shouldHandleUserPortalTextInput(): bool
    {
        if (!empty($this->input['callback'])) {
            return false;
        }
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
        if (!empty($this->input['callback'])) {
            return;
        }
        $message = trim((string) ($this->input['message'] ?? ''));
        $pending = $this->getPendingUserPortalReply();
        if ($pending !== null) {
            $callback = (string) ($pending['callback'] ?? '');
            $args = $pending['args'] ?? [];
            $uiId = (int) ($pending['message_id'] ?? $pending['start_message'] ?? 0);
            if ($uiId > 0) {
                $this->input['message_id'] = $uiId;
            }
            $this->clearPendingUserPortalReply($callback);
            $this->userPortalDeleteUserMessage();
            if ($callback === 'userPortalImportLink') {
                $this->userPortalImportLink($message);

                return;
            }
            if ($callback === 'userPortalDeleteDevicePassword') {
                $this->userPortalDeleteDevicePassword($message, ...$args);

                return;
            }
            if ($callback === 'userPortalSavePassword') {
                $this->userPortalSavePassword($message, ...$args);

                return;
            }
            if ($callback === 'userPortalChangePasswordVerify') {
                $this->userPortalChangePasswordVerify($message);

                return;
            }
            if ($callback === 'userPortalRenameDeviceSave') {
                $this->userPortalRenameDeviceSave($message, ...$args);

                return;
            }
        }
        if ($this->parseSubscriptionLink($message) !== null) {
            $this->userPortalDeleteUserMessage();
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
            'subId'       => $resolved['subscription_id'],
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
            'client'          => $resolved['client'],
            'clientIndex'     => $resolved['index'],
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
                'index'           => $k,
                'client'          => $v,
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
            'page'  => $this->buildSubscriptionPageUrl($scheme, $domain, $hash, $subscriptionId),
            'clash' => $this->buildPacUrl($scheme, $domain, $hash, [
                'h' => $hash,
                't' => 'cl',
                's' => $subscriptionId,
            ]),
        ];
    }

    protected function saveUserPortalDevicePassword(string $password, bool $change = false): array
    {
        $session = $this->getUserPortalSession();
        if ($session === null) {
            return ['ok' => false, 'message' => $this->i18n('user portal bind first')];
        }
        $password = trim($password);
        if ($password === '') {
            return ['ok' => false, 'message' => $this->i18n('user portal empty password')];
        }

        $ownerSubId = $session['subscription_id'];
        if (!$this->checkSubscriptionActionRateLimit($ownerSubId, 'device_password_set', 5, 600)) {
            return ['ok' => false, 'message' => $this->i18n('user portal rate limit')];
        }

        $xr = $this->getXray();
        $idx = (int) $session['clientIndex'];
        if (!isset($xr['inbounds'][0]['settings']['clients'][$idx])) {
            return ['ok' => false, 'message' => $this->i18n('user portal link not found')];
        }

        $clientRef = &$xr['inbounds'][0]['settings']['clients'][$idx];
        if ($change) {
            if (empty($_SESSION['userPortal']['pw_change_ok'])) {
                return ['ok' => false, 'message' => $this->i18n('user portal password verify first')];
            }
            unset($_SESSION['userPortal']['pw_change_ok']);
        } elseif ($this->hasSubscriptionDevicePassword($clientRef)) {
            return ['ok' => false, 'message' => $this->i18n('user portal password already set')];
        }

        $this->setSubscriptionDevicePassword($clientRef, $password);
        $this->writeXrayConfig($xr);

        return ['ok' => true];
    }

    public function userPortalMenu()
    {
        if (preg_match('~^/(?:start|menu)$~', (string) ($this->input['message'] ?? ''))) {
            unset($_SESSION['userPortalUi']['message_id']);
        }

        $session = $this->getUserPortalSession();
        $text = [$this->i18n('user portal title')];
        $flash = $this->userPortalTakeFlash();
        if ($flash !== '') {
            $text[] = $flash;
            $text[] = '';
        }
        if ($session !== null) {
            $text = array_merge($text, $this->buildUserPortalAccountInfoLines($session));
        } else {
            $text[] = $this->i18n('user portal hint');
        }

        $data = [
            [
                [
                    'text'          => $this->i18n('user portal restore link'),
                    'callback_data' => '/userPortalImport',
                ],
                [
                    'text'          => $this->i18n('user portal my devices'),
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

            $data[] = [[
                'text'          => $this->hasSubscriptionDevicePassword($session['client'])
                    ? $this->i18n('user portal change password')
                    : $this->i18n('user portal set password'),
                'callback_data' => '/userPortalPassword',
            ]];
        }

        $this->userPortalShow(implode("\n", $text), $data);
    }

    public function userPortalImport()
    {
        $this->userPortalPromptInput($this->i18n('user portal send old link'), 'userPortalImportLink', []);
    }

    public function userPortalImportLink($text = '')
    {
        $text = trim((string) $text);
        if ($text === '') {
            $this->userPortalSetFlash('⚠️ ' . $this->i18n('user portal link not found'));
            $this->userPortalMenu();

            return;
        }
        $telegramId = (string) ($this->input['from'] ?? '');
        if (!$this->checkSubscriptionActionRateLimit('portal:' . $telegramId, 'import_link', 8, 600)) {
            $this->userPortalSetFlash('⚠️ ' . $this->i18n('user portal rate limit'));
            $this->userPortalMenu();

            return;
        }

        $subscriptionId = $this->parseSubscriptionLink($text);
        if ($subscriptionId === null || $this->resolveSubscriptionClient($subscriptionId) === null) {
            $this->userPortalSetFlash('⚠️ ' . $this->i18n('user portal link not found'));
            $this->userPortalMenu();

            return;
        }

        $this->bindUserPortalSession($subscriptionId);
        $session = $this->getUserPortalSession();
        if ($session === null) {
            $this->userPortalSetFlash('⚠️ ' . $this->i18n('user portal link not found'));
            $this->userPortalMenu();

            return;
        }

        $this->userPortalSetFlash('✅ ' . $this->i18n('user portal link restored'));
        $this->userPortalMenu();
    }

    public function userPortalPassword()
    {
        $session = $this->getUserPortalSession();
        if ($session === null) {
            $this->ackCallback($this->i18n('user portal bind first'), true);
            $this->userPortalImport();

            return;
        }
        if ($this->hasSubscriptionDevicePassword($session['client'])) {
            $this->userPortalChangePassword();

            return;
        }
        $this->userPortalPromptInput($this->i18n('user portal enter new password'), 'userPortalSavePassword', []);
    }

    public function userPortalChangePassword()
    {
        $session = $this->getUserPortalSession();
        if ($session === null) {
            $this->ackCallback($this->i18n('user portal bind first'), true);
            $this->userPortalMenu();

            return;
        }
        if (!$this->hasSubscriptionDevicePassword($session['client'])) {
            $this->userPortalPassword();

            return;
        }
        unset($_SESSION['userPortal']['pw_change_ok']);
        $this->userPortalPromptInput($this->i18n('user portal enter current password'), 'userPortalChangePasswordVerify', []);
    }

    public function userPortalChangePasswordVerify($password)
    {
        $session = $this->getUserPortalSession();
        if ($session === null) {
            $this->userPortalSetFlash('⚠️ ' . $this->i18n('user portal bind first'));
            $this->userPortalMenu();

            return;
        }

        $xr = $this->getXray();
        $idx = (int) $session['clientIndex'];
        if (!isset($xr['inbounds'][0]['settings']['clients'][$idx])) {
            $this->userPortalSetFlash('⚠️ ' . $this->i18n('user portal link not found'));
            $this->userPortalMenu();

            return;
        }

        $clientRef = &$xr['inbounds'][0]['settings']['clients'][$idx];
        if (!$this->isSubscriptionDevicePasswordValid($clientRef, trim((string) $password))) {
            $this->userPortalSetFlash('⚠️ ' . $this->i18n('user portal invalid password'));
            $this->userPortalChangePassword();

            return;
        }

        $_SESSION['userPortal']['pw_change_ok'] = 1;
        $this->userPortalPromptInput($this->i18n('user portal enter new password'), 'userPortalSavePassword', ['change' => 1]);
    }

    public function userPortalSavePassword($password, $change = 0)
    {
        $result = $this->saveUserPortalDevicePassword((string) $password, !empty($change));
        if (empty($result['ok'])) {
            $this->userPortalSetFlash('⚠️ ' . (string) ($result['message'] ?? 'error'));
            if (!empty($change)) {
                $this->userPortalChangePassword();
            } else {
                $this->userPortalPassword();
            }

            return;
        }

        $this->userPortalSetFlash('✅ ' . $this->i18n('user portal password saved'));
        $this->userPortalMenu();
    }

    public function userPortalDevices($page = 0)
    {
        $session = $this->getUserPortalSession();
        if ($session === null) {
            $this->ackCallback($this->i18n('user portal bind first'), true);
            $this->userPortalPromptInput(
                $this->i18n('user portal bind first') . "\n\n" . $this->i18n('user portal send old link'),
                'userPortalImportLink',
                [],
            );

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

        $text = [$this->i18n('user portal my devices')];
        $flash = $this->userPortalTakeFlash();
        if ($flash !== '') {
            $text[] = $flash;
        }
        $text = array_merge($text, $this->buildUserPortalAccountInfoLines($session));
        $text[] = '';

        $data = [];
        if (!$this->hasSubscriptionDevicePassword($client)) {
            $text[] = '';
            $text[] = $this->i18n('user portal set password to delete');
            $data[] = [[
                'text'          => $this->i18n('user portal set password'),
                'callback_data' => '/userPortalPassword',
            ]];
        }

        if ($total === 0) {
            $text[] = $this->i18n('no devices');
        } else {
            $deviceTraffic = $this->getHwidDeviceTraffic($ownerSubId);
            foreach ($hwidsPage as $index => $hwid) {
                $info = $devices[$hwid];
                $number = $page * $perPage + $index + 1;
                $customName = trim((string) ($info['device_name'] ?? ''));
                $details = array_filter([
                    $info['device_os'] ?? '',
                    $info['os_version'] ?? '',
                    $info['device_model'] ?? '',
                ], fn($v) => $v !== '');
                $text[] = str_repeat('-', 40);
                if ($customName !== '') {
                    $text[] = $number . '. <b>' . htmlspecialchars($customName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</b>';
                    $text[] = '<code>' . htmlspecialchars($hwid, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>';
                } else {
                    $text[] = $number . '. <code>' . htmlspecialchars($hwid, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>';
                }
                if ($details !== []) {
                    $text[] = htmlspecialchars(implode(' ', $details), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                }
                if (!empty($info['time'])) {
                    $text[] = date('d.m.Y H:i', (int) $info['time']);
                }
                $devDown = (int) ($deviceTraffic[$hwid]['download'] ?? 0);
                $devUp = (int) ($deviceTraffic[$hwid]['upload'] ?? 0);
                $text[] = $this->formatTrafficDisplayLine($devDown, $devUp);
                $token = $this->rememberHwidToken($scope, $hwid);
                $row = [[
                    'text'          => $this->i18n('rename') . ' ' . $number,
                    'callback_data' => "/userPortalRename {$page}_{$token}",
                ]];
                if ($this->hasSubscriptionDevicePassword($client)) {
                    $row[] = [
                        'text'          => $this->i18n('delete') . ' ' . $number,
                        'callback_data' => "/userPortalDel {$page}_{$token}",
                    ];
                }
                $data[] = $row;
            }
        }

        if ($this->hasSubscriptionDevicePassword($client)) {
            $data[] = [[
                'text'          => $this->i18n('user portal change password'),
                'callback_data' => '/userPortalPassword',
            ]];
        }

        if ($pages > 1) {
            $data[] = [
                [
                    'text'          => '<<',
                    'callback_data' => '/userPortalDevices_' . ($page - 1 >= 0 ? $page - 1 : $pages - 1),
                ],
                [
                    'text'          => ($page + 1) . '/' . $pages,
                    'callback_data' => "/userPortalDevices_{$page}",
                ],
                [
                    'text'          => '>>',
                    'callback_data' => '/userPortalDevices_' . (($page + 1) % $pages),
                ],
            ];
        }

        $data[] = [[
            'text'          => $this->i18n('back'),
            'callback_data' => '/userPortal',
        ]];

        $this->userPortalShow(implode("\n", $text), $data ?: false);
    }

    public function userPortalRename($pageToken, $token)
    {
        $session = $this->getUserPortalSession();
        if ($session === null) {
            $this->ackCallback($this->i18n('user portal bind first'), true);
            $this->userPortalMenu();

            return;
        }

        $scope = $this->getUserPortalTokenScope();
        $hwid = $this->resolveHwidToken($scope, $token);
        if ($hwid === '') {
            $this->ackCallback('device not found', true);
            $this->userPortalDevices((int) explode('_', (string) $pageToken)[0]);

            return;
        }

        $devices = $this->getHwidDevicesByUser($session['subscription_id']);
        $info = $devices[$hwid] ?? [];
        $currentName = trim((string) ($info['device_name'] ?? ''));
        $prompt = $this->i18n('user portal enter device name');
        if ($currentName !== '') {
            $prompt .= "\n\n" . $this->i18n('user portal current device name') . ': <b>' . htmlspecialchars($currentName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</b>';
        }
        $prompt .= "\n\n<code>" . htmlspecialchars($hwid, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>';

        $this->userPortalPromptInput(
            $prompt,
            'userPortalRenameDeviceSave',
            [$pageToken, $token],
            [[
                'text'          => $this->i18n('back'),
                'callback_data' => '/userPortalDevices_' . (int) explode('_', (string) $pageToken)[0],
            ]],
        );
    }

    public function userPortalRenameDeviceSave($name, $pageToken, $token)
    {
        $session = $this->getUserPortalSession();
        if ($session === null) {
            $this->userPortalSetFlash('⚠️ ' . $this->i18n('user portal bind first'));
            $this->userPortalMenu();

            return;
        }

        $scope = $this->getUserPortalTokenScope();
        $hwid = $this->resolveHwidToken($scope, $token);
        if ($hwid === '') {
            $this->userPortalSetFlash('⚠️ device not found');
            $this->userPortalDevices((int) explode('_', (string) $pageToken)[0]);

            return;
        }

        $ownerSubId = $session['subscription_id'];
        if (!$this->checkSubscriptionActionRateLimit($ownerSubId, 'device_rename', 30, 600)) {
            $this->userPortalSetFlash('⚠️ ' . $this->i18n('user portal rate limit'));
            $this->userPortalDevices((int) explode('_', (string) $pageToken)[0]);

            return;
        }

        $result = $this->renameHwidDevice($ownerSubId, $hwid, (string) $name);
        if (empty($result['ok'])) {
            $message = (string) ($result['message'] ?? 'error');
            if ($message === 'empty name') {
                $message = $this->i18n('user portal empty device name');
            }
            $this->userPortalSetFlash('⚠️ ' . $message);
            $this->userPortalRename($pageToken, $token);

            return;
        }

        $savedName = (string) ($result['device_name'] ?? '');
        $this->userPortalSetFlash('✅ ' . $this->i18n('user portal device renamed') . ': ' . $savedName);
        $this->userPortalDevices((int) explode('_', (string) $pageToken)[0]);
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
            $this->ackCallback($this->i18n('user portal set password to delete'), true);
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

        $this->userPortalPromptInput(
            $this->i18n('user portal enter delete password') . "\n\n<code>" . htmlspecialchars($hwid, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>',
            'userPortalDeleteDevicePassword',
            [$pageToken, $token],
            [[
                'text'          => $this->i18n('back'),
                'callback_data' => '/userPortalDevices_' . (int) explode('_', (string) $pageToken)[0],
            ]],
        );
    }

    public function userPortalDeleteDevicePassword($password, $pageToken, $token)
    {
        $session = $this->getUserPortalSession();
        if ($session === null) {
            $this->userPortalSetFlash('⚠️ ' . $this->i18n('user portal bind first'));
            $this->userPortalMenu();

            return;
        }

        $scope = $this->getUserPortalTokenScope();
        $hwid = $this->resolveHwidToken($scope, $token);
        if ($hwid === '') {
            $this->userPortalSetFlash('⚠️ device not found');
            $this->userPortalDevices((int) explode('_', (string) $pageToken)[0]);

            return;
        }

        $ownerSubId = $session['subscription_id'];
        if (!$this->checkSubscriptionActionRateLimit($ownerSubId, 'device_delete', 10, 600)) {
            $this->userPortalSetFlash('⚠️ ' . $this->i18n('user portal rate limit'));
            $this->userPortalDevices((int) explode('_', (string) $pageToken)[0]);

            return;
        }

        $result = $this->performSubscriptionDeviceDelete($ownerSubId, $hwid, trim((string) $password));
        if (empty($result['ok'])) {
            $message = (string) ($result['message'] ?? 'error');
            if ($message === 'invalid password') {
                $message = $this->i18n('user portal invalid password');
            }
            $this->userPortalSetFlash('⚠️ ' . $message);
            $this->userPortalDevices((int) explode('_', (string) $pageToken)[0]);

            return;
        }

        $this->userPortalSetFlash('✅ ' . $this->i18n('user portal device deleted'));
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
