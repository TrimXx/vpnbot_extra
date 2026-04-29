<?php

require __DIR__ . '/timezone.php';

require __DIR__ . '/bot.php';
require __DIR__ . '/config.php';
require __DIR__ . '/i18n.php';

if (!empty($c['debug'] ?? false)) {
    require __DIR__ . '/debug.php';
}

$bot = new Bot($c['key'], $i);

$bot->selfUpdate();
$bot->ssPswdCheck();
$bot->restartTG();
if (!empty($bot->selfupdate)) {
    $bot->offWarp();
}
$bot->dontshowcron = 1;
$bot->sslip();
$bot->adguardSync();
$bot->cloakNginx();
$bot->syncDeny();
$bot->cleanDocker();
$bot->dnsttStart();
$bot->restartHysteria();
$c = $bot->getPacConf();
$bot->setUpstreamDomain($c['transport'] != 'Reality' ? 't' : $c['reality']['domain']);
$bot->setUpstreamDomainNaive($c['domain']);
$bot->setUpstreamDomainOcserv($c['domain']);
