<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
  fwrite(STDERR, "CLI only\n");
  exit(1);
}

require_once __DIR__ . '/../api/helpers.php';
require_once __DIR__ . '/../api/db.php';
require_once __DIR__ . '/../api/account_store.php';
require_once __DIR__ . '/../api/profile_store.php';
require_once __DIR__ . '/../api/knowledge_store.php';

function coreui_pk_assert(bool $condition, string $message): void {
  if (!$condition) throw new RuntimeException($message);
}

$pdo = stu_pdo();
coreui_pk_assert(coreui_profile_schema_ready($pdo), 'Migration 004 Profil-Schema fehlt.');
coreui_pk_assert(coreui_knowledge_schema_ready($pdo), 'Migration 004 Knowledge-Schema fehlt.');
$chatSource = (string)file_get_contents(__DIR__ . '/../api/chat.php');
coreui_pk_assert(
  substr_count($chatSource, "\$channel === 'console' && function_exists('coreui_private_knowledge_block')") >= 2,
  'Privates RAG-Lite ist nicht an beiden Promptpfaden hart auf den privaten Console-Kanal begrenzt.'
);

$sample = str_repeat(
  "Alpha Chronicle beschreibt einen privaten Testinhalt mit eindeutigen Fakten.\n\n"
  . "Der zweite Absatz prueft Chunk-Grenzen und Ueberlappung. ",
  40
);
$chunks = coreui_knowledge_chunks($sample, 500, 80, 100);
coreui_pk_assert(count($chunks) >= 4, 'RAG-Chunker erzeugt zu wenige Chunks.');
coreui_pk_assert(mb_strlen($chunks[0], 'UTF-8') <= 500, 'RAG-Chunker ueberschreitet das Zielbudget.');
$codePath = tempnam(sys_get_temp_dir(), 'coreui-rag-code-');
coreui_pk_assert(is_string($codePath) && $codePath !== '', 'RAG-Code-Testdatei konnte nicht angelegt werden.');
file_put_contents($codePath, "def private_rag_probe():\n    return 'PRIVATE_RAG_CODE_OK'\n");
$codeText = coreui_knowledge_extract($codePath, 'py', 4000);
@unlink($codePath);
coreui_pk_assert(str_contains($codeText, 'PRIVATE_RAG_CODE_OK'), 'Gemeinsamer RAG-Lite-Kern liest Python-Text nicht.');

$suffix = bin2hex(random_bytes(6));
$emailA = 'selftest-a-' . $suffix . '@coreui.invalid';
$emailB = 'selftest-b-' . $suffix . '@coreui.invalid';
$uuidA = bin2hex(random_bytes(16));
$uuidB = bin2hex(random_bytes(16));
$avatarPath = null;

