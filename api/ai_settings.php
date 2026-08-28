<?php
declare(strict_types=1);

/*
 * Per-user AI configuration for the isolated Ember CoreUI database.
 *
 * This file contains no HTTP dispatch. It is shared by user_settings.php,
 * chat.php and the administration API. All values are normalised again at
 * runtime so an old or manually modified database row cannot bypass limits.
 */

function coreui_ai_settings_schema_ready(PDO $pdo): bool {
  try {
    $pdo->query('SELECT user_id FROM stu_user_ai_settings LIMIT 1');
    return true;
  } catch (Throwable $e) {
    return false;
  }
}

function coreui_ai_prompt_limit(PDO $pdo): int {
  $raw = function_exists('stu_app_setting_get')
    ? (int)stu_app_setting_get($pdo, 'user_system_prompt_max_chars', '6000')
    : 6000;
  return max(500, min(12000, $raw));
}

function coreui_ai_settings_defaults(PDO $pdo): array {
  $memoryDefault = function_exists('stu_app_setting_get')
    ? stu_app_setting_get($pdo, 'memory_default_enabled', '1') !== '0'
    : true;
  $memoryLimit = defined('STU_EMBER_MEMORY_LIMIT') ? (int)STU_EMBER_MEMORY_LIMIT : 16;
  $numPredict = defined('STU_EMBER_NUM_PREDICT') ? (int)STU_EMBER_NUM_PREDICT : 6500;
  if ($numPredict < 0) $numPredict = 8192;
  $temperature = defined('STU_EMBER_TEMPERATURE') ? (float)STU_EMBER_TEMPERATURE : 1.0;

  return [
    'system_prompt' => '',
    'memory_enabled' => $memoryDefault,
    'memory_limit' => max(0, min(60, $memoryLimit)),
    'num_predict' => max(256, min(16384, $numPredict)),
    'temperature' => max(0.1, min(1.5, $temperature)),
    'provider' => 'local',
    'model_override' => '',
  ];
}

function coreui_ai_external_capability(PDO $pdo): array {
  $enabled = function_exists('stu_app_setting_get')
    && stu_app_setting_get($pdo, 'external_api_enabled', '0') === '1';
  $label = function_exists('stu_app_setting_get')
    ? trim((string)stu_app_setting_get($pdo, 'external_api_label', 'OpenAI-kompatibel'))
    : 'OpenAI-kompatibel';
  $baseUrl = function_exists('stu_app_setting_get')
    ? trim((string)stu_app_setting_get($pdo, 'external_api_base_url', ''))
    : '';
  $model = function_exists('stu_app_setting_get')
    ? trim((string)stu_app_setting_get($pdo, 'external_api_model', ''))
    : '';
  $keyConfigured = defined('STU_EXTERNAL_API_KEY')
    && trim((string)constant('STU_EXTERNAL_API_KEY')) !== '';

  return [
    // 0.3.0-alpha stores the operator configuration but deliberately keeps the
    // selector locked until the provider adapter is released and regression-tested.
    'configured' => false,
    'configuration_complete' => $enabled && $baseUrl !== '' && $model !== '' && $keyConfigured,
    'enabled' => $enabled,
    'label' => $label !== '' ? $label : 'OpenAI-kompatibel',
    'base_url' => $baseUrl,
    'model' => $model,
    'key_configured' => $keyConfigured,
    'status' => 'adapter_reserved',
  ];
}

function coreui_ai_settings_normalise(PDO $pdo, array $input): array {
  $defaults = coreui_ai_settings_defaults($pdo);
  $prompt = trim((string)($input['system_prompt'] ?? $defaults['system_prompt']));
  $promptLimit = coreui_ai_prompt_limit($pdo);
  if (function_exists('mb_substr')) {
    $prompt = mb_substr($prompt, 0, $promptLimit, 'UTF-8');
  } else {
    $prompt = substr($prompt, 0, $promptLimit);
  }
  // Keep control bytes out of the model instruction block while retaining
  // line breaks, which are useful for structured prompts.
  $prompt = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $prompt);

  $provider = strtolower(trim((string)($input['provider'] ?? $defaults['provider'])));
  if (!in_array($provider, ['local', 'external'], true)) $provider = 'local';
  if ($provider === 'external' && empty(coreui_ai_external_capability($pdo)['configured'])) {
    $provider = 'local';
  }

  $model = trim((string)($input['model_override'] ?? $defaults['model_override']));
  $model = preg_replace('~[^A-Za-z0-9._:/-]+~', '', $model);
  if (strlen((string)$model) > 160) $model = substr((string)$model, 0, 160);

  return [
    'system_prompt' => trim((string)$prompt),
    'memory_enabled' => filter_var(
      $input['memory_enabled'] ?? $defaults['memory_enabled'],
      FILTER_VALIDATE_BOOLEAN,
      FILTER_NULL_ON_FAILURE
    ) ?? (bool)$defaults['memory_enabled'],
    'memory_limit' => max(0, min(60, (int)($input['memory_limit'] ?? $defaults['memory_limit']))),
    'num_predict' => max(256, min(16384, (int)($input['num_predict'] ?? $defaults['num_predict']))),
    'temperature' => max(0.1, min(1.5, (float)($input['temperature'] ?? $defaults['temperature']))),
    'provider' => $provider,
    'model_override' => (string)$model,
  ];
}

