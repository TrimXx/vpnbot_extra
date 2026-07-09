<?php

trait LoggingTrait
{
    protected function applyXrayLogConfig(array &$c): void
    {
        $c['log'] = $this->buildXrayLogConfig();
        $this->applyXrayRealityDebugConfig($c);
    }

    protected function applyXrayRealityDebugConfig(array &$c): void
    {
        $show = $this->getLogLevel('xray_reality_show') === 'on';
        foreach (array_keys($c['inbounds'] ?? []) as $idx) {
            if (!is_array($c['inbounds'][$idx] ?? null)) {
                continue;
            }
            if (($c['inbounds'][$idx]['streamSettings']['security'] ?? '') !== 'reality') {
                continue;
            }
            if (!isset($c['inbounds'][$idx]['streamSettings']['realitySettings'])
                || !is_array($c['inbounds'][$idx]['streamSettings']['realitySettings'])) {
                $c['inbounds'][$idx]['streamSettings']['realitySettings'] = [];
            }
            $c['inbounds'][$idx]['streamSettings']['realitySettings']['show'] = $show;
        }
    }

    protected function usesClassicXrayApiInbound(?array $pac = null): bool
    {
        return $this->getLogLevel('xray_api_inbound', $pac) === 'on';
    }

    protected function applyXrayApiRuntimeConfig(array &$c): void
    {
        $c['inbounds'] = array_values(array_filter(
            $c['inbounds'] ?? [],
            static fn(array $inbound): bool => ($inbound['tag'] ?? '') !== 'api'
                && ($inbound['protocol'] ?? '') !== 'dokodemo-door'
        ));

        if (!isset($c['routing']) || !is_array($c['routing'])) {
            $c['routing'] = [];
        }
        if (!isset($c['routing']['rules']) || !is_array($c['routing']['rules'])) {
            $c['routing']['rules'] = [];
        }
        $c['routing']['rules'] = array_values(array_filter(
            $c['routing']['rules'],
            static fn(array $rule): bool => ($rule['outboundTag'] ?? '') !== 'api'
        ));

        if ($this->usesClassicXrayApiInbound()) {
            $c['api'] = [
                'tag'      => 'api',
                'services' => ['StatsService'],
            ];
            $c['inbounds'][] = [
                'listen'   => '127.0.0.1',
                'port'     => 8080,
                'protocol' => 'dokodemo-door',
                'settings' => ['address' => '127.0.0.1'],
                'tag'      => 'api',
            ];
            $c['routing']['rules'][] = [
                'type'        => 'field',
                'inboundTag'  => ['api'],
                'outboundTag' => 'api',
            ];
        } else {
            $c['api'] = [
                'tag'      => 'api',
                'listen'   => '127.0.0.1:8080',
                'services' => ['StatsService'],
            ];
        }

        if (!isset($c['policy']) || !is_array($c['policy'])) {
            $c['policy'] = [];
        }
        $c['policy']['system'] = [
            'statsInboundUplink'    => true,
            'statsInboundDownlink'  => true,
            'statsOutboundUplink'   => true,
            'statsOutboundDownlink' => true,
        ];
    }

    protected function getXrayStartCommand(): string
    {
        if ($this->getLogLevel('xray_reality_show') === 'on') {
            return 'xray run -config /xray.json >> /logs/xray_reality.log 2>&1 &';
        }

        return 'xray run -config /xray.json > /dev/null 2>&1 &';
    }

    protected function getLoggingServiceDefinitions(): array
    {
        return [
            'xray' => [
                'label' => 'Xray error',
                'levels' => ['none', 'error', 'warning', 'info', 'debug'],
                'default' => 'error',
            ],
            'xray_access' => [
                'label' => 'Xray access',
                'levels' => ['off', 'on'],
                'default' => 'off',
            ],
            'xray_api_inbound' => [
                'label' => 'Xray API inbound',
                'levels' => ['off', 'on'],
                'default' => 'off',
            ],
            'xray_reality_show' => [
                'label' => 'Xray REALITY debug',
                'levels' => ['off', 'on'],
                'default' => 'off',
            ],
            'nginx' => [
                'label' => 'Nginx error',
                'levels' => ['crit', 'error', 'warn', 'notice', 'info', 'debug'],
                'default' => 'error',
            ],
            'nginx_upstream' => [
                'label' => 'Upstream error',
                'levels' => ['crit', 'error', 'warn', 'notice', 'info', 'debug'],
                'default' => 'error',
            ],
            'nginx_upstream_access' => [
                'label' => 'Upstream access',
                'levels' => ['off', 'on'],
                'default' => 'off',
            ],
            'php' => [
                'label' => 'PHP errors',
                'levels' => ['error', 'warning', 'debug'],
                'default' => 'error',
            ],
            'php_webhook' => [
                'label' => 'PHP webhook',
                'levels' => ['off', 'on'],
                'default' => 'off',
            ],
            'php_requests' => [
                'label' => 'PHP requests',
                'levels' => ['off', 'on'],
                'default' => 'off',
            ],
            'adguard' => [
                'label' => 'AdGuard verbose',
                'levels' => ['off', 'on'],
                'default' => 'off',
            ],
            'clash' => [
                'label' => 'Clash subscription',
                'levels' => ['silent', 'error', 'warning', 'info', 'debug'],
                'default' => 'info',
            ],
        ];
    }

