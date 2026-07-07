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
if (!$bot->isChildNode()) {
    $bot->sslip();
}
$pac = $bot->getPacConf();
if (!$bot->isChildNode() || !empty($pac['child_adguard'])) {
    try {
        $bot->adguardSync();
    } catch (Throwable $e) {
        error_log('adguardSync: ' . $e->getMessage());
    }
}
$bot->cloakNginx();
$bot->syncUpstreamRuntime();
$bot->syncDeny();
$bot->cleanDocker();
$bot->restartHysteriaWithRetry();
