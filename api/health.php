<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$checks = [
  'database' => false,
  'schema' => false,
  'ollama' => false,
  'storage' => false,
];

try {
  $pdo = stu_pdo();
  $checks['database'] = ((int)$pdo->query('SELECT 1')->fetchColumn() === 1);
  $required = [
    'stu_users',
    'stu_characters',
    'stu_kv',
    'stu_chat_messages',
    'stu_console_sessions',
    'ember_memories',
    'ember_knowledge_chunks',
    'stu_user_ai_settings',
    'stu_admin_audit',
  ];
  $missing = [];
  foreach ($required as $table) {
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?');
    $st->execute([STU_DB_NAME, $table]);
    if ((int)$st->fetchColumn() !== 1) $missing[] = $table;
  }
  if ($missing === []) {
    try {
      $pdo->query('SELECT last_message_id,last_read_message_id,archived_at FROM stu_console_sessions LIMIT 0');
      $pdo->query('SELECT session_id,reply_to_id FROM stu_chat_messages LIMIT 0');
      $pdo->query('SELECT session_id,trigger_message_id FROM stu_ember_browse_jobs LIMIT 0');
      $stMigration = $pdo->prepare('SELECT COUNT(*) FROM stu_schema_migrations WHERE version=?');
      $stMigration->execute(['003_console_sessions']);
      if ((int)$stMigration->fetchColumn() !== 1) $missing[] = 'migration_003_console_sessions';
    } catch (Throwable $eSchema) {
      $missing[] = 'console_session_columns';
    }
  }
  $checks['schema'] = ($missing === []);
} catch (Throwable $e) {
  $missing = ['database_unavailable'];
}

$ollamaBase = preg_replace('~/api/chat/?$~', '', (string)STU_EMBER_OLLAMA_URL);
if (is_string($ollamaBase) && $ollamaBase !== '' && function_exists('curl_init')) {
  $ch = curl_init(rtrim($ollamaBase, '/') . '/api/tags');
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 2,
    CURLOPT_TIMEOUT => 5,
  ]);
  $body = curl_exec($ch);
  $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  $checks['ollama'] = ($body !== false && $status >= 200 && $status < 300);
}

$projectRoot = dirname(__DIR__);
$writableDirs = [
  $projectRoot . '/logs',
  $projectRoot . '/var/console_media',
  $projectRoot . '/var/ember_py',
  $projectRoot . '/var/ember_frames',
  $projectRoot . '/var/pdf_pages',
  $projectRoot . '/uploads/ember_browse',
  $projectRoot . '/assets/chat_media',
  $projectRoot . '/assets/profile_photos/pending',
  $projectRoot . '/assets/profile_photos/approved',
];
$checks['storage'] = true;
foreach ($writableDirs as $dir) {
  if (!is_dir($dir) || !is_writable($dir)) $checks['storage'] = false;
}

$ok = !in_array(false, $checks, true);
http_response_code($ok ? 200 : 503);
echo json_encode([
  'ok' => $ok,
  'project' => 'Project STΛRLIɢHT: Ember CoreUI',
  'version' => '0.3.2-alpha',
  'model' => STU_EMBER_MODEL,
  'checks' => $checks,
  'missing_tables' => $missing ?? [],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