    protected function getDefaultLogLevels(): array
    {
        $defaults = [];
        foreach ($this->getLoggingServiceDefinitions() as $key => $meta) {
            $defaults[$key] = (string) ($meta['default'] ?? 'off');
        }

        return $defaults;
    }

    protected function normalizeLogLevel(string $service, string $value): string
    {
        $defs = $this->getLoggingServiceDefinitions();
        if (!isset($defs[$service])) {
            return 'off';
        }
        $value = strtolower(trim($value));
        $allowed = $defs[$service]['levels'] ?? [];

        return in_array($value, $allowed, true) ? $value : (string) ($defs[$service]['default'] ?? 'off');
    }

    protected function getLogLevels(?array $pac = null): array
    {
        $pac = $pac ?? $this->getPacConf();
        $merged = array_replace($this->getDefaultLogLevels(), is_array($pac['log_levels'] ?? null) ? $pac['log_levels'] : []);
        foreach ($merged as $service => $value) {
            $merged[$service] = $this->normalizeLogLevel((string) $service, (string) $value);
        }

        return $merged;
    }

    protected function getLogLevel(string $service, ?array $pac = null): string
    {
        $levels = $this->getLogLevels($pac);

        return (string) ($levels[$service] ?? $this->getDefaultLogLevels()[$service] ?? 'off');
    }

    protected function isPhpWebhookLoggingEnabled(?array $pac = null): bool
    {
        return $this->getLogLevel('php_webhook', $pac) === 'on';
    }

    protected function isPhpRequestLoggingEnabled(?array $pac = null): bool
    {
        if (!empty(($pac ?? $this->getPacConf())['debug'])) {
            return true;
        }

        return $this->getLogLevel('php_requests', $pac) === 'on';
    }

    protected function buildXrayLogConfig(?array $pac = null): array
    {
        $access = $this->getLogLevel('xray_access', $pac);

        return [
            'access'   => $access === 'on' ? '/logs/xray_access' : 'none',
            'error'    => '/logs/xray_error',
            'loglevel' => $this->getLogLevel('xray', $pac),
        ];
    }

    protected function patchNginxErrorLogDirective(string $content, string $path, string $level): string
    {
        $replacement = "error_log $path $level;";
        $updated = preg_replace('~error_log\s+' . preg_quote($path, '~') . '\s+\w+\s*;~', $replacement, $content, 1, $count);

        return ($count ?? 0) > 0 ? (string) $updated : $content;
    }

    protected function patchNginxUpstreamAccessLog(string $content, string $enabled): string
    {
        $replacement = $enabled === 'on'
            ? "access_log /logs/upstream_access basic;"
            : 'access_log off;';

        return preg_replace('~access_log\s+[^;]+;~', $replacement, $content, 1) ?? $content;
    }

    protected function applyUpstreamLogging(bool $reload = true): void
    {
        $path = '/config/upstream.conf';
        if (!is_readable($path)) {
            return;
        }
        $levels = $this->getLogLevels();
        $nginx = (string) file_get_contents($path);
        $nginx = $this->patchNginxErrorLogDirective($nginx, '/logs/upstream_error', $levels['nginx_upstream']);
        $nginx = $this->patchNginxUpstreamAccessLog($nginx, $levels['nginx_upstream_access']);
        if ($nginx !== (string) file_get_contents($path)) {
            file_put_contents($path, $nginx);
            if ($reload) {
                $this->ssh('nginx -s reload 2>&1', 'up');
            }
        }
    }

    protected function applyNginxTemplateLogging(string $template): string
    {
        return $this->patchNginxErrorLogDirective($template, '/logs/nginx_error', $this->getLogLevel('nginx'));
    }

    protected function applyAdguardLogging(): void
    {
        $pac = $this->getPacConf();
        if (empty($pac['ad'])) {
            return;
        }
        if (!is_readable($this->adguard)) {
            return;
        }
        $config = yaml_parse_file($this->adguard);
        if (!is_array($config)) {
            return;
        }
        if (!isset($config['log']) || !is_array($config['log'])) {
            $config['log'] = [];
        }
        $verbose = $this->getLogLevel('adguard') === 'on';
        if (!empty($config['log']['verbose']) === $verbose) {
            return;
        }
        $config['log']['verbose'] = $verbose;
        if (empty($config['log']['file'])) {
            $config['log']['file'] = '/logs/adguard';
        }
        $this->stopAd();
        yaml_emit_file($this->adguard, $config);
        $this->startAd();
    }

