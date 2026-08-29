<?php
declare(strict_types=1);

/**
 * Read-only Ollama model catalog for Ember CoreUI.
 *
 * The endpoint is derived exclusively from the operator-controlled Ollama
 * chat URL. User input can never select a host or protocol.
 */

function coreui_model_name_valid(string $name): bool {
  return $name !== ''
    && strlen($name) <= 160
    && preg_match('~^[A-Za-z0-9][A-Za-z0-9._:/-]*$~D', $name) === 1;
}

function coreui_ollama_tags_url(): string {
  $chatUrl = defined('STU_EMBER_OLLAMA_URL')
    ? trim((string)STU_EMBER_OLLAMA_URL)
    : 'http://127.0.0.1:11434/api/chat';
  if (!preg_match('~^https?://~i', $chatUrl)) throw new RuntimeException('model_catalog_invalid_url');
  $tagsUrl = preg_replace('~/api/chat/?(?:\?.*)?$~i', '/api/tags', $chatUrl, 1, $count);
  if ($count !== 1 || !is_string($tagsUrl)) throw new RuntimeException('model_catalog_invalid_url');
  return $tagsUrl;
}

function coreui_ollama_models(): array {
  if (!function_exists('curl_init')) throw new RuntimeException('model_catalog_unavailable');
  $ch = curl_init(coreui_ollama_tags_url());
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 2,
    CURLOPT_TIMEOUT => 8,
    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
    CURLOPT_HTTPHEADER => ['Accept: application/json'],
  ]);
  $raw = curl_exec($ch);
  $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  $error = curl_error($ch);
  curl_close($ch);
  if (!is_string($raw) || $error !== '' || $status < 200 || $status >= 300) {
    throw new RuntimeException('model_catalog_unavailable');
  }
  $decoded = json_decode($raw, true);
  if (!is_array($decoded) || !is_array($decoded['models'] ?? null)) {
    throw new RuntimeException('model_catalog_invalid_response');
  }
  $models = [];
  foreach ($decoded['models'] as $row) {
    if (!is_array($row)) continue;
    $name = trim((string)($row['name'] ?? $row['model'] ?? ''));
    if (!coreui_model_name_valid($name)) continue;
    $details = is_array($row['details'] ?? null) ? $row['details'] : [];
    $models[$name] = [
      'name' => $name,
      'size' => max(0, (int)($row['size'] ?? 0)),
      'modified_at' => isset($row['modified_at']) ? (string)$row['modified_at'] : null,
      'family' => isset($details['family']) ? (string)$details['family'] : null,
      'parameter_size' => isset($details['parameter_size']) ? (string)$details['parameter_size'] : null,
      'quantization_level' => isset($details['quantization_level']) ? (string)$details['quantization_level'] : null,
    ];
  }
  ksort($models, SORT_NATURAL | SORT_FLAG_CASE);
  return array_values($models);
}

function coreui_ollama_model_exists(string $name): bool {
  if (!coreui_model_name_valid($name)) return false;
  foreach (coreui_ollama_models() as $model) {
    if (hash_equals((string)$model['name'], $name)) return true;
  }
  return false;
}
