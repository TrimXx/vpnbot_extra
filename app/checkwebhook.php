<?php

require './config.php';

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => "https://api.telegram.org/bot{$c['key']}/getWebhookInfo",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
]);
$res = curl_exec($ch);
curl_close($ch);
$j = json_decode($res, true);
if (!is_array($j) || empty($j['ok'])) {
    echo "getWebhookInfo failed\n";
    var_export($res);
    exit(1);
}
$r = $j['result'] ?? [];
echo "url: " . ($r['url'] ?? '-') . "\n";
echo "pending_update_count: " . ($r['pending_update_count'] ?? '?') . "\n";
echo "last_error_date: " . (isset($r['last_error_date']) ? date('c', (int) $r['last_error_date']) : '-') . "\n";
echo "last_error_message: " . ($r['last_error_message'] ?? '-') . "\n";
echo "ip_address: " . ($r['ip_address'] ?? '-') . "\n";
echo "max_connections: " . ($r['max_connections'] ?? '-') . "\n";