function coreui_ai_settings_load(PDO $pdo, int $uid): array {
  $defaults = coreui_ai_settings_defaults($pdo);
  if ($uid <= 0 || !coreui_ai_settings_schema_ready($pdo)) return $defaults;

  try {
    $st = $pdo->prepare(
      'SELECT system_prompt, memory_enabled, memory_limit, num_predict, temperature, provider, model_override '
      . 'FROM stu_user_ai_settings WHERE user_id = ? LIMIT 1'
    );
    $st->execute([$uid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) return $defaults;
    return coreui_ai_settings_normalise($pdo, array_merge($defaults, $row));
  } catch (Throwable $e) {
    return $defaults;
  }
}

function coreui_ai_settings_save(PDO $pdo, int $uid, array $input): array {
  if ($uid <= 0) throw new InvalidArgumentException('invalid_user');
  if (!coreui_ai_settings_schema_ready($pdo)) {
    throw new RuntimeException('missing_schema_002');
  }

  $value = coreui_ai_settings_normalise($pdo, $input);
  $st = $pdo->prepare(
    'INSERT INTO stu_user_ai_settings '
    . '(user_id, system_prompt, memory_enabled, memory_limit, num_predict, temperature, provider, model_override, updated_at) '
    . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW()) '
    . 'ON DUPLICATE KEY UPDATE system_prompt = VALUES(system_prompt), memory_enabled = VALUES(memory_enabled), '
    . 'memory_limit = VALUES(memory_limit), num_predict = VALUES(num_predict), temperature = VALUES(temperature), '
    . 'provider = VALUES(provider), model_override = VALUES(model_override), updated_at = NOW()'
  );
  $st->execute([
    $uid,
    $value['system_prompt'],
    $value['memory_enabled'] ? 1 : 0,
    $value['memory_limit'],
    $value['num_predict'],
    $value['temperature'],
    $value['provider'],
    $value['model_override'] !== '' ? $value['model_override'] : null,
  ]);
  return $value;
}

function coreui_operator_runtime_load(PDO $pdo): array {
  $defaults = [
    'web' => defined('STU_EMBER_WEB_ENABLED') ? (bool)STU_EMBER_WEB_ENABLED : true,
    'browse' => defined('STU_EMBER_BROWSE_ENABLED') ? (bool)STU_EMBER_BROWSE_ENABLED : true,
    'python' => defined('STU_EMBER_PY_ENABLED') ? (bool)STU_EMBER_PY_ENABLED : true,
  ];
  if (!function_exists('stu_app_setting_get')) return $defaults;
  return [
    'web' => stu_app_setting_get($pdo, 'tool_web_enabled', $defaults['web'] ? '1' : '0') === '1',
    'browse' => stu_app_setting_get($pdo, 'tool_browse_enabled', $defaults['browse'] ? '1' : '0') === '1',
    'python' => stu_app_setting_get($pdo, 'tool_python_enabled', $defaults['python'] ? '1' : '0') === '1',
  ];
}

function coreui_ai_runtime_apply(PDO $pdo, int $uid): array {
  $settings = coreui_ai_settings_load($pdo, $uid);
  $GLOBALS['COREUI_AI_RUNTIME_SETTINGS'] = $settings;
  $GLOBALS['COREUI_OPERATOR_RUNTIME_SETTINGS'] = coreui_operator_runtime_load($pdo);
  return $settings;
}

function coreui_ai_runtime_settings(): array {
  $value = $GLOBALS['COREUI_AI_RUNTIME_SETTINGS'] ?? [];
  return is_array($value) ? $value : [];
}

function coreui_runtime_tool_enabled(string $tool, bool $fallback): bool {
  $settings = $GLOBALS['COREUI_OPERATOR_RUNTIME_SETTINGS'] ?? [];
  if (!is_array($settings) || !array_key_exists($tool, $settings)) return $fallback;
  return (bool)$settings[$tool];
}

function coreui_ai_user_prompt_block(): string {
  $settings = coreui_ai_runtime_settings();
  $prompt = trim((string)($settings['system_prompt'] ?? ''));
  if ($prompt === '') return '';
  return "\n\n--- BENUTZERKONFIGURATION (Stil und Arbeitsweise) ---\n"
    . $prompt
    . "\n--- ENDE BENUTZERKONFIGURATION ---";
}
