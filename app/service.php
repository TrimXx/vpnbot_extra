<?php

require __DIR__ . '/timezone.php';

require __DIR__ . '/bot.php';
require __DIR__ . '/config.php';
require __DIR__ . '/i18n.php';

if (!empty($c['debug'] ?? false)) {
    require __DIR__ . '/debug.php';
}

$bot = new Bot($c['key'], $i);

$bot->migratePacConf();
$bot->selfUpdate();
if (!$bot->isChildNode()) {
    $bot->restartTG();
}
$bot->ssPswdCheck();
if (!empty($bot->selfupdate)) {
    $bot->offWarp();
}
$bot->dontshowcron = 1;
$bot->ensureAwgServerConfig();
$bot->syncRuntimeWgServerOnStartup();
$bot->sslip();
$pac = $bot->getPacConf();
if (!$bot->isChildNode() || !empty($pac['child_adguard'])) {
    $bot->adguardSync();
}
$bot->cloakNginx();
$bot->syncUpstreamRuntime();
$bot->syncDeny();
$bot->cleanDocker();
$bot->restartHysteriaWithRetry();
