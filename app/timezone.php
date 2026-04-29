<?php

date_default_timezone_set(getenv('TZ'));

// Unified PHP logging for all entrypoints that include timezone.php.
ini_set('log_errors', '1');
ini_set('display_errors', '0');
ini_set('error_log', '/logs/php_error');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);

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
