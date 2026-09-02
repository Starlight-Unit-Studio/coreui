<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
  fwrite(STDERR, "CLI only\n");
  exit(1);
}

$root = dirname(__DIR__);

function frontend_test_read(string $path): string {
  $content = @file_get_contents($path);
  if (!is_string($content) || $content === '') {
    throw new RuntimeException('Datei fehlt oder ist leer: ' . $path);
  }
  return $content;
}

function frontend_test_assert(bool $condition, string $message): void {
  if (!$condition) throw new RuntimeException($message);
}

try {
  $settings = frontend_test_read($root . '/js/settings.js');
  $admin = frontend_test_read($root . '/js/admin.js');
  $adminHtml = frontend_test_read($root . '/admin/index.html');
  $appHtml = frontend_test_read($root . '/app.html');
  $console = frontend_test_read($root . '/js/console-app.js');
  $markdown = frontend_test_read($root . '/js/coreui-markdown.js');
  $css = frontend_test_read($root . '/css/console.css');
  $profileStore = frontend_test_read($root . '/api/profile_store.php');
  $profileMedia = frontend_test_read($root . '/api/profile_media.php');

  frontend_test_assert(!str_contains($settings, 'history.back()'), 'Einstellungen verwenden weiterhin den Browser-Verlauf.');
  frontend_test_assert(str_contains($settings, "location.replace('app.html')"), 'Core Channel besitzt kein deterministisches Ziel.');
  frontend_test_assert(str_contains($adminHtml, 'id="adminBack"'), 'Admin-Zurueckschalter ist nicht eindeutig verdrahtet.');
  frontend_test_assert(
    str_contains($admin, "location.replace('../settings.html')") && str_contains($admin, 'event.preventDefault()'),
    'Admin Core hinterlaesst weiterhin einen problematischen Verlaufseintrag.'
  );

  foreach ([
    "image.className = 'msg-avatar-image'",
    "image.addEventListener('load'",
    "image.addEventListener('error'",
    "element.classList.add('has-image')",
  ] as $needle) {
    frontend_test_assert(str_contains($console, $needle), 'Chat-Avatar-Fallback fehlt: ' . $needle);
  }
  frontend_test_assert(!str_contains($console, 'element.style.backgroundImage'), 'Chat-Avatar wird weiterhin nur als CSS-Hintergrund geladen.');
  frontend_test_assert(
    str_contains($css, '.msg-avatar-image') && str_contains($css, '.msg-avatar.has-image .msg-avatar-image'),
    'CSS fuer das echte Chat-Avatarbild fehlt.'
  );
  frontend_test_assert(
    str_contains($profileStore, 'function coreui_profile_media_record')
      && str_contains($profileMedia, 'coreui_profile_media_record($pdo, $uid, $slot)'),
    'Profil-API und Medienendpunkt verwenden nicht dieselbe Dateipruefung.'
  );
  $markdownScriptPos = strpos($appHtml, 'js/coreui-markdown.js');
  $consoleScriptPos = strpos($appHtml, 'js/console-app.js');
  frontend_test_assert(
    $markdownScriptPos !== false && $consoleScriptPos !== false && $markdownScriptPos < $consoleScriptPos,
    'Markdown-Renderer wird nicht vor dem Chatclient geladen.'
  );
  frontend_test_assert(!str_contains($markdown, '.innerHTML'), 'Markdown-Renderer verwendet innerHTML.');
  frontend_test_assert(
    str_contains($console, 'buildMessageActions(role, text, record || null)')
      && str_contains($css, '.msg-actions')
      && str_contains($css, '.md-codeblock'),
    'Nachrichtenaktionen oder Codeblockdarstellung sind nicht vollstaendig verdrahtet.'
  );
  foreach ([
    "action: 'edit_message'",
    'state.pendingBySession[ses.id]',
    'queuedTurnsBySession',
    'drainQueuedTurn(sessionId)',
    'variantSelectionBySession',
    'msg-variant-nav',
  ] as $needle) {
    frontend_test_assert(
      str_contains($console, $needle),
      'Bearbeitung, Warteschlange oder Antwortvarianten fehlen: ' . $needle
    );
  }
  foreach ([
    'id="composerEditState"',
    'id="composerEditLabel"',
    'id="composerEditCancel"',
  ] as $needle) {
    frontend_test_assert(str_contains($appHtml, $needle), 'Bearbeitungsanzeige fehlt: ' . $needle);
  }
  frontend_test_assert(
    str_contains($css, '.composer-edit-state') && str_contains($css, '.msg-variant-nav'),
    'Darstellung fuer Bearbeitung oder Antwortvarianten fehlt.'
  );

  fwrite(STDOUT, "Frontend-Regressions-Selftest OK: Navigation, Avatare, Markdown, Bearbeitung, Warteschlange und Varianten sind fehlersicher verdrahtet.\n");
} catch (Throwable $e) {
  fwrite(STDERR, 'Frontend-Regressions-Selftest FEHLER: ' . $e->getMessage() . "\n");
  exit(2);
}
