<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/console_session_store.php';

// -----------------------------------------------------------------------------
// STU Console - Datei-Upload (v1.1.1.89)
//
// Bilder gehen weiterhin nach www/assets/chat_media, damit der bestehende
// Vision-Pfad (image_url -> ember_call_ollama) unveraendert greift.
//
// Alles andere (Dokumente, Archive, Video, Audio) landet in
// var/console_media innerhalb des Projekts. Nginx liefert diesen Ordner
// nicht direkt aus. Zugriff ausschliesslich ueber console_media.php mit
// Session-Pruefung. Damit gibt es keine ratbare Direkt-URL auf hochgeladene
// Dokumente oder Archive.
// -----------------------------------------------------------------------------

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$pdo    = stu_pdo();
$uid    = stu_get_user_id();

if (!$uid) stu_json(['ok'=>false,'error'=>'not_authenticated'], 401);
if ($method !== 'POST') stu_json(['ok'=>false,'error'=>'method_not_allowed'], 405);

// Ein vor dem Senden bewusst entfernter Upload kann sofort aufgeraeumt werden.
// Sobald irgendeine Nachricht die UUID referenziert, ist die Loeschung gesperrt.
$contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
if (str_contains($contentType, 'application/json')) {
  $deleteBody = stu_read_json_body();
  if (($deleteBody['action'] ?? '') === 'delete_unreferenced') {
    stu_require_csrf();
    $uuid = strtolower(trim((string)($deleteBody['uuid'] ?? '')));
    if (!preg_match('~^[a-f0-9]{32}$~', $uuid)) {
      stu_json(['ok'=>false,'error'=>'invalid_uuid'], 400);
    }

    $relativePath = null;
    $deleted = false;
    $pdo->beginTransaction();
    try {
      $stMedia = $pdo->prepare('SELECT rel_path FROM stu_console_media WHERE uuid=? AND user_id=? LIMIT 1 FOR UPDATE');
      $stMedia->execute([$uuid, (int)$uid]);
      $relativePath = $stMedia->fetchColumn();
      if (!is_string($relativePath) || $relativePath === '') {
        $pdo->rollBack();
        stu_json(['ok'=>true,'deleted'=>false,'missing'=>true]);
      }

      $referenceCount = 0;
      $stLegacy = $pdo->prepare('SELECT COUNT(*) FROM stu_chat_messages WHERE file_uuid=?');
      $stLegacy->execute([$uuid]);
      $referenceCount += (int)$stLegacy->fetchColumn();
      if (coreui_console_attachment_schema_ready($pdo)) {
        $stReferences = $pdo->prepare('SELECT COUNT(*) FROM stu_console_message_attachments WHERE media_uuid=?');
        $stReferences->execute([$uuid]);
        $referenceCount += (int)$stReferences->fetchColumn();
      }
      if ($referenceCount > 0) {
        $pdo->rollBack();
        stu_json(['ok'=>false,'error'=>'attachment_in_use'], 409);
      }

      $stDelete = $pdo->prepare('DELETE FROM stu_console_media WHERE uuid=? AND user_id=? LIMIT 1');
      $stDelete->execute([$uuid, (int)$uid]);
      $deleted = $stDelete->rowCount() === 1;
      $pdo->commit();
    } catch (Throwable $eDelete) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $eDelete;
    }
    $files = coreui_console_session_delete_media_files([(string)$relativePath]);
    stu_json(['ok'=>true,'deleted'=>$deleted] + $files);
  }
}

// --- Limits ------------------------------------------------------------------
const CONSOLE_MAX_IMAGE_BYTES = 8   * 1024 * 1024;   // 8 MB
const CONSOLE_MAX_FILE_BYTES  = 256 * 1024 * 1024;   // 256 MB

// Bewusst unter post_max_size (512M): der Multipart-Overhead zaehlt mit, ein
// Upload am absoluten Limit bricht sonst kommentarlos ab, bevor PHP ihn sieht.

function console_media_dir(): string {
  return dirname(__DIR__) . '/var/console_media';
}

function console_media_kind(string $mime, string $ext): string {
  if (in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) return 'image';
  if (str_starts_with($mime, 'video/')) return 'video';
  if (str_starts_with($mime, 'audio/')) return 'audio';
  if (in_array($mime, ['application/zip','application/x-zip-compressed','application/x-tar',
                       'application/gzip','application/x-7z-compressed','application/x-rar-compressed',
                       'application/vnd.rar'], true)) return 'archive';
  if (in_array($ext, ['zip','tar','gz','tgz','7z','rar'], true)) return 'archive';
  return 'document';
}

