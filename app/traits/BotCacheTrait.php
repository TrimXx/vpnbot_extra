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