$pdo->beginTransaction();
try {
  $userA = coreui_account_create($pdo, $emailA, 'Selftest-Passwort-123!', 'Alpha Operator', 4);
  $userB = coreui_account_create($pdo, $emailB, 'Selftest-Passwort-123!', 'Beta Operator', 4);
  $uidA = (int)$userA['user_id'];
  $uidB = (int)$userB['user_id'];
  coreui_pk_assert($uidA > 0 && $uidB > 0 && $uidA !== $uidB, 'Account-Provisioning ist nicht eindeutig.');
  coreui_pk_assert((string)$userA['character_id'] !== '', 'Neuer Benutzer hat keinen Operator.');

  $profile = coreui_profile_save($pdo, $uidA, 'Alpha Sichtbar', 'Nova Core');
  coreui_pk_assert(($profile['display_name'] ?? '') === 'Alpha Sichtbar', 'Benutzername wurde nicht gespeichert.');
  coreui_pk_assert(($profile['assistant_name'] ?? '') === 'Nova Core', 'CoreAI-Name wurde nicht gespeichert.');

  $avatarUuid = bin2hex(random_bytes(16));
  $avatarStoredName = $avatarUuid . '.png';
  $avatarPath = coreui_profile_media_dir() . '/' . $avatarStoredName;
  $avatar = imagecreatetruecolor(48, 48);
  coreui_pk_assert($avatar !== false, 'Avatar-Testbild konnte nicht erzeugt werden.');
  $avatarColor = imagecolorallocate($avatar, 91, 146, 255);
  imagefill($avatar, 0, 0, $avatarColor);
  $avatarWritten = imagepng($avatar, $avatarPath, 6);
  imagedestroy($avatar);
  coreui_pk_assert($avatarWritten && is_file($avatarPath), 'Avatar-Testbild konnte nicht gespeichert werden.');
  $pdo->prepare(
    'INSERT INTO stu_coreui_profile_media '
    . '(uuid,user_id,slot,original_name,stored_name,mime_type,file_size,width_px,height_px,created_at) '
    . 'VALUES (?,?,?,?,?,?,?,?,?,NOW())'
  )->execute([
    $avatarUuid, $uidA, 'user', 'selftest.png', $avatarStoredName, 'image/png',
    (int)filesize($avatarPath), 48, 48,
  ]);
  $profileWithAvatar = coreui_profile_load($pdo, $uidA);
  $avatarUrl = (string)($profileWithAvatar['avatars']['user'] ?? '');
  coreui_pk_assert(
    str_contains($avatarUrl, 'api/profile_media.php?slot=user') && str_contains($avatarUrl, $avatarUuid),
    'Profil-API liefert keinen eindeutigen Benutzerbild-Endpunkt.'
  );
  $avatarRecord = coreui_profile_media_record($pdo, $uidA, 'user');
  coreui_pk_assert(
    is_array($avatarRecord) && ($avatarRecord['path'] ?? '') === $avatarPath,
    'Gespeichertes Benutzerbild ist fuer den Auslieferungsendpunkt nicht lesbar.'
  );

  $stSource = $pdo->prepare(
    "INSERT INTO stu_user_knowledge_sources
       (uuid,user_id,title,original_name,stored_name,mime_type,file_size,char_count,chunk_count,status,created_at,updated_at)
     VALUES (?,?,?,?,?,'text/plain',100,100,1,'ready',NOW(),NOW())"
  );
  $stSource->execute([$uuidA, $uidA, 'Alpha Privat', 'alpha.txt', $uuidA . '.txt']);
  $stSource->execute([$uuidB, $uidB, 'Beta Privat', 'beta.txt', $uuidB . '.txt']);
  $stChunk = $pdo->prepare(
    'INSERT INTO stu_user_knowledge_chunks (source_uuid,user_id,title,chunk_no,chunk_text,created_at) VALUES (?,?,?,?,?,NOW())'
  );
  $stChunk->execute([$uuidA, $uidA, 'Alpha Privat', 0, 'alphachronicle ist ausschliesslich fuer Benutzer Alpha sichtbar.']);
  $stChunk->execute([$uuidB, $uidB, 'Beta Privat', 0, 'betachronicle ist ausschliesslich fuer Benutzer Beta sichtbar.']);

  $alphaRows = coreui_private_knowledge_search($pdo, $uidA, 'Was steht in alphachronicle?', 4);
  $betaRows = coreui_private_knowledge_search($pdo, $uidB, 'Was steht in alphachronicle?', 4);
  $genericRows = coreui_private_knowledge_search($pdo, $uidA, 'Fass meine hochgeladene Datei zusammen.', 4);
  coreui_pk_assert(count($alphaRows) === 1, 'Eigene RAG-Quelle wurde nicht gefunden.');
  coreui_pk_assert(count($betaRows) === 0, 'Private RAG-Quelle ist kontouebergreifend sichtbar.');
  coreui_pk_assert(count($genericRows) === 1, 'Die zuletzt hochgeladene eigene Quelle wird bei einer generischen Dokumentfrage nicht gefunden.');

  $pdo->rollBack();
  if (is_string($avatarPath)) @unlink($avatarPath);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  if (is_string($avatarPath)) @unlink($avatarPath);
  fwrite(STDERR, 'Profil/RAG-Selftest fehlgeschlagen: ' . $e->getMessage() . "\n");
  exit(2);
}

echo 'Profil/RAG-Selftest erfolgreich: Accounts, Profile, Avatar-Auslieferung, Chunker und Nutzerisolation geprueft.' . "\n";
