<?php

trait NodeTrait
{
  protected ?bool $nodeSyncScheduled = null;

  public function isChildNode(): bool
  {
    return ($this->getPacConf()['node_role'] ?? 'parent') === 'child';
  }

  public function isParentNode(): bool
  {
    return !$this->isChildNode();
  }

  /**
   * @return list<array{id: string, name: string, domain: string, enabled: bool, registered: bool, last_sync: int, last_ok: int, last_error: string}>
   */
  protected function getEnabledChildNodes(?array $pac = null): array
  {
    $pac = $pac ?? $this->getPacConf();
    $list = $pac['child_nodes'] ?? [];
    if (!is_array($list)) {
      return [];
    }
    $nodes = [];
    foreach ($list as $id => $node) {
      if (!is_array($node) || empty($node['enabled'])) {
        continue;
      }
      $domain = trim((string) ($node['domain'] ?? ''));
      if ($domain === '' || empty($node['registered'])) {
        continue;
      }
      $name = trim((string) ($node['name'] ?? ''));
      if ($name === '') {
        $name = $this->deriveNodeProxyLabel($domain);
      }
      $nodes[] = [
        'id'         => (string) $id,
        'name'       => $this->sanitizeNodeProxyLabel($name),
        'domain'     => $domain,
        'enabled'    => true,
        'registered' => true,
        'last_sync'  => (int) ($node['last_sync'] ?? 0),
        'last_ok'    => (int) ($node['last_ok'] ?? 0),
        'last_error' => (string) ($node['last_error'] ?? ''),
      ];
    }

    return $nodes;
  }

  protected function getChildNodesRaw(?array $pac = null): array
  {
    $pac = $pac ?? $this->getPacConf();
    $list = $pac['child_nodes'] ?? [];

    return is_array($list) ? $list : [];
  }

  protected function deriveNodeProxyLabel(string $host): string
  {
    $host = trim($host);
    if ($host === '') {
      return 'node';
    }
    if (filter_var($host, FILTER_VALIDATE_IP)) {
      $parts = explode('.', $host);

      return 'n' . ($parts[count($parts) - 1] ?? '1');
    }
    $parts = explode('.', $host);

    return $this->sanitizeNodeProxyLabel((string) ($parts[0] ?? 'node'));
  }

  protected function sanitizeNodeProxyLabel(string $label): string
  {
    $label = preg_replace('~[^a-zA-Z0-9_-]~', '', $label) ?? '';
    if ($label === '') {
      return 'node';
    }
    if (function_exists('mb_substr')) {
      return mb_substr($label, 0, 16, 'UTF-8');
    }

    return substr($label, 0, 16);
  }

  protected function getParentPublicUrl(?array $pac = null): string
  {
    $pac = $pac ?? $this->getPacConf();
    $domain = trim((string) ($pac['domain'] ?? ''));
    if ($domain === '') {
      $domain = trim((string) $this->ip);
    }

    return 'https://' . $domain;
  }

  protected function getNodeSyncPacExcludeKeys(): array
  {
    return [
      'child_nodes',
      'node_role',
      'node_id',
      'parent_url',
      'node_sync_token',
      'domain',
      'domain_main',
      'domain_aliases',
      'mirrorlist',
      'mirror_labels',
    ];
  }

  protected function getNodeSyncPacLocalKeys(): array
  {
    return [
      'child_nodes',
      'node_role',
      'node_id',
      'parent_url',
      'node_sync_token',
      'domain',
      'domain_main',
      'domain_aliases',
      'child_adguard',
    ];
  }

  public function buildNodeSyncPayload(): array
  {
    $this->wg = 1;
    $wg1 = [
      'server'  => $this->readConfig(),
      'clients' => json_decode(file_get_contents($this->clients1), true) ?: [],
    ];
    $pac = $this->getPacConf();
    foreach ($this->getNodeSyncPacExcludeKeys() as $key) {
      unset($pac[$key]);
    }
    $payload = [
      'version' => time(),
      'pac'     => $pac,
      'wg1'     => $wg1,
      'hwid'    => file_exists($this->hwid) ? (json_decode(file_get_contents($this->hwid), true) ?: []) : [],
      'mtproto' => file_get_contents('/config/mtprotosecret'),
      'mtprotodomain' => file_get_contents('/config/mtprotodomain'),
      'xray'    => $this->getXray(),
      'hy'      => yaml_parse_file('/config/hysteria.yaml'),
      'deny'    => file_exists('/config/deny') ? (string) file_get_contents('/config/deny') : '',
    ];
    $fullPac = $this->getPacConf();
    if (!empty($fullPac['child_adguard'])) {
      $payload['ad'] = yaml_parse_file($this->adguard);
    }

    return $payload;
  }

