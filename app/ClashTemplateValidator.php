<?php

require_once __DIR__ . '/ClashTemplatePlaceholders.php';

class ClashTemplateValidator
{
  private const KNOWN_PLACEHOLDERS = ClashTemplatePlaceholders::CATALOG;

  private const BUILTIN_ACTIONS = ['DIRECT', 'REJECT', 'PROXY', 'GLOBAL', 'PASS', 'COMPATIBLE'];

  private const LIST_PLACEHOLDERS = [
    '~pac~', '~block~', '~warp~', '~process~', '~package~', '~subnet~',
  ];

  private const ALLOWED_GROUP_TYPES = [
    'select', 'url-test', 'fallback', 'load-balance', 'relay',
  ];

  private const ALLOWED_RULE_TYPES = [
    'DOMAIN', 'DOMAIN-SUFFIX', 'DOMAIN-KEYWORD', 'DOMAIN-REGEX',
    'GEOSITE', 'GEOIP', 'IP-CIDR', 'IP-CIDR6', 'IP-ASN',
    'SRC-IP-CIDR', 'SRC-PORT', 'DST-PORT', 'IN-PORT',
    'PROCESS-NAME', 'PROCESS-PATH', 'PROCESS-PATH-REGEX',
    'NETWORK', 'UID', 'IN-TYPE', 'IN-USER', 'IN-NAME',
    'RULE-SET', 'AND', 'OR', 'NOT', 'SUB-RULE', 'MATCH',
  ];

