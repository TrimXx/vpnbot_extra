<?php

class ClashTemplateValidator
{
  private const KNOWN_PLACEHOLDERS = [
    '~uid~', '~domain~', '~directdomain~', '~cdndomain~', '~short_id~', '~email~',
    '~public_key~', '~server_name~', '~reality_server_host~', '~reality_server_port~',
    '~ip~', '~dns~', '~dnspath~', '~outbound~', '~pac~', '~block~', '~warp~',
    '~process~', '~package~', '~subnet~',
  ];

  public static function validateClashTemplate(array $template): array
  {
    $errors = [];
    $warnings = [];

    if (!isset($template['proxies']) || !is_array($template['proxies'])) {
      $errors[] = 'proxies must be a non-empty array';
    } elseif ($template['proxies'] === []) {
      $errors[] = 'proxies must not be empty';
    } else {
      $proxyNames = [];
      foreach ($template['proxies'] as $i => $proxy) {
        if (!is_array($proxy)) {
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
          $warnings[] = "proxies[$i].type is empty";
        }
        if ($type === 'vless' && !self::containsPlaceholder($proxy, '~uid~')) {
          $warnings[] = "proxies[$i] ($name): vless without ~uid~ placeholder";
        }
        self::scanUnknownPlaceholders($proxy, "proxies[$i]", $warnings);
      }

      $groupNames = [];
      $hasProxyGroup = false;
      if (empty($template['proxy-groups']) || !is_array($template['proxy-groups'])) {
        $errors[] = 'proxy-groups must be a non-empty array';
      } else {
        foreach ($template['proxy-groups'] as $gi => $group) {
          if (!is_array($group)) {
            $errors[] = "proxy-groups[$gi] must be an object";
            continue;
          }
          $groupName = trim((string) ($group['name'] ?? ''));
          if ($groupName === '') {
            $errors[] = "proxy-groups[$gi].name is required";
          } else {
            $groupNames[$groupName] = true;
            if ($groupName === 'PROXY') {
              $hasProxyGroup = true;
            }
          }
          if (empty($group['proxies']) || !is_array($group['proxies'])) {
            $warnings[] = "proxy-groups[$gi].proxies is empty";
            continue;
          }
          foreach ($group['proxies'] as $ref) {
            $ref = trim((string) $ref);
            if ($ref === '' || in_array($ref, ['DIRECT', 'REJECT', 'GLOBAL', 'PASS'], true)) {
              continue;
            }
            if (!isset($proxyNames[$ref]) && !isset($groupNames[$ref])) {
              $errors[] = "proxy-groups[$gi] references unknown proxy/group: $ref";
            }
          }
        }
        if (!$hasProxyGroup) {
          $errors[] = 'proxy-groups must include a group named PROXY';
        }
      }
    }

    if (isset($template['rules']) && !is_array($template['rules'])) {
      $errors[] = 'rules must be an array when present';
    }

    return [
      'ok' => $errors === [],
      'errors' => $errors,
      'warnings' => $warnings,
    ];
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
          self::scanUnknownPlaceholders($v, "$path.$k", $warnings);
        }
      }
      return;
    }
    if (preg_match_all('~(~[a-z_]+~|"[~[a-z_]+~]")~i', $value, $m)) {
      foreach ($m[1] as $ph) {
        $ph = trim($ph, '"');
        if (!in_array($ph, self::KNOWN_PLACEHOLDERS, true)) {
          $warnings[] = "$path: unknown placeholder $ph";
        }
      }
    }
  }
}