  protected function mergeNodeSyncPac(array $current, array $incoming): array
  {
    $local = [];
    foreach ($this->getNodeSyncPacLocalKeys() as $key) {
      if (array_key_exists($key, $current)) {
        $local[$key] = $current[$key];
      }
    }
    $merged = array_replace_recursive($current, $incoming);
    foreach ($local as $key => $value) {
      $merged[$key] = $value;
    }
    if (($merged['node_role'] ?? '') !== 'child') {
      $merged['node_role'] = 'child';
    }

    return $merged;
  }

  public function applyNodeSyncPayload(array $json, bool $restart = true): array
  {
    $out = [];
    if (empty($json) || !is_array($json)) {
      return ['error: invalid payload'];
    }

    $importPac = null;
    $switch_wg1amnezia = 0;
    if (!empty($json['pac']) && is_array($json['pac'])) {
      $out[] = 'update pac';
      $currentPac = $this->getPacConf();
      $importPac = $this->mergeNodeSyncPac($currentPac, $json['pac']);
      $switch_wg1amnezia = (($currentPac['wg1_amnezia'] ?? 0) != ($importPac['wg1_amnezia'] ?? 0)) ? 1 : 0;
      $this->setPacConf($importPac);
      $this->pacUpdate('1');
    }

    if (!empty($json['wg1'])) {
      $out[] = 'update wireguard 1';
      $this->wg = 1;
      $this->saveClients($json['wg1']['clients']);
      $this->restartWG($this->createConfig($json['wg1']['server']), $switch_wg1amnezia ?? 0);
      $this->iptablesWG();
    }

    if (!empty($json['ad']) && !empty($this->getPacConf()['child_adguard'])) {
      $out[] = 'update adguard';
      $this->stopAd();
      yaml_emit_file($this->adguard, $json['ad']);
      $this->startAd();
    }

    if (!empty($json['mtproto'])) {
      $out[] = 'update mtproto';
      file_put_contents('/config/mtprotosecret', $json['mtproto']);
      file_put_contents('/config/mtprotodomain', $json['mtprotodomain'] ?: '');
      if (!$this->isChildNode()) {
        $this->restartTG();
      }
    }

    if (array_key_exists('hwid', $json)) {
      $out[] = 'update hwid devices';
      $data = is_array($json['hwid']) ? $json['hwid'] : [];
      file_put_contents($this->hwid, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    if (!empty($json['xray'])) {
      $out[] = 'update xray';
      $this->restartXray($json['xray']);
      $this->adguardXrayClients();
      $pacForRestore = is_array($importPac) ? $importPac : $this->getPacConf();
      $this->setUpstreamDomain($this->getUpstreamRealityDomain($pacForRestore, $json['xray'] ?? null));
    }

    if (!empty($json['hy'])) {
      $out[] = 'update hysteria';
      yaml_emit_file('/config/hysteria.yaml', $json['hy']);
      $this->restartHysteria();
    }

    if (array_key_exists('deny', $json)) {
      $out[] = 'update deny';
      file_put_contents('/config/deny', (string) $json['deny']);
      $this->syncDeny();
    }

    if ($restart) {
      $out[] = 'reset nginx';
      $this->cloakNginx();
      if (method_exists($this, 'restartHysteriaWithRetry')) {
        $this->restartHysteriaWithRetry();
      }
    }

    $out[] = 'end sync';

    return $out;
  }

  protected function signNodeSyncRequest(string $token, string $timestamp, string $body): string
  {
    return hash_hmac('sha256', $timestamp . "\n" . $body, $token);
  }

  protected function verifyNodeSyncRequest(string $token, string $timestamp, string $signature, string $body, int $maxSkew = 300): bool
  {
    if ($token === '' || $timestamp === '' || $signature === '') {
      return false;
    }
    if (!ctype_digit($timestamp)) {
      return false;
    }
    $ts = (int) $timestamp;
    if (abs(time() - $ts) > $maxSkew) {
      return false;
    }
    $expected = $this->signNodeSyncRequest($token, $timestamp, $body);

    return hash_equals($expected, $signature);
  }

  protected function httpNodeJsonRequest(string $url, string $method, string $body, string $token, int $timeout = 90): array
  {
    $timestamp = (string) time();
    $signature = $this->signNodeSyncRequest($token, $timestamp, $body);
    $ch = curl_init();
    curl_setopt_array($ch, [
      CURLOPT_URL            => $url,
      CURLOPT_CUSTOMREQUEST  => $method,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_CONNECTTIMEOUT => 10,
      CURLOPT_TIMEOUT        => $timeout,
      CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'X-Node-Sync-Timestamp: ' . $timestamp,
        'X-Node-Sync-Signature: ' . $signature,
      ],
      CURLOPT_POSTFIELDS     => $body,
    ]);
    $res = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    return [
      'code' => $code,
      'body' => is_string($res) ? $res : '',
      'error' => $err,
    ];
  }

  protected function getNodeSyncUrl(string $domain): string
  {
    $hash = $this->getHashBot();
    $domain = preg_replace('~^\w+://~', '', trim($domain));
    $domain = preg_replace('~/.*$~', '', $domain);

    return 'https://' . $domain . '/pac' . $hash . '/node-sync';
  }

  public function pushSyncToNode(string $nodeId, ?array $pac = null): array
  {
    $pac = $pac ?? $this->getPacConf();
    $nodes = $pac['child_nodes'] ?? [];
    if (!is_array($nodes) || empty($nodes[$nodeId]) || !is_array($nodes[$nodeId])) {
      return ['ok' => false, 'error' => 'node not found'];
    }
    $node = $nodes[$nodeId];
    $domain = trim((string) ($node['domain'] ?? ''));
    $token = trim((string) ($node['token'] ?? ''));
    if ($domain === '' || $token === '') {
      return ['ok' => false, 'error' => 'node incomplete'];
    }

    $payload = $this->buildNodeSyncPayload();
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $url = $this->getNodeSyncUrl($domain);
    $res = $this->httpNodeJsonRequest($url, 'POST', $body, $token);
    $nodes[$nodeId]['last_sync'] = time();
    if ($res['code'] >= 200 && $res['code'] < 300) {
      $nodes[$nodeId]['last_ok'] = time();
      $nodes[$nodeId]['last_error'] = '';
      $pac['child_nodes'] = $nodes;
      $this->setPacConf($pac);
      return ['ok' => true, 'response' => $res['body']];
    }
    $nodes[$nodeId]['last_error'] = $res['error'] ?: ('HTTP ' . $res['code'] . ': ' . substr($res['body'], 0, 200));
    $pac['child_nodes'] = $nodes;
    $this->setPacConf($pac);

    return ['ok' => false, 'error' => $nodes[$nodeId]['last_error']];
  }

  public function pushSyncToAllNodes(): array
  {
    if (!$this->isParentNode()) {
      return [];
    }
    $results = [];
    foreach (array_keys($this->getChildNodesRaw()) as $nodeId) {
      $node = $this->getChildNodesRaw()[$nodeId];
      if (empty($node['enabled']) || empty($node['registered'])) {
        continue;
      }
      $results[$nodeId] = $this->pushSyncToNode((string) $nodeId);
    }

    return $results;
  }

  public function scheduleNodeSync(): void
  {
    if (!$this->isParentNode()) {
      return;
    }
    if ($this->nodeSyncScheduled) {
      return;
    }
    $this->nodeSyncScheduled = true;
    $bot = $this;
    register_shutdown_function(static function () use ($bot) {
      try {
        $bot->pushSyncToAllNodes();
      } catch (Throwable $e) {
        file_put_contents('/logs/node_sync_error', date('c') . ' ' . $e->getMessage() . "\n", FILE_APPEND);
      }
    });
  }

  public function handleNodeSyncReceive(): void
  {
    $raw = file_get_contents('php://input') ?: '';
    $pac = $this->getPacConf();
    $token = trim((string) ($pac['node_sync_token'] ?? ''));
    $timestamp = (string) ($_SERVER['HTTP_X_NODE_SYNC_TIMESTAMP'] ?? '');
    $signature = (string) ($_SERVER['HTTP_X_NODE_SYNC_SIGNATURE'] ?? '');
    if (!$this->verifyNodeSyncRequest($token, $timestamp, $signature, $raw)) {
      http_response_code(403);
      header('Content-Type: application/json; charset=utf-8');
      echo json_encode(['ok' => false, 'error' => 'forbidden']);
      exit;
    }
    $json = json_decode($raw, true);
    if (!is_array($json)) {
      http_response_code(400);
      header('Content-Type: application/json; charset=utf-8');
      echo json_encode(['ok' => false, 'error' => 'invalid json']);
      exit;
    }
    $steps = $this->applyNodeSyncPayload($json);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'steps' => $steps]);
    exit;
  }

  public function handleNodeRegister(): void
  {
    if (!$this->isParentNode()) {
      http_response_code(403);
      header('Content-Type: application/json; charset=utf-8');
      echo json_encode(['ok' => false, 'error' => 'parent only']);
      exit;
    }
    $raw = file_get_contents('php://input') ?: '';
    $json = json_decode($raw, true);
    if (!is_array($json)) {
      http_response_code(400);
      header('Content-Type: application/json; charset=utf-8');
      echo json_encode(['ok' => false, 'error' => 'invalid json']);
      exit;
    }
    $nodeId = trim((string) ($json['node_id'] ?? ''));
    $token = trim((string) ($json['token'] ?? ''));
    $domain = trim((string) ($json['domain'] ?? ''));
    $ip = trim((string) ($json['ip'] ?? ''));
    if ($nodeId === '' || $token === '' || $domain === '') {
      http_response_code(400);
      header('Content-Type: application/json; charset=utf-8');
      echo json_encode(['ok' => false, 'error' => 'missing fields']);
      exit;
    }
    $timestamp = (string) ($_SERVER['HTTP_X_NODE_SYNC_TIMESTAMP'] ?? '');
    $signature = (string) ($_SERVER['HTTP_X_NODE_SYNC_SIGNATURE'] ?? '');
    if (!$this->verifyNodeSyncRequest($token, $timestamp, $signature, $raw)) {
      http_response_code(403);
      header('Content-Type: application/json; charset=utf-8');
      echo json_encode(['ok' => false, 'error' => 'forbidden']);
      exit;
    }
    $pac = $this->getPacConf();
    $nodes = $pac['child_nodes'] ?? [];
    if (!is_array($nodes) || empty($nodes[$nodeId]) || !is_array($nodes[$nodeId])) {
      http_response_code(404);
      header('Content-Type: application/json; charset=utf-8');
      echo json_encode(['ok' => false, 'error' => 'unknown node']);
      exit;
    }
    if (!hash_equals((string) ($nodes[$nodeId]['token'] ?? ''), $token)) {
      http_response_code(403);
      header('Content-Type: application/json; charset=utf-8');
      echo json_encode(['ok' => false, 'error' => 'bad token']);
      exit;
    }
    $nodes[$nodeId]['domain'] = preg_replace('~^\w+://~', '', $domain);
    $nodes[$nodeId]['domain'] = preg_replace('~/.*$~', '', $nodes[$nodeId]['domain']);
    $nodes[$nodeId]['registered'] = true;
    $nodes[$nodeId]['registered_at'] = time();
    if ($ip !== '') {
      $nodes[$nodeId]['ip'] = $ip;
    }
    $pac['child_nodes'] = $nodes;
    $this->setPacConf($pac);
    $sync = $this->pushSyncToNode($nodeId, $pac);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'sync' => $sync]);
    exit;
  }

  public function handleNodeBootstrap(string $nodeId): void
  {
    if (!$this->isParentNode()) {
      http_response_code(403);
      header('Content-Type: text/plain; charset=utf-8');
      echo 'parent only';
      exit;
    }
    $token = trim((string) ($_GET['token'] ?? ''));
    $pac = $this->getPacConf();
    $nodes = $pac['child_nodes'] ?? [];
    if ($token === '' || !is_array($nodes) || empty($nodes[$nodeId]) || !hash_equals((string) ($nodes[$nodeId]['token'] ?? ''), $token)) {
      http_response_code(403);
      header('Content-Type: text/plain; charset=utf-8');
      echo 'forbidden';
      exit;
    }
    $hash = $this->getHashBot();
    $parentUrl = $this->getParentPublicUrl($pac);
    $branch = trim((string) ($_GET['branch'] ?? 'v2'));
    if ($branch === '') {
      $branch = 'v2';
    }
    $scriptPath = __DIR__ . '/../join_node.sh';
    if (!is_readable($scriptPath)) {
      $scriptPath = dirname(__DIR__, 2) . '/scripts/join_node.sh';
    }
    $script = is_readable($scriptPath) ? file_get_contents($scriptPath) : '';
    if ($script === '') {
      http_response_code(500);
      header('Content-Type: text/plain; charset=utf-8');
      echo 'join script missing';
      exit;
    }
    $env = [
      'NODE_ID' => $nodeId,
      'NODE_TOKEN' => $token,
      'PARENT_URL' => $parentUrl,
      'BOT_KEY' => $this->key,
      'REPO_BRANCH' => $branch,
      'PAC_HASH' => $hash,
    ];
  $header = "#!/bin/sh\n";
    foreach ($env as $k => $v) {
      $header .= $k . '=' . escapeshellarg($v) . "\n";
      $header .= 'export ' . $k . "\n";
    }
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: inline; filename="join_node.sh"');
    echo $header . "\n" . $script;
    exit;
  }

  public function handleNodeUpdateReceive(): void
  {
    $raw = file_get_contents('php://input') ?: '';
    $pac = $this->getPacConf();
    $token = trim((string) ($pac['node_sync_token'] ?? ''));
    $timestamp = (string) ($_SERVER['HTTP_X_NODE_SYNC_TIMESTAMP'] ?? '');
    $signature = (string) ($_SERVER['HTTP_X_NODE_SYNC_SIGNATURE'] ?? '');
    if (!$this->verifyNodeSyncRequest($token, $timestamp, $signature, $raw)) {
      http_response_code(403);
      header('Content-Type: application/json; charset=utf-8');
      echo json_encode(['ok' => false, 'error' => 'forbidden']);
      exit;
    }
    $json = json_decode($raw, true);
    $branch = trim((string) ($json['branch'] ?? 'v2'));
    if ($branch === '') {
      $branch = 'v2';
    }
    if (!preg_match('~^[a-zA-Z0-9._-]+$~', $branch)) {
      http_response_code(400);
      header('Content-Type: application/json; charset=utf-8');
      echo json_encode(['ok' => false, 'error' => 'bad branch']);
      exit;
    }
    file_put_contents('/update/node_pull_branch', $branch);
    file_put_contents('/update/pipe', 'node');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'started' => true, 'branch' => $branch]);
    exit;
  }

  protected function rewriteClashProxyForChildNode(array $proxy, string $childDomain): array
  {
    $clone = $proxy;
    $clone['server'] = $childDomain;
    foreach (['servername', 'sni', 'peer'] as $field) {
      if (!empty($clone[$field])) {
        $clone[$field] = $childDomain;
      }
    }
    if (!empty($clone['client-fingerprint']) && is_string($clone['client-fingerprint'])) {
      // keep fingerprint
    }
    if (!empty($clone['ws-opts']) && is_array($clone['ws-opts'])) {
      if (!empty($clone['ws-opts']['headers']) && is_array($clone['ws-opts']['headers'])) {
        foreach ($clone['ws-opts']['headers'] as $hk => $hv) {
          if (strtolower((string) $hk) === 'host') {
            $clone['ws-opts']['headers'][$hk] = $childDomain;
          }
        }
      }
    }
    if (!empty($clone['reality-opts']) && is_array($clone['reality-opts']) && !empty($clone['reality-opts']['public-key'])) {
      if (!empty($clone['reality-opts']['servername'])) {
        $clone['reality-opts']['servername'] = $childDomain;
      }
    }

    return $clone;
  }

  protected function appendClashChildNodeProxies(array &$c, ?array $pac = null): void
  {
    if (!$this->isParentNode()) {
      return;
    }
    $pac = $pac ?? $this->getPacConf();
    $nodes = $this->getEnabledChildNodes($pac);
    if ($nodes === [] || empty($c['proxies']) || !is_array($c['proxies'])) {
      return;
    }

    $originalProxies = array_values($c['proxies']);
    $existingNames = [];
    foreach ($c['proxies'] as $proxy) {
      if (is_array($proxy) && !empty($proxy['name'])) {
        $existingNames[(string) $proxy['name']] = true;
      }
    }

    foreach ($nodes as $node) {
      foreach ($originalProxies as $proxy) {
        if (!is_array($proxy)) {
          continue;
        }
        $type = strtolower((string) ($proxy['type'] ?? ''));
        $origName = trim((string) ($proxy['name'] ?? ''));
        if ($origName === '') {
          continue;
        }
        $nodeName = $origName . '-' . $node['name'];
        if (isset($existingNames[$nodeName])) {
          continue;
        }

        $clone = $this->rewriteClashProxyForChildNode($proxy, $node['domain']);
        $clone['name'] = $nodeName;
        if ($type === 'wireguard') {
          $wgPort = (int) (getenv('WG1PORT') ?: 51821);
          $clone['port'] = $wgPort > 0 ? $wgPort : 51821;
        }

        $c['proxies'][] = $clone;
        $existingNames[$nodeName] = true;
        $this->linkClashTransportProxyToGroups($c, $origName, $nodeName);
      }
    }
  }

  public function nodes($page = 0)
  {
    if (!$this->isParentNode()) {
      $this->answer($this->input['callback_id'], 'parent only', true);
      return;
    }
    $p = $this->getPacConf();
    $nodes = $this->getChildNodesRaw($p);
    $enabled = count($this->getEnabledChildNodes($p));
    $total = count($nodes);
    $text[] = 'Menu -> ' . $this->i18n('xray') . ' -> ' . $this->i18n('nodes');
    $text[] = $this->i18n('nodes_help');
    $text[] = $this->i18n('enabled') . ": $enabled / $total";

    $data[] = [
      [
        'text'          => $this->i18n('add'),
        'callback_data' => '/nodeAdd',
      ],
      [
        'text'          => $this->i18n('nodes_sync_all'),
        'callback_data' => '/nodeSyncAll',
      ],
    ];
    $data[] = [
      [
        'text'          => $this->i18n('nodes_update_all'),
        'callback_data' => '/nodeUpdateAll',
      ],
    ];

    if ($nodes !== []) {
      $all = (int) max(1, ceil(count($nodes) / $this->limit));
      $page = min($page, $all - 1);
      $page = $page < 0 ? $all - 1 : $page;
      $slice = array_slice($nodes, $page * $this->limit, $this->limit, true);
      $i = 0;
      foreach ($slice as $id => $node) {
        $name = trim((string) ($node['name'] ?? $id));
        $domain = trim((string) ($node['domain'] ?? ''));
        $registered = !empty($node['registered']);
        $st = !empty($node['enabled']) ? $this->i18n('on') : $this->i18n('off');
        $label = $st . ' ' . $name;
        if ($domain !== '') {
          $label .= ' (' . $domain . ')';
        } elseif (!$registered) {
          $label .= ' [' . $this->i18n('nodes_pending') . ']';
        }
        $data[] = [
          [
            'text'          => $label,
            'callback_data' => "/nodeView $id $page",
          ],
        ];
        $i++;
      }
      if ($all > 1) {
        $data[] = [
          [
            'text'          => '<<',
            'callback_data' => "/nodes " . ($page - 1 >= 0 ? $page - 1 : $all - 1),
          ],
          [
            'text'          => (string) ($page + 1),
            'callback_data' => "/nodes $page",
          ],
          [
            'text'          => '>>',
            'callback_data' => "/nodes " . ($page < $all - 1 ? $page + 1 : 0),
          ],
        ];
      }
    }

    $data[] = [
      [
        'text'          => $this->i18n('back'),
        'callback_data' => '/xrayCore',
      ],
    ];
    $this->update(
      $this->input['chat'],
      $this->input['message_id'],
      implode("\n", $text ?: ['...']),
      $data ?: false,
    );
  }

  public function nodeAdd()
  {
    $r = $this->send(
      $this->input['chat'],
      "@{$this->input['username']} " . $this->i18n('nodes_add_prompt'),
      $this->input['message_id'],
      reply: 'EU:eu.example.com',
    );
    $_SESSION['reply'][$r['result']['message_id']] = [
      'start_message' => $this->input['message_id'],
      'callback'      => 'addNodeEntry',
      'args'          => [],
    ];
    if (session_status() === PHP_SESSION_ACTIVE) {
      session_write_close();
    }
  }

  public function addNodeEntry(string $line)
  {
    if (!$this->isParentNode()) {
      return;
    }
    $line = trim($line);
    if (!preg_match('~^([^:]+):(.+)$~', $line, $m)) {
      $this->send($this->input['from'], $this->i18n('nodes_add_prompt'));
      return;
    }
    $name = $this->sanitizeNodeProxyLabel(trim($m[1]));
    $domain = preg_replace('~^\w+://~', '', trim($m[2]));
    $domain = preg_replace('~/.*$~', '', $domain);
    if ($name === '' || $domain === '') {
      $this->send($this->input['from'], $this->i18n('nodes_add_prompt'));
      return;
    }
    $nodeId = bin2hex(random_bytes(8));
    $token = bin2hex(random_bytes(32));
    $pac = $this->getPacConf();
    if (!isset($pac['child_nodes']) || !is_array($pac['child_nodes'])) {
      $pac['child_nodes'] = [];
    }
    $pac['child_nodes'][$nodeId] = [
      'name'       => $name,
      'domain'     => $domain,
      'enabled'    => true,
      'registered' => false,
      'token'      => $token,
      'created_at' => time(),
    ];
    if (($pac['node_role'] ?? '') !== 'parent') {
      $pac['node_role'] = 'parent';
    }
    $this->setPacConf($pac);
    $this->nodeJoinCommand($nodeId);
  }

  public function nodeView(string $nodeId, int $page = 0)
  {
    $pac = $this->getPacConf();
    $node = $pac['child_nodes'][$nodeId] ?? null;
    if (!is_array($node)) {
      $this->nodes($page);
      return;
    }
    $text[] = 'Menu -> ' . $this->i18n('nodes') . ' -> ' . ($node['name'] ?? $nodeId);
    $text[] = 'id: <code>' . htmlspecialchars($nodeId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>';
    $text[] = 'domain: <code>' . htmlspecialchars((string) ($node['domain'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>';
    $text[] = $this->i18n('nodes_registered') . ': ' . $this->i18n(!empty($node['registered']) ? 'on' : 'off');
    if (!empty($node['last_ok'])) {
      $text[] = $this->i18n('nodes_last_ok') . ': ' . date('Y-m-d H:i:s', (int) $node['last_ok']);
    }
    if (!empty($node['last_error'])) {
      $text[] = $this->i18n('error') . ': ' . htmlspecialchars((string) $node['last_error'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    $data[] = [
      [
        'text'          => $this->i18n(!empty($node['enabled']) ? 'on' : 'off'),
        'callback_data' => "/nodeToggle $nodeId $page",
      ],
      [
        'text'          => $this->i18n('nodes_join_cmd'),
        'callback_data' => "/nodeJoin $nodeId",
      ],
    ];
    $data[] = [
      [
        'text'          => $this->i18n('nodes_sync_one'),
        'callback_data' => "/nodeSyncOne $nodeId $page",
      ],
      [
        'text'          => 'delete',
        'callback_data' => "/nodeDelete $nodeId $page",
      ],
    ];
    $data[] = [
      [
        'text'          => $this->i18n('back'),
        'callback_data' => "/nodes $page",
      ],
    ];
    $this->update(
      $this->input['chat'],
      $this->input['message_id'],
      implode("\n", $text),
      $data,
    );
  }

  public function nodeJoinCommand(string $nodeId)
  {
    $pac = $this->getPacConf();
    $node = $pac['child_nodes'][$nodeId] ?? null;
    if (!is_array($node)) {
      $this->nodes();
      return;
    }
    $hash = $this->getHashBot();
    $token = urlencode((string) ($node['token'] ?? ''));
    $parentUrl = $this->getParentPublicUrl($pac);
    $domain = trim((string) ($node['domain'] ?? 'child.example.com'));
    $cmd = "curl -fsSL \"{$parentUrl}/pac{$hash}/node-bootstrap/{$nodeId}?token={$token}\" | bash -s -- {$domain}";
    $text[] = $this->i18n('nodes_join_cmd');
    $text[] = '<code>' . htmlspecialchars($cmd, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>';
    $text[] = $this->i18n('nodes_join_help');
    $data[] = [
      [
        'text'          => $this->i18n('back'),
        'callback_data' => "/nodeView $nodeId",
      ],
    ];
    $this->update(
      $this->input['chat'],
      $this->input['message_id'],
      implode("\n", $text),
      $data,
    );
  }

  public function nodeToggle(string $nodeId, int $page = 0)
  {
    $pac = $this->getPacConf();
    if (empty($pac['child_nodes'][$nodeId])) {
      $this->nodes($page);
      return;
    }
    $pac['child_nodes'][$nodeId]['enabled'] = empty($pac['child_nodes'][$nodeId]['enabled']);
    $this->setPacConf($pac);
    $this->nodeView($nodeId, $page);
  }

  public function nodeDelete(string $nodeId, int $page = 0)
  {
    $pac = $this->getPacConf();
    unset($pac['child_nodes'][$nodeId]);
    $this->setPacConf($pac);
    $this->nodes($page);
  }

  public function nodeSyncAll()
  {
    $this->answer($this->input['callback_id'], $this->i18n('nodes_syncing'), true);
    $results = $this->pushSyncToAllNodes();
    $ok = 0;
    $fail = 0;
    foreach ($results as $r) {
      if (!empty($r['ok'])) {
        $ok++;
      } else {
        $fail++;
      }
    }
    $this->update(
      $this->input['chat'],
      $this->input['message_id'],
      $this->i18n('nodes_sync_result') . ": OK=$ok FAIL=$fail",
      [[['text' => $this->i18n('back'), 'callback_data' => '/nodes']]],
    );
  }

  public function nodeSyncOne(string $nodeId, int $page = 0)
  {
    $r = $this->pushSyncToNode($nodeId);
    $msg = !empty($r['ok']) ? $this->i18n('success') : ((string) ($r['error'] ?? $this->i18n('error')));
    $this->answer($this->input['callback_id'], $msg, true);
    $this->nodeView($nodeId, $page);
  }

    public function nodePushUpdateToAll(): array
  {
    if (!$this->isParentNode()) {
      return [];
    }
    $branch = 'v2';
    if (is_readable(dirname(__DIR__, 2) . '/version')) {
      $ver = trim((string) file_get_contents(dirname(__DIR__, 2) . '/version'));
      if (preg_match('~^v\d~', $ver)) {
        $branch = $ver;
      }
    }
    $body = json_encode(['branch' => $branch], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $results = [];
    foreach ($this->getChildNodesRaw() as $nodeId => $node) {
      if (empty($node['enabled']) || empty($node['registered'])) {
        continue;
      }
      $domain = trim((string) ($node['domain'] ?? ''));
      $token = trim((string) ($node['token'] ?? ''));
      if ($domain === '' || $token === '') {
        continue;
      }
      $hash = $this->getHashBot();
      $domain = preg_replace('~^\w+://~', '', $domain);
      $url = 'https://' . $domain . '/pac' . $hash . '/node-update';
      $results[$nodeId] = $this->httpNodeJsonRequest($url, 'POST', $body, $token);
    }

    return $results;
  }

  public function nodeUpdateAll()
  {
    $this->answer($this->input['callback_id'], $this->i18n('nodes_updating'), true);
    $results = $this->nodePushUpdateToAll();
    $ok = 0;
    foreach ($results as $r) {
      if (($r['code'] ?? 0) >= 200 && ($r['code'] ?? 0) < 300) {
        $ok++;
      }
    }
    $this->update(
      $this->input['chat'],
      $this->input['message_id'],
      $this->i18n('nodes_update_result') . ": OK=$ok/" . count($results),
      [[['text' => $this->i18n('back'), 'callback_data' => '/nodes']]],
    );
  }
}
