<?php

require __DIR__ . '/timezone.php';
require __DIR__ . '/config.php';
require __DIR__ . '/i18n.php';
require __DIR__ . '/bot.php';

$bot = new Bot($c['key'], $i);
if (!$bot->isParentNode()) {
    exit(0);
}
$bot->nodePushUpdateToAll();
$bot->pushSyncToAllNodes();
