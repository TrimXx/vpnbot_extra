<?php

date_default_timezone_set(getenv('TZ'));

function vpnbot_log_levels_from_pac(): array
{
    static $levels = null;
    if ($levels !== null) {
        return $levels;
    }
    $levels = [];
    $raw = @file_get_contents('/config/pac.json');
    if ($raw !== false) {
        $pac = json_decode($raw, true);
        if (is_array($pac) && is_array($pac['log_levels'] ?? null)) {
            $levels = $pac['log_levels'];
        }
    }

    return $levels;
}

function vpnbot_trace_enabled(): bool
{
    return strtolower((string) (vpnbot_log_levels_from_pac()['php_webhook'] ?? 'off')) === 'on';
}

function vpnbot_requests_logging_enabled(): bool
{
    return strtolower((string) (vpnbot_log_levels_from_pac()['php_requests'] ?? 'off')) === 'on';
}

function vpnbot_php_error_reporting(): int
{
    $level = strtolower((string) (vpnbot_log_levels_from_pac()['php'] ?? 'error'));

    return match ($level) {
        'debug' => E_ALL,
        'warning' => E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR
            | E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE | E_STRICT | E_RECOVERABLE_ERROR,
        default => E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR,
    };
}

function vpnbot_trace(string $line): void
{
    if (!vpnbot_trace_enabled()) {
        return;
    }
    $dir = '/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    file_put_contents(
        $dir . '/webhook',
        date('c') . ' ' . $line . "\n",
        FILE_APPEND | LOCK_EX
    );
}

// Production: only fatals in log. php.ini also has error_reporting = E_ERROR.
// E_WARNING (incl. Undefined array key in PHP 8+) floods /logs/php_error on large bot.php.
ini_set('log_errors', '1');
ini_set('display_errors', '0');
ini_set('error_log', '/logs/php_error');
error_reporting(vpnbot_php_error_reporting());

set_error_handler(static function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    error_log("PHP error [$severity] $message in $file:$line");
    return false;
});

set_exception_handler(static function ($exception) {
    $msg = sprintf(
        "Uncaught %s: %s in %s:%d\nStack trace:\n%s",
        get_class($exception),
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine(),
        $exception->getTraceAsString()
    );
    error_log($msg);
});

register_shutdown_function(static function () {
    $error = error_get_last();
    if ($error === null) {
        return;
    }
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (in_array($error['type'] ?? 0, $fatalTypes, true)) {
        error_log(sprintf(
            'Shutdown fatal [%d] %s in %s:%d',
            $error['type'],
            $error['message'] ?? '',
            $error['file'] ?? '',
            $error['line'] ?? 0
        ));
    }
});
