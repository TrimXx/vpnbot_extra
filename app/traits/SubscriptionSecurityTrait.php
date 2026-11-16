<?php

trait SubscriptionSecurityTrait
{
    protected function hasSubscriptionDevicePassword(array $client): bool
    {
        return !empty($client['device_delete_password_hash'])
            || !empty($client['device_delete_password_md5']);
    }

    protected function getSubscriptionDevicePasswordHash(array $client): string
    {
        $modern = (string) ($client['device_delete_password_hash'] ?? '');
        if ($modern !== '') {
            return $modern;
        }

        return (string) ($client['device_delete_password_md5'] ?? '');
    }

    protected function isSubscriptionDevicePasswordValid(array $client, string $password): bool
    {
        if ($password === '') {
            return false;
        }
        $modern = (string) ($client['device_delete_password_hash'] ?? '');
        if ($modern !== '') {
            return password_verify($password, $modern);
        }
        $legacy = strtolower((string) ($client['device_delete_password_md5'] ?? ''));
        if ($legacy === '') {
            return false;
        }

        return hash_equals($legacy, strtolower(md5($password)));
    }

    protected function setSubscriptionDevicePassword(array &$client, string $password): void
    {
        $client['device_delete_password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        unset($client['device_delete_password_md5']);
    }

    protected function clearSubscriptionDevicePassword(array &$client): void
    {
        unset($client['device_delete_password_hash'], $client['device_delete_password_md5']);
    }

    protected function verifySubscriptionDevicePasswordWithMigration(array &$client, string $password): bool
    {
        if (!$this->isSubscriptionDevicePasswordValid($client, $password)) {
            return false;
        }
        if (empty($client['device_delete_password_hash']) && !empty($client['device_delete_password_md5'])) {
            $this->setSubscriptionDevicePassword($client, $password);
        }

        return true;
    }

    protected function createSubscriptionActionToken(string $subscriptionId, int $ttl = 3600): string
    {
        $ts = time();
        $sig = hash_hmac('sha256', $subscriptionId . '|' . $ts, $this->key);
        $payload = $subscriptionId . '|' . $ts . '|' . $sig;

        return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    }

    protected function verifySubscriptionActionToken(string $subscriptionId, string $token, int $ttl = 3600): bool
    {
        $token = trim($token);
        if ($token === '' || $subscriptionId === '') {
            return false;
        }
        $decoded = base64_decode(strtr($token, '-_', '+/'), true);
        if ($decoded === false) {
            return false;
        }
        $parts = explode('|', $decoded, 3);
        if (count($parts) !== 3) {
            return false;
        }
        [$subId, $ts, $sig] = $parts;
        if ($subId !== $subscriptionId) {
            return false;
        }
        $ts = (int) $ts;
        if ($ts <= 0 || $ts < time() - $ttl || $ts > time() + 60) {
            return false;
        }
        $expected = hash_hmac('sha256', $subId . '|' . $ts, $this->key);

        return hash_equals($expected, $sig);
    }

    protected function denySubscriptionAction(string $message = 'invalid action token', int $status = 403): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => $message]);
        exit;
    }

    protected function requireSubscriptionActionToken(string $subscriptionId): void
    {
        $token = trim((string) ($_POST['action_token'] ?? $_SERVER['HTTP_X_SUB_ACTION_TOKEN'] ?? ''));
        if (!$this->verifySubscriptionActionToken($subscriptionId, $token)) {
            $this->denySubscriptionAction('invalid or expired action token');
        }
    }

    protected function getSubscriptionRateLimitPath(): string
    {
        return '/config/sub_action_rl.json';
    }

    protected function updateSubscriptionRateLimit(callable $mutator): bool
    {
        $path = $this->getSubscriptionRateLimitPath();
        $fh = @fopen($path, 'c+');
        if ($fh === false) {
            return false;
        }
        try {
            if (!flock($fh, LOCK_EX)) {
                return false;
            }
            rewind($fh);
            $raw = stream_get_contents($fh);
            $data = json_decode(is_string($raw) ? $raw : '', true);
            if (!is_array($data)) {
                $data = [];
            }
            $mutator($data);
            $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                $encoded = '{}';
            }
            rewind($fh);
            if (!ftruncate($fh, 0) || fwrite($fh, $encoded) === false) {
                return false;
            }
            fflush($fh);

            return true;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    protected function checkSubscriptionActionRateLimit(string $subscriptionId, string $action, int $max = 10, int $window = 300): bool
    {
        $now = time();
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $bucketKey = $subscriptionId . '|' . $action . '|' . $ip;
        $allowed = true;
        $updated = $this->updateSubscriptionRateLimit(function (array &$data) use ($bucketKey, $now, $window, $max, &$allowed): void {
            $entries = array_values(array_filter(
                is_array($data[$bucketKey] ?? null) ? $data[$bucketKey] : [],
                static fn($ts) => (int) $ts > $now - $window
            ));
            if (count($entries) >= $max) {
                $allowed = false;
            } else {
                $entries[] = $now;
            }
            $data[$bucketKey] = $entries;
            foreach ($data as $key => $timestamps) {
                if (!is_array($timestamps)) {
                    unset($data[$key]);
                    continue;
                }
                $filtered = array_values(array_filter($timestamps, static fn($ts) => (int) $ts > $now - $window));
                if ($filtered === []) {
                    unset($data[$key]);
                } else {
                    $data[$key] = $filtered;
                }
            }
        });
        if (!$updated) {
            return true;
        }

        return $allowed;
    }

    protected function clearSubscriptionActionRateLimit(string $subscriptionId, string $action): int
    {
        $subscriptionId = trim($subscriptionId);
        $action = trim($action);
        if ($subscriptionId === '' || $action === '') {
            return 0;
        }
        $prefix = $subscriptionId . '|' . $action . '|';
        $removed = 0;
        $this->updateSubscriptionRateLimit(function (array &$data) use ($prefix, &$removed): void {
            foreach (array_keys($data) as $key) {
                if (!is_string($key) || strncmp($key, $prefix, strlen($prefix)) !== 0) {
                    continue;
                }
                unset($data[$key]);
                $removed++;
            }
        });

        return $removed;
    }

    protected function requireSubscriptionActionRateLimit(string $subscriptionId, string $action, int $max = 10, int $window = 300): void
    {
        if (!$this->checkSubscriptionActionRateLimit($subscriptionId, $action, $max, $window)) {
            $this->denySubscriptionAction('rate limit exceeded', 429);
        }
    }

    protected function getSubscriptionUrlEpoch(?array $pac = null): int
    {
        $pac = $pac ?? $this->getPacConf();

        return max(1, (int) ($pac['subscription_url_epoch'] ?? 1));
    }

    protected function createSubscriptionUrlSig(string $subscriptionId, ?int $epoch = null): string
    {
        $epoch = $epoch ?? $this->getSubscriptionUrlEpoch();
        $digest = hash_hmac('sha256', $subscriptionId . '|' . $epoch, $this->key, true);

        return rtrim(strtr(base64_encode($digest), '+/', '-_'), '=');
    }

    protected function verifySubscriptionUrlSig(string $subscriptionId, string $sig): bool
    {
        $sig = trim($sig);
        if ($sig === '' || $subscriptionId === '') {
            return false;
        }
        $epoch = $this->getSubscriptionUrlEpoch();
        if (hash_equals($this->createSubscriptionUrlSig($subscriptionId, $epoch), $sig)) {
            return true;
        }

        return $epoch > 1 && hash_equals($this->createSubscriptionUrlSig($subscriptionId, $epoch - 1), $sig);
    }

    protected function buildSubscriptionPageUrl(string $scheme, string $domain, string $hash, string $subscriptionId): string
    {
        $subscriptionId = trim($subscriptionId);
        $query = http_build_query([
            'id' => $subscriptionId,
            'sig' => $this->createSubscriptionUrlSig($subscriptionId),
        ]);

        return "{$scheme}://{$domain}/pac{$hash}/sub?{$query}";
    }

    protected function requireSubscriptionUrlAccess(string $subscriptionId): void
    {
        $pac = $this->getPacConf();
        $sig = trim((string) ($_GET['sig'] ?? ''));
        $signedRequired = !empty($pac['subscription_url_signed']);
        if ($sig === '' && !$signedRequired) {
            return;
        }
        if ($sig === '' || !$this->verifySubscriptionUrlSig($subscriptionId, $sig)) {
            http_response_code(403);
            header('Content-Type: text/html; charset=utf-8');
            echo '<html><body><h2>Subscription link invalid or expired</h2><p>Please open the bot and request a new subscription link.</p></body></html>';
            exit;
        }
    }

    protected function rotateSubscriptionUrlEpoch(): int
    {
        $pac = $this->getPacConf();
        $pac['subscription_url_epoch'] = $this->getSubscriptionUrlEpoch($pac) + 1;
        $this->setPacConf($pac);

        return (int) $pac['subscription_url_epoch'];
    }

    protected function performSubscriptionDeviceDelete(string $ownerSubId, string $hwid, string $password): array
    {
        $hwid = trim($hwid);
        if ($hwid === '') {
            return ['ok' => false, 'message' => 'empty hwid'];
        }

        $resolved = $this->resolveSubscriptionClient($ownerSubId);
        if ($resolved === null) {
            return ['ok' => false, 'message' => 'subscription not found'];
        }

        $client = $resolved['client'];
        if (!$this->isSubscriptionDevicePasswordValid($client, $password)) {
            return ['ok' => false, 'message' => 'invalid password'];
        }

        $devices = $this->listVisibleHwidDevices($ownerSubId);
        if (!isset($devices[$hwid]) || !is_array($devices[$hwid])) {
            return ['ok' => false, 'message' => 'device not found'];
        }
        $deviceUuid = (string) ($devices[$hwid]['device_uuid'] ?? '');
        $this->deleteHwidDevice($ownerSubId, $hwid);
        $this->deleteRuntimeDeviceClients($ownerSubId, $hwid, $deviceUuid);

        return ['ok' => true];
    }
}