  /**
   * @return array{ok: bool, errors: list<string>, warnings: list<string>}
   */
  public static function validateClashTemplate(array $template): array
  {
    $errors = [];
    $warnings = [];

    if (self::isListArray($template) || $template === []) {
      $errors[] = 'template root must be a JSON object';
      return ['ok' => false, 'errors' => $errors, 'warnings' => $warnings];
    }

    $proxyNames = [];
    if (!isset($template['proxies']) || !is_array($template['proxies'])) {
      $errors[] = 'proxies must be a non-empty array';
    } elseif ($template['proxies'] === []) {
      $errors[] = 'proxies must not be empty';
    } else {
      foreach ($template['proxies'] as $i => $proxy) {
        if (!is_array($proxy) || ($proxy !== [] && self::isListArray($proxy))) {
          $errors[] = "proxies[$i] must be an object";
          continue;
        }
        $name = trim((string) ($proxy['name'] ?? ''));
        if ($name === '') {
          $errors[] = "proxies[$i].name is required";
        } elseif (isset($proxyNames[$name])) {
          $errors[] = "duplicate proxy name: $name";
        } else {
          $proxyNames[$name] = true;
        }
        $type = strtolower(trim((string) ($proxy['type'] ?? '')));
        if ($type === '') {
          $errors[] = "proxies[$i]" . ($name !== '' ? " ($name)" : '') . ': type is required';
        }
        if ($type === 'vless' && !self::containsPlaceholder($proxy, '~uid~')) {
          $warnings[] = "proxies[$i] ($name): vless without ~uid~ placeholder";
        }
        self::scanUnknownPlaceholders($proxy, "proxies[$i]", $warnings);
      }
    }

    $groupNames = [];
    $hasProxyGroup = false;
    if (empty($template['proxy-groups']) || !is_array($template['proxy-groups'])) {
      $errors[] = 'proxy-groups must be a non-empty array';
    } else {
      foreach ($template['proxy-groups'] as $gi => $group) {
        if (!is_array($group) || ($group !== [] && self::isListArray($group))) {
          $errors[] = "proxy-groups[$gi] must be an object";
          continue;
        }
        $groupName = trim((string) ($group['name'] ?? ''));
        if ($groupName === '') {
          $errors[] = "proxy-groups[$gi].name is required";
        } elseif (isset($groupNames[$groupName])) {
          $errors[] = "duplicate proxy-group name: $groupName";
        } else {
          $groupNames[$groupName] = true;
          if ($groupName === 'PROXY') {
            $hasProxyGroup = true;
          }
        }
        $gType = strtolower(trim((string) ($group['type'] ?? '')));
        if ($gType === '') {
          $errors[] = "proxy-groups[$gi]" . ($groupName !== '' ? " ($groupName)" : '') . ': type is required';
        } elseif (!in_array($gType, self::ALLOWED_GROUP_TYPES, true)) {
          $warnings[] = "proxy-groups[$gi] ($groupName): unusual type '$gType'";
        }
        if (in_array($gType, ['url-test', 'fallback', 'load-balance'], true)) {
          if (empty($group['url'])) {
            $warnings[] = "proxy-groups[$gi] ($groupName): $gType without url";
          }
          if (empty($group['interval'])) {
            $warnings[] = "proxy-groups[$gi] ($groupName): $gType without interval";
          }
        }
        self::scanUnknownPlaceholders($group, "proxy-groups[$gi]", $warnings);
      }
      if (!$hasProxyGroup) {
        $errors[] = 'proxy-groups must include a group named PROXY';
      }

      foreach ($template['proxy-groups'] as $gi => $group) {
        if (!is_array($group)) {
          continue;
        }
        $groupName = trim((string) ($group['name'] ?? ''));
        if (empty($group['proxies']) || !is_array($group['proxies'])) {
          $errors[] = "proxy-groups[$gi]" . ($groupName !== '' ? " ($groupName)" : '') . ': proxies must be a non-empty array';
          continue;
        }
        foreach ($group['proxies'] as $ri => $ref) {
          $ref = trim((string) $ref);
          if ($ref === '' || in_array($ref, self::BUILTIN_ACTIONS, true)) {
            continue;
          }
          if (!isset($proxyNames[$ref]) && !isset($groupNames[$ref])) {
            $errors[] = "proxy-groups[$gi] ($groupName) proxies[$ri]: unknown proxy/group '$ref'";
          }
        }
      }
    }

    $providers = [];
    if (isset($template['rule-providers'])) {
      if (!is_array($template['rule-providers'])) {
        $errors[] = 'rule-providers must be an object when present';
      } elseif ($template['rule-providers'] !== [] && self::isListArray($template['rule-providers'])) {
        $errors[] = 'rule-providers must be an object when present';
      } else {
        foreach ($template['rule-providers'] as $pid => $provider) {
          $pid = (string) $pid;
          if (!is_array($provider) || ($provider !== [] && self::isListArray($provider))) {
            $errors[] = "rule-providers.$pid must be an object";
            continue;
          }
          $providers[$pid] = true;
          $behavior = strtolower(trim((string) ($provider['behavior'] ?? '')));
          $format = strtolower(trim((string) ($provider['format'] ?? 'yaml')));
          if ($behavior === '') {
            $errors[] = "rule-providers.$pid: behavior is required (domain|ipcidr|classical)";
          } elseif (!in_array($behavior, ['domain', 'ipcidr', 'classical'], true)) {
            $errors[] = "rule-providers.$pid: invalid behavior '$behavior'";
          }
          if (!in_array($format, ['yaml', 'text', 'mrs'], true)) {
            $errors[] = "rule-providers.$pid: invalid format '$format'";
          }
          if ($format === 'mrs' && $behavior === 'classical') {
            $errors[] = "rule-providers.$pid: mrs format does not support classical behavior (use domain or ipcidr)";
          }
          $ptype = strtolower(trim((string) ($provider['type'] ?? 'http')));
          if ($ptype === 'http') {
            $url = trim((string) ($provider['url'] ?? ''));
            if ($url === '') {
              $errors[] = "rule-providers.$pid: url is required for type http";
            } elseif (!preg_match('~^https?://~i', $url) && !str_contains($url, '~')) {
              $warnings[] = "rule-providers.$pid: url does not look like http(s)";
            }
          }
          if ($format === 'mrs' && !empty($provider['url']) && !preg_match('~\.mrs(\?.*)?$~i', (string) $provider['url'])) {
            $warnings[] = "rule-providers.$pid: format mrs but url has no .mrs extension";
          }
        }
      }
    }

    $knownActions = $groupNames + array_fill_keys(self::BUILTIN_ACTIONS, true);

    if (isset($template['rules'])) {
      if (!is_array($template['rules'])) {
        $errors[] = 'rules must be an array when present';
      } elseif ($template['rules'] === []) {
        $warnings[] = 'rules array is empty';
      } else {
        $matchIndex = null;
        foreach ($template['rules'] as $ri => $rule) {
          if (!is_array($rule)) {
            $errors[] = "rules[$ri] must be an object";
            continue;
          }
          $type = strtoupper(trim((string) ($rule['type'] ?? '')));
          if ($type === '' && isset($rule[0])) {
            $warnings[] = "rules[$ri]: short-array rule form is not used in vpnbot templates (use {type, list/action})";
            continue;
          }
          if ($type === '') {
            $errors[] = "rules[$ri]: type is required";
            continue;
          }
          if (!in_array($type, self::ALLOWED_RULE_TYPES, true)) {
            $warnings[] = "rules[$ri]: unusual rule type '$type'";
          }
          if ($type === 'MATCH') {
            $matchIndex = $ri;
            $action = trim((string) ($rule['action'] ?? ''));
            if ($action === '') {
              $errors[] = "rules[$ri] MATCH: action is required";
            } elseif (!isset($knownActions[$action]) && !isset($knownActions[strtoupper($action)])) {
              $errors[] = "rules[$ri] MATCH: unknown action '$action'";
            }
            continue;
          }

          $action = trim((string) ($rule['action'] ?? ''));
          if ($action === '') {
            $errors[] = "rules[$ri] ($type): action is required";
          } elseif (!isset($knownActions[$action]) && !isset($knownActions[strtoupper($action)])) {
            $errors[] = "rules[$ri] ($type): unknown action/group '$action'";
          }

          if ($type === 'RULE-SET') {
            $list = $rule['list'] ?? null;
            $providerName = trim((string) ($rule['name'] ?? ''));
            if (is_string($list)) {
              $listTrim = trim($list);
              if ($listTrim === '') {
                $errors[] = "rules[$ri] RULE-SET: list is empty";
              } elseif (in_array($listTrim, self::LIST_PLACEHOLDERS, true) || str_starts_with($listTrim, '~')) {
                // bot-hosted / placeholder lists — ok
              } elseif (!isset($providers[$listTrim])) {
                $errors[] = "rules[$ri] RULE-SET: list '$listTrim' not found in rule-providers";
              }
            } elseif (is_array($list)) {
              if ($providerName === '') {
                $errors[] = "rules[$ri] RULE-SET: name is required when list is an inline array / placeholder";
              }
            } else {
              $errors[] = "rules[$ri] RULE-SET: list must be a string provider id or array";
            }
          }

          if (in_array($type, ['DOMAIN', 'DOMAIN-SUFFIX', 'DOMAIN-KEYWORD', 'IP-CIDR', 'IP-CIDR6', 'PROCESS-NAME'], true)) {
            if (!array_key_exists('list', $rule) && !array_key_exists('payload', $rule) && !isset($rule['domain']) && !isset($rule['ip'])) {
              // vpnbot style uses "list" for many rule kinds
              if (!isset($rule['list'])) {
                $warnings[] = "rules[$ri] ($type): no list/payload field";
              }
            }
          }
        }

        $last = count($template['rules']) - 1;
        if ($matchIndex === null) {
          $warnings[] = 'rules: no MATCH rule (recommended as last rule)';
        } elseif ($matchIndex !== $last) {
          $errors[] = "rules: MATCH must be the last rule (found at index $matchIndex, last is $last)";
        }

        // App RULE-SET after block, before pac — soft check
        $pacIdx = null;
        $appAfterPac = [];
        foreach ($template['rules'] as $ri => $rule) {
          if (!is_array($rule) || strtoupper((string) ($rule['type'] ?? '')) !== 'RULE-SET') {
            continue;
          }
          $name = strtolower((string) ($rule['name'] ?? ''));
          $list = $rule['list'] ?? null;
          $listStr = is_string($list) ? $list : '';
          if ($name === 'pac' || $listStr === '~pac~') {
            $pacIdx = $ri;
            continue;
          }
          if ($pacIdx !== null && is_string($listStr) && $listStr !== '' && !str_starts_with($listStr, '~') && isset($providers[$listStr])) {
            $appAfterPac[] = $listStr;
          }
        }
        if ($appAfterPac !== []) {
          $warnings[] = 'rules: external RULE-SET (' . implode(', ', $appAfterPac) . ') appears after ~pac~ — app groups may lose to PROXY';
        }
      }
    } else {
      $warnings[] = 'rules key is missing';
    }

    if (array_key_exists('add-rule-providers', $template) && !is_bool($template['add-rule-providers']) && !in_array($template['add-rule-providers'], [0, 1, '0', '1'], true)) {
      $warnings[] = 'add-rule-providers should be boolean';
    }

    self::scanUnknownPlaceholders($template, 'root', $warnings);
    $warnings = array_values(array_unique($warnings));
    $errors = array_values(array_unique($errors));

    return [
      'ok' => $errors === [],
      'errors' => $errors,
      'warnings' => $warnings,
    ];
  }

