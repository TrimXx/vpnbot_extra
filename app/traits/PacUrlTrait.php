<?php

trait PacUrlTrait
{
    protected function encodePacUrlPayload(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return '';
        }
        $sig = hash_hmac('sha256', $json, $this->key);
        $wrapped = json_encode([
            'v' => 2,
            'd' => $payload,
            's' => $sig,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return rtrim(strtr(base64_encode((string) $wrapped), '+/', '-_'), '=');
    }

    public function decodePacUrlPayload(string $token): array
    {
        $token = trim($token);
        if ($token === '') {
            return [];
        }
        $raw = base64_decode(strtr($token, '-_', '+/'), true);
        if ($raw === false) {
            $raw = base64_decode($token, true);
        }
        if ($raw === false || $raw === '') {
            return [];
        }

        $wrapper = json_decode($raw, true);
        if (is_array($wrapper) && (int) ($wrapper['v'] ?? 0) === 2 && isset($wrapper['d'], $wrapper['s']) && is_array($wrapper['d'])) {
            $json = json_encode($wrapper['d'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json !== false && hash_equals(hash_hmac('sha256', $json, $this->key), (string) $wrapper['s'])) {
                return $wrapper['d'];
            }

            return [];
        }

        if (is_array($wrapper)) {
            return $wrapper;
        }

        $legacy = @unserialize($raw);

        return is_array($legacy) ? $legacy : [];
    }

    protected function buildPacUrl(string $scheme, string $domain, string $hash, array $params): string
    {
        return "{$scheme}://{$domain}/pac{$hash}/" . $this->encodePacUrlPayload($params);
    }
}
