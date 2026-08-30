<?php
declare(strict_types=1);

function coreui_profile_schema_ready(PDO $pdo): bool {
  try {
    $pdo->query('SELECT user_id, display_name, assistant_name FROM stu_coreui_profiles LIMIT 1');
    $pdo->query('SELECT uuid, user_id, slot FROM stu_coreui_profile_media LIMIT 1');
    return true;
  } catch (Throwable $e) {
    return false;
  }
}

function coreui_profile_name(string $value, string $fallback): string {
  $value = trim((string)preg_replace('~\s+~u', ' ', $value));
  $value = (string)preg_replace('/[\x00-\x1F\x7F]/u', '', $value);
  $value = trim((string)preg_replace("~[^\p{L}\p{N} ._'’()\\-]+~u", '', $value));
  if ($value === '') $value = $fallback;
  return function_exists('mb_substr')
    ? mb_substr($value, 0, 64, 'UTF-8')
    : substr($value, 0, 64);
}

function coreui_profile_fallback_display(string $username): string {
  $local = trim((string)strtok($username, '@'));
  $local = str_replace(['.', '_', '-'], ' ', $local);
  $local = trim((string)preg_replace('~\s+~u', ' ', $local));
  return coreui_profile_name($local !== '' ? ucwords($local) : 'Operator', 'Operator');
}

