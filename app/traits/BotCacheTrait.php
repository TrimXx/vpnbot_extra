<?php

trait BotCacheTrait
{
    protected $pacConfCache = null;
    protected $xrayConfigCache = null;
    protected $xrayStatsCache = null;
    protected $dockerComposeServicesCache = null;
    protected $dockerComposeMtime = null;

    protected function invalidatePacConfCache(): void
    {
        $this->pacConfCache = null;
    }

    protected function invalidateXrayConfigCache(): void
    {
        $this->xrayConfigCache = null;
    }

    protected function invalidateXrayStatsCache(): void
    {
        $this->xrayStatsCache = null;
    }

    protected function invalidateDockerComposeCache(): void
    {
        $this->dockerComposeServicesCache = null;
        $this->dockerComposeMtime = null;
    }

    protected function isComposePortPublished(string $service): bool
    {
        $services = $this->getDockerComposeServices();
        if (empty($services[$service]) || !is_array($services[$service])) {
            return false;
        }
        $ports = $services[$service]['ports'] ?? [];

        return is_array($ports) && $ports !== [];
    }

    protected function releaseSessionLock(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    protected function resumeSessionLock(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE && !empty($this->input['from'])) {
            session_id((string) $this->input['from']);
            session_start();
        }
    }

    protected function telegramRequestOk(?array $response): bool
    {
        if (!empty($response['ok'])) {
            return true;
        }
        $description = (string) ($response['description'] ?? '');

        return str_contains($description, 'message is not modified');
    }

    protected function replyMenu($chat, int $messageId, string $text, $buttons = false, $reply = false): void
    {
        $markup = $buttons ?: false;
        if (!empty($this->input['callback_id']) && $messageId > 0) {
            $r = $this->update($chat, $messageId, $text, $markup, $reply !== false ? $reply : false);
            if ($this->telegramRequestOk($r)) {
                return;
            }
            if ($markup) {
                $rk = $this->request('editMessageReplyMarkup', [
                    'chat_id'      => $chat,
                    'message_id'   => $messageId,
                    'reply_markup' => json_encode(['inline_keyboard' => $markup]),
                ]);
                if ($this->telegramRequestOk($rk)) {
                    return;
                }
            }

            return;
        }
        $this->send($chat, $text, $messageId, $markup, $reply !== false ? $reply : false);
    }

    protected function ackCallback(?string $text = null, bool $showAlert = false): void
    {
        if (empty($this->input['callback_id'])) {
            return;
        }
        $this->answer($this->input['callback_id'], $text ?? '', $showAlert);
    }

    protected function getDockerComposeServices(): array
    {
        $path = '/docker/compose';
        $mtime = (int) (@filemtime($path) ?: 0);
        if (
            is_array($this->dockerComposeServicesCache)
            && $this->dockerComposeMtime === $mtime
        ) {
            return $this->dockerComposeServicesCache;
        }
        $parsed = @yaml_parse_file($path);
        $services = is_array($parsed) ? ($parsed['services'] ?? []) : [];
        if (!is_array($services)) {
            $services = [];
        }
        $this->dockerComposeServicesCache = $services;
        $this->dockerComposeMtime = $mtime;

        return $services;
    }

    protected function getCertificateMenuSnapshot(): array
    {
        $certPath = '/certs/cert_public';
        $mtime = (int) (@filemtime($certPath) ?: 0);
        $cacheFile = '/tmp/vpnbot_cert_menu.json';
        if ($mtime > 0 && is_readable($cacheFile)) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached) && (int) ($cached['mtime'] ?? 0) === $mtime) {
                return $cached;
            }
        }
        $snapshot = [
            'mtime' => $mtime,
            'expiry' => false,
            'domains' => [],
        ];
        if ($mtime > 0 && is_readable($certPath)) {
            $raw = file_get_contents($certPath);
            $cert = $raw !== false ? @openssl_x509_read($raw) : false;
            if ($cert !== false) {
                $parsed = openssl_x509_parse($cert) ?: [];
                $snapshot['expiry'] = $parsed['validTo_time_t'] ?? false;
                $san = $parsed['extensions']['subjectAltName'] ?? '';
                if ($san !== '') {
                    $snapshot['domains'] = array_map(
                        static fn($e) => trim($e),
                        explode(',', str_replace('DNS:', '', (string) $san))
                    );
                }
            }
        }
        @file_put_contents($cacheFile, json_encode($snapshot));

        return $snapshot;
    }
}
