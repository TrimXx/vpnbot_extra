<?php

require __DIR__ . '/timezone.php';

require __DIR__ . '/bot.php';
require __DIR__ . '/config.php';
require __DIR__ . '/i18n.php';
if (!empty($c['debug']) || vpnbot_requests_logging_enabled()) {
    require __DIR__ . '/debug.php';
}

$bot = new Bot($c['key'], $i);
echo $bot->export();