function console_media_ensure_schema(PDO $pdo): void {
  try {
    $pdo->exec(
      "CREATE TABLE IF NOT EXISTS stu_console_media (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        uuid VARCHAR(64) NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        character_id VARCHAR(64) NULL,
        kind VARCHAR(16) NOT NULL DEFAULT 'document',
        orig_name VARCHAR(255) NOT NULL,
        stored_name VARCHAR(255) NOT NULL,
        rel_path VARCHAR(512) NOT NULL,
        public_url VARCHAR(512) NULL,
        mime_type VARCHAR(128) NOT NULL,
        file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_uuid (uuid),
        KEY idx_user (user_id, id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
  } catch (Throwable $e) {
    if (function_exists('stu__log_error')) {
      stu__log_error(['type'=>'console_media_schema_fail','message'=>$e->getMessage()]);
    }
  }
}

// --- Upload-Fehler sauber uebersetzen ----------------------------------------
// Ohne diese Uebersetzung sieht der Nutzer bei einem zu grossen Video nur
// "kein Upload" und sucht den Fehler an der falschen Stelle.
if (empty($_FILES['file'])) {
  $postMax = (string)ini_get('post_max_size');
  if (($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 && empty($_POST)) {
    stu_json(['ok'=>false,'error'=>'post_too_large','post_max_size'=>$postMax], 413);
  }
  stu_json(['ok'=>false,'error'=>'no_file_uploaded'], 400);
}

$file = $_FILES['file'];
$err  = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
if ($err !== UPLOAD_ERR_OK) {
  $map = [
    UPLOAD_ERR_INI_SIZE   => 'file_too_large_ini',
    UPLOAD_ERR_FORM_SIZE  => 'file_too_large_form',
    UPLOAD_ERR_PARTIAL    => 'upload_incomplete',
    UPLOAD_ERR_NO_FILE    => 'no_file_uploaded',
    UPLOAD_ERR_NO_TMP_DIR => 'no_tmp_dir',
    UPLOAD_ERR_CANT_WRITE => 'cannot_write',
    UPLOAD_ERR_EXTENSION  => 'blocked_by_extension',
  ];
  stu_json(['ok'=>false,'error'=>$map[$err] ?? 'upload_failed','php_code'=>$err], 400);
}

$size = (int)($file['size'] ?? 0);
if ($size <= 0) stu_json(['ok'=>false,'error'=>'empty_file'], 400);

// --- Typ bestimmen (finfo, nicht die Dateiendung) ----------------------------
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = (string)$finfo->file($file['tmp_name']);
if ($mime === '') $mime = 'application/octet-stream';

$origName = (string)($file['name'] ?? 'datei');
$origName = preg_replace('~[\x00-\x1F\x7F/\\\\]+~u', '', $origName);
$origName = trim(mb_substr($origName, 0, 200, 'UTF-8'));
if ($origName === '') $origName = 'datei';

$ext = strtolower((string)pathinfo($origName, PATHINFO_EXTENSION));
$ext = preg_replace('~[^a-z0-9]~', '', $ext);
$kind = console_media_kind($mime, $ext);

$limit = ($kind === 'image') ? CONSOLE_MAX_IMAGE_BYTES : CONSOLE_MAX_FILE_BYTES;
if ($size > $limit) {
  stu_json([
    'ok'=>false, 'error'=>'file_too_large',
    'max_mb'=>(int)round($limit / 1048576), 'kind'=>$kind,
  ], 400);
}

console_media_ensure_schema($pdo);

$uuid = bin2hex(random_bytes(16));
$imageExtensions = [
  'image/jpeg' => 'jpg',
  'image/png' => 'png',
  'image/gif' => 'gif',
  'image/webp' => 'webp',
];
$safeExt = ($kind === 'image' && isset($imageExtensions[$mime]))
  ? $imageExtensions[$mime]
  : ($ext !== '' ? $ext : 'bin');
$storedName = $uuid . '.' . $safeExt;

$charId = trim((string)($_POST['character_id'] ?? ''));
$publicUrl = null;

if ($kind === 'image') {
  // Bilder in den bestehenden oeffentlichen Medienordner, damit der Vision-Pfad
  // sie ueber eine normale URL erreichen kann.
  $dir = dirname(__DIR__) . '/assets/chat_media';
  if (!is_dir($dir)) @mkdir($dir, 0755, true);
  $dest = $dir . '/' . $storedName;
  if (!move_uploaded_file($file['tmp_name'], $dest)) {
    stu_json(['ok'=>false,'error'=>'move_failed'], 500);
  }
  @chmod($dest, 0644);
  $relPath   = 'assets/chat_media/' . $storedName;
  $publicUrl = stu_public_path('assets/chat_media/' . rawurlencode($storedName));
} else {
  $dir = console_media_dir();
  if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
    stu_json(['ok'=>false,'error'=>'storage_dir_unavailable','dir'=>'files/console_media'], 500);
  }
  if (!is_writable($dir)) {
    stu_json(['ok'=>false,'error'=>'storage_dir_not_writable','dir'=>'files/console_media'], 500);
  }
  $dest = $dir . '/' . $storedName;
  if (!move_uploaded_file($file['tmp_name'], $dest)) {
    stu_json(['ok'=>false,'error'=>'move_failed'], 500);
  }
  @chmod($dest, 0640);
  $relPath = 'var/console_media/' . $storedName;
}

try {
  $st = $pdo->prepare(
    "INSERT INTO stu_console_media
       (uuid, user_id, character_id, kind, orig_name, stored_name, rel_path, public_url, mime_type, file_size)
     VALUES (?,?,?,?,?,?,?,?,?,?)"
  );
  $st->execute([$uuid, $uid, ($charId !== '' ? $charId : null), $kind,
                $origName, $storedName, $relPath, $publicUrl, $mime, $size]);
} catch (Throwable $e) {
  @unlink($dest);
  if (function_exists('stu__log_error')) {
    stu__log_error(['type'=>'console_media_insert_fail','message'=>$e->getMessage()]);
  }
  stu_json(['ok'=>false,'error'=>'db_insert_failed'], 500);
}

if (function_exists('stu__log_error')) {
  stu__log_error(['type'=>'ember_console_upload','uuid'=>$uuid,'kind'=>$kind,
                  'mime'=>$mime,'bytes'=>$size,'name'=>$origName]);
}

stu_json([
  'ok'        => true,
  'uuid'      => $uuid,
  'kind'      => $kind,
  'name'      => $origName,
  'mime'      => $mime,
  'size'      => $size,
  'image_url' => $publicUrl,                                  // nur bei Bildern gesetzt
  'url'       => $publicUrl ?? stu_public_path('api/console_media.php?uuid=' . $uuid),
  'marker'    => '[file:' . $uuid . ']',
]);
