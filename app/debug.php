<?php

require_once __DIR__ . '/timezone.php';

if (!vpnbot_requests_logging_enabled()) {
    return;
}

$GLOBALS['debug'] = true;

register_shutdown_function(static function () {
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    $line = date('Y-m-d H:i:s') . " {$method} {$uri}\n";
    @file_put_contents('/logs/requests', $line, FILE_APPEND | LOCK_EX);
});
