<?php

require '/app/config.php';
require '/app/bot.php';
require '/app/i18n.php';

$bot = new Bot($c['key'], $i);
$hash = $bot->getHashBot();
$sub = $argv[1] ?? 'fc915e93-764e-4b2a-a4e7-89f51e147e50';
$domain = $argv[2] ?? 'test.trimx.ru';
$scheme = 'https';

$ref = new ReflectionMethod($bot, 'buildPacUrl');
$ref->setAccessible(true);
$build = function (array $params) use ($ref, $bot, $scheme, $domain, $hash) {
    return $ref->invoke($bot, $scheme, $domain, $hash, $params);
};

$urls = [
    'sub' => $build(['h' => $hash, 't' => 'cl', 's' => $sub]),
    'rule_package' => $build(['h' => $hash, 't' => 'cl', 's' => $sub, 'r' => 'package']),
    'rule_block' => $build(['h' => $hash, 't' => 'cl', 's' => $sub, 'r' => 'block']),
];

foreach ($urls as $name => $url) {
    echo strtoupper($name) . '=' . $url . PHP_EOL;
}