    /**
     * Apply only the runtime pieces affected by $service.
     * PHP/clash flags need no process restart; xray/nginx/adguard do.
     */
    public function applyLoggingRuntime(?string $service = null): void
    {
        $service = $service !== null ? strtolower(trim($service)) : null;
        $touchAll = $service === null || $service === '';
        $touchXray = $touchAll || in_array($service, [
            'xray', 'xray_access', 'xray_api_inbound', 'xray_reality_show',
        ], true);
        $touchNginx = $touchAll || $service === 'nginx';
        $touchUpstream = $touchAll || in_array($service, [
            'nginx_upstream', 'nginx_upstream_access',
        ], true);
        $touchAdguard = $touchAll || $service === 'adguard';
        // php / php_webhook / php_requests / clash: pac.json only (read at request/sub time)

        if ($touchXray) {
            try {
                $xray = $this->getXray();
                $this->applyXrayLogConfig($xray);
                $this->applyXrayApiRuntimeConfig($xray);
                // Log-level changes must not fan out a full node sync.
                $this->restartXray($xray, false, false);
            } catch (Throwable $e) {
                error_log('applyLoggingRuntime xray: ' . $e->getMessage());
            }
        }

        if ($touchNginx) {
            try {
                if (method_exists($this, 'cloakNginx')) {
                    $this->cloakNginx();
                }
            } catch (Throwable $e) {
                error_log('applyLoggingRuntime nginx: ' . $e->getMessage());
            }
        }

        if ($touchUpstream) {
            try {
                $this->applyUpstreamLogging(true);
            } catch (Throwable $e) {
                error_log('applyLoggingRuntime upstream: ' . $e->getMessage());
            }
        }

        if ($touchAdguard) {
            try {
                $this->applyAdguardLogging();
            } catch (Throwable $e) {
                error_log('applyLoggingRuntime adguard: ' . $e->getMessage());
            }
        }
    }

    public function logLevels()
    {
        $this->ackCallback();
        $levels = $this->getLogLevels();
        $defs = $this->getLoggingServiceDefinitions();
        $text[] = 'Menu -> ' . $this->i18n('config') . ' -> ' . $this->i18n('logs') . ' -> ' . $this->i18n('log_levels');
        $text[] = $this->i18n('log_levels_help');

        $data = [];
        $row = [];
        foreach ($defs as $key => $meta) {
            $label = (string) ($meta['label'] ?? $key);
            $current = (string) ($levels[$key] ?? '');
            $row[] = [
                'text'          => $label . ': ' . $current,
                'callback_data' => "/logLevelView $key",
            ];
            if (count($row) === 2) {
                $data[] = $row;
                $row = [];
            }
        }
        if ($row !== []) {
            $data[] = $row;
        }
        $data[] = [[
            'text'          => $this->i18n('back'),
            'callback_data' => '/logs',
        ]];
        $this->replyMenu(
            $this->input['chat'],
            (int) ($this->input['message_id'] ?? 0),
            implode("\n", $text),
            $data
        );
    }

    public function logLevelView(string $service)
    {
        $this->ackCallback();
        $defs = $this->getLoggingServiceDefinitions();
        if (!isset($defs[$service])) {
            $this->logLevels();
            return;
        }
        $meta = $defs[$service];
        $current = $this->getLogLevel($service);
        $text[] = (string) ($meta['label'] ?? $service);
        $text[] = $this->i18n('current') . ': <code>' . $current . '</code>';

        $data = [];
        $row = [];
        foreach ($meta['levels'] as $level) {
            $row[] = [
                'text'          => $level . ($level === $current ? ' ✓' : ''),
                'callback_data' => "/setLogLevel $service $level",
            ];
            if (count($row) === 2) {
                $data[] = $row;
                $row = [];
            }
        }
        if ($row !== []) {
            $data[] = $row;
        }
        $data[] = [[
            'text'          => $this->i18n('back'),
            'callback_data' => '/logLevels',
        ]];
        $this->replyMenu(
            $this->input['chat'],
            (int) ($this->input['message_id'] ?? 0),
            implode("\n", $text),
            $data
        );
    }

    public function setLogLevel(string $service, string $level)
    {
        $defs = $this->getLoggingServiceDefinitions();
        if (!isset($defs[$service])) {
            $this->logLevels();
            return;
        }
        $pac = $this->getPacConf();
        if (!isset($pac['log_levels']) || !is_array($pac['log_levels'])) {
            $pac['log_levels'] = [];
        }
        $pac['log_levels'][$service] = $this->normalizeLogLevel($service, $level);
        $this->setPacConf($pac);
        $this->applyLoggingRuntime($service);
        $this->logLevelView($service);
    }
}