  /**
   * Validate raw JSON text (syntax + template structure).
   *
   * @return array{ok: bool, errors: list<string>, warnings: list<string>, data: ?array}
   */
  public static function validateClashTemplateJson(string $json): array
  {
    $json = preg_replace('/^\xEF\xBB\xBF/', '', $json) ?? $json;
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
      $msg = json_last_error_msg();
      $hint = self::jsonErrorHint($json, json_last_error());
      return [
        'ok' => false,
        'errors' => [trim("JSON parse error: $msg" . ($hint !== '' ? " ($hint)" : ''))],
        'warnings' => [],
        'data' => null,
      ];
    }
    $result = self::validateClashTemplate($decoded);
    $result['data'] = $decoded;

    return $result;
  }

  public static function formatValidationMessage(array $validation, bool $includeWarnings = true): string
  {
    $lines = [];
    if (!empty($validation['errors'])) {
      $lines[] = 'Errors:';
      foreach ($validation['errors'] as $i => $e) {
        $lines[] = ($i + 1) . '. ' . $e;
      }
    }
    if ($includeWarnings && !empty($validation['warnings'])) {
      if ($lines !== []) {
        $lines[] = '';
      }
      $lines[] = 'Warnings:';
      foreach ($validation['warnings'] as $i => $w) {
        $lines[] = ($i + 1) . '. ' . $w;
      }
    }

    return implode("\n", $lines);
  }

  private static function jsonErrorHint(string $json, int $code): string
  {
    if ($code === JSON_ERROR_NONE) {
      return 'decoded value is not an object/array';
    }
    // Best-effort line hint for common syntax issues
    if (preg_match('~,\s*[}\]]~', $json)) {
      return 'possible trailing comma';
    }
    if (preg_match("~'[^']*'~", $json) && !preg_match('~"[^"]*"~', $json)) {
      return 'use double quotes, not single quotes';
    }

    return '';
  }

  private static function isListArray(array $value): bool
  {
    if (function_exists('array_is_list')) {
      return array_is_list($value);
    }
    $i = 0;
    foreach ($value as $k => $_) {
      if ($k !== $i) {
        return false;
      }
      $i++;
    }

    return true;
  }

  private static function containsPlaceholder(mixed $value, string $placeholder): bool
  {
    if (is_string($value)) {
      return str_contains($value, $placeholder);
    }
    if (!is_array($value)) {
      return false;
    }
    foreach ($value as $v) {
      if (self::containsPlaceholder($v, $placeholder)) {
        return true;
      }
    }
    return false;
  }

  private static function scanUnknownPlaceholders(mixed $value, string $path, array &$warnings): void
  {
    if (!is_string($value)) {
      if (is_array($value)) {
        foreach ($value as $k => $v) {
          $next = is_int($k) ? "$path[$k]" : "$path.$k";
          self::scanUnknownPlaceholders($v, $next, $warnings);
        }
      }
      return;
    }
    if (preg_match_all('~(~[a-z0-9_]+~)~i', $value, $m)) {
      foreach ($m[1] as $ph) {
        if (!isset(self::KNOWN_PLACEHOLDERS[$ph])) {
          $warnings[] = "$path: unknown placeholder $ph";
        }
      }
    }
  }
}
