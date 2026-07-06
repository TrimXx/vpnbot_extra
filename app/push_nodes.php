<?php

require __DIR__ . '/timezone.php';
require __DIR__ . '/config.php';
require __DIR__ . '/i18n.php';
require __DIR__ . '/bot.php';

$bot = new Bot($c['key'], $i);
if (!$bot->isParentNode()) {
    exit(0);
}

$mode = $argv[1] ?? 'both';
$wait = (int) ($argv[2] ?? 30);

if ($mode === 'update' || $mode === 'both') {
    $bot->nodePushUpdateToAll();
}
if ($mode === 'both' && $wait > 0) {
    sleep($wait);
}
if ($mode === 'sync' || $mode === 'both') {
    $bot->pushSyncToAllNodes();
}
