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

    protected function denySubscriptionAction(string $message = 'invalid action token'): void
    {
        http_response_code(403);
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
}