function coreui_profile_load(PDO $pdo, int $uid): array {
  if ($uid <= 0) throw new InvalidArgumentException('invalid_user');
  $stUser = $pdo->prepare('SELECT username FROM stu_users WHERE id = ? LIMIT 1');
  $stUser->execute([$uid]);
  $username = (string)($stUser->fetchColumn() ?: '');
  $displayName = coreui_profile_fallback_display($username);
  $assistantName = 'Ember';
  $updatedAt = null;

  if (coreui_profile_schema_ready($pdo)) {
    $st = $pdo->prepare('SELECT display_name, assistant_name, updated_at FROM stu_coreui_profiles WHERE user_id = ? LIMIT 1');
    $st->execute([$uid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) {
      $displayName = coreui_profile_name((string)($row['display_name'] ?? ''), $displayName);
      $assistantName = coreui_profile_name((string)($row['assistant_name'] ?? ''), 'Ember');
      $updatedAt = $row['updated_at'] ?? null;
    }
  }

  $avatars = ['user' => null, 'assistant' => null];
  if (coreui_profile_schema_ready($pdo)) {
    $stMedia = $pdo->prepare('SELECT slot, uuid, created_at FROM stu_coreui_profile_media WHERE user_id = ?');
    $stMedia->execute([$uid]);
    foreach ($stMedia->fetchAll(PDO::FETCH_ASSOC) ?: [] as $media) {
      $slot = (string)($media['slot'] ?? '');
      if (!array_key_exists($slot, $avatars)) continue;
      // Die zufaellige Medien-ID ist ein genauer Cache-Buster, auch wenn zwei
      // Uploads innerhalb derselben DATETIME-Sekunde stattfinden.
      $version = (string)($media['uuid'] ?? '');
      $avatars[$slot] = stu_public_path(
        'api/profile_media.php?slot=' . rawurlencode($slot) . '&v=' . rawurlencode($version)
      );
    }
  }

  return [
    'username' => $username,
    'display_name' => $displayName,
    'assistant_name' => $assistantName,
    'avatars' => $avatars,
    'updated_at' => $updatedAt,
  ];
}

function coreui_profile_save(PDO $pdo, int $uid, string $displayName, string $assistantName): array {
  if (!coreui_profile_schema_ready($pdo)) throw new RuntimeException('missing_schema_004');
  $displayName = coreui_profile_name($displayName, 'Operator');
  $assistantName = coreui_profile_name($assistantName, 'Ember');
  $st = $pdo->prepare(
    'INSERT INTO stu_coreui_profiles (user_id, display_name, assistant_name, created_at, updated_at) '
    . 'VALUES (?, ?, ?, NOW(), NOW()) '
    . 'ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), assistant_name = VALUES(assistant_name), updated_at = NOW()'
  );
  $st->execute([$uid, $displayName, $assistantName]);
  return coreui_profile_load($pdo, $uid);
}

function coreui_profile_media_dir(): string {
  return dirname(__DIR__) . '/var/profile_media';
}

function coreui_profile_delete_avatar(PDO $pdo, int $uid, string $slot): bool {
  if (!in_array($slot, ['user', 'assistant'], true)) throw new InvalidArgumentException('invalid_slot');
  if (!coreui_profile_schema_ready($pdo)) throw new RuntimeException('missing_schema_004');
  $st = $pdo->prepare('SELECT stored_name FROM stu_coreui_profile_media WHERE user_id = ? AND slot = ? LIMIT 1');
  $st->execute([$uid, $slot]);
  $stored = (string)($st->fetchColumn() ?: '');
  $del = $pdo->prepare('DELETE FROM stu_coreui_profile_media WHERE user_id = ? AND slot = ?');
  $del->execute([$uid, $slot]);
  if ($stored !== '') @unlink(coreui_profile_media_dir() . '/' . basename($stored));
  return $del->rowCount() > 0;
}

function coreui_profile_store_avatar(PDO $pdo, int $uid, string $slot, array $file): array {
  if (!in_array($slot, ['user', 'assistant'], true)) throw new InvalidArgumentException('invalid_slot');
  if (!coreui_profile_schema_ready($pdo)) throw new RuntimeException('missing_schema_004');
  $size = (int)($file['size'] ?? 0);
  $tmp = (string)($file['tmp_name'] ?? '');
  if ($size <= 0 || $tmp === '' || !is_uploaded_file($tmp)) throw new InvalidArgumentException('invalid_upload');
  if ($size > 4 * 1024 * 1024) throw new InvalidArgumentException('avatar_too_large');

  $finfo = new finfo(FILEINFO_MIME_TYPE);
  $mime = (string)$finfo->file($tmp);
  if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
    throw new InvalidArgumentException('avatar_format_not_allowed');
  }
  $info = @getimagesize($tmp);
  $width = (int)($info[0] ?? 0);
  $height = (int)($info[1] ?? 0);
  if ($width < 32 || $height < 32 || $width > 8000 || $height > 8000 || ($width * $height) > 40000000) {
    throw new InvalidArgumentException('avatar_dimensions_invalid');
  }
  $raw = @file_get_contents($tmp);
  $src = is_string($raw) ? @imagecreatefromstring($raw) : false;
  if ($src === false) throw new InvalidArgumentException('avatar_decode_failed');

  $side = min($width, $height);
  $srcX = (int)floor(($width - $side) / 2);
  $srcY = (int)floor(($height - $side) / 2);
  $target = min(512, $side);
  $dst = imagecreatetruecolor($target, $target);
  imagealphablending($dst, false);
  imagesavealpha($dst, true);
  $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
  imagefill($dst, 0, 0, $transparent);
  imagecopyresampled($dst, $src, 0, 0, $srcX, $srcY, $target, $target, $side, $side);
  imagedestroy($src);

  $dir = coreui_profile_media_dir();
  if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
    imagedestroy($dst);
    throw new RuntimeException('profile_storage_unavailable');
  }
  if (!is_writable($dir)) {
    imagedestroy($dst);
    throw new RuntimeException('profile_storage_not_writable');
  }

  $uuid = bin2hex(random_bytes(16));
  $storedName = $uuid . '.png';
  $tempName = '.' . $storedName . '.tmp';
  $tempPath = $dir . '/' . $tempName;
  $destPath = $dir . '/' . $storedName;
  $written = imagepng($dst, $tempPath, 6);
  imagedestroy($dst);
  if (!$written || !@rename($tempPath, $destPath)) {
    @unlink($tempPath);
    throw new RuntimeException('avatar_write_failed');
  }
  @chmod($destPath, 0640);
  $finalSize = (int)(@filesize($destPath) ?: 0);
  $originalName = trim((string)preg_replace('~[\x00-\x1F\x7F/\\\\]+~u', '', (string)($file['name'] ?? 'avatar')));
  if ($originalName === '') $originalName = 'avatar';
  if (function_exists('mb_substr')) $originalName = mb_substr($originalName, 0, 255, 'UTF-8');
  else $originalName = substr($originalName, 0, 255);

  $oldStored = '';
  try {
    $pdo->beginTransaction();
    $stOld = $pdo->prepare('SELECT stored_name FROM stu_coreui_profile_media WHERE user_id = ? AND slot = ? FOR UPDATE');
    $stOld->execute([$uid, $slot]);
    $oldStored = (string)($stOld->fetchColumn() ?: '');
    $st = $pdo->prepare(
      'INSERT INTO stu_coreui_profile_media '
      . '(uuid, user_id, slot, original_name, stored_name, mime_type, file_size, width_px, height_px, created_at) '
      . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()) '
      . 'ON DUPLICATE KEY UPDATE uuid = VALUES(uuid), original_name = VALUES(original_name), '
      . 'stored_name = VALUES(stored_name), mime_type = VALUES(mime_type), file_size = VALUES(file_size), '
      . 'width_px = VALUES(width_px), height_px = VALUES(height_px), created_at = NOW()'
    );
    $st->execute([$uuid, $uid, $slot, $originalName, $storedName, 'image/png', $finalSize, $target, $target]);
    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    @unlink($destPath);
    throw $e;
  }
  if ($oldStored !== '' && $oldStored !== $storedName) @unlink($dir . '/' . basename($oldStored));
  return coreui_profile_load($pdo, $uid);
}

function coreui_profile_prompt_block(PDO $pdo, int $uid): string {
  if ($uid <= 0 || !coreui_profile_schema_ready($pdo)) return '';
  try {
    $profile = coreui_profile_load($pdo, $uid);
    $assistantName = coreui_profile_name((string)($profile['assistant_name'] ?? ''), 'Ember');
    $displayName = coreui_profile_name((string)($profile['display_name'] ?? ''), 'Operator');
    return "\n\n--- EMBER COREUI-PROFIL ---\n"
      . 'Der aktuelle Benutzer wird in dieser privaten Oberfläche als "' . $displayName . '" angezeigt. ' 
      . 'Dein frei gewählter Anzeigename in diesem Konto ist "' . $assistantName . '". '
      . 'Deine feste technische Identität und alle Sicherheitsregeln bleiben unverändert.\n'
      . "--- ENDE EMBER COREUI-PROFIL ---";
  } catch (Throwable $e) {
    return '';
  }
}
