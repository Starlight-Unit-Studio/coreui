<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
  fwrite(STDERR, "CLI only\n");
  exit(1);
}

require_once __DIR__ . '/../api/db.php';

$pdo = stu_pdo();
$token = 'EMBER_PY_WORKER_' . bin2hex(random_bytes(6));
$jobId = 0;
$exitCode = 0;

try {
  $pdo->query('SELECT id,status,stdout,error FROM stu_ember_py_jobs LIMIT 0');
  $st = $pdo->prepare(
    "INSERT INTO stu_ember_py_jobs (status,code,channel,trigger_user_id,recipient_uid,created_at) "
    . "VALUES ('queued',?,'console',NULL,NULL,NOW())"
  );
  $st->execute(['print(' . var_export($token, true) . ')']);
  $jobId = (int)$pdo->lastInsertId();
  if ($jobId <= 0) throw new RuntimeException('Testjob konnte nicht angelegt werden.');

  $select = $pdo->prepare(
    'SELECT status,stdout,stderr,exit_code,error FROM stu_ember_py_jobs WHERE id=? LIMIT 1'
  );
  $deadline = microtime(true) + 180.0;
  $row = null;
  while (microtime(true) < $deadline) {
    usleep(500000);
    $select->execute([$jobId]);
    $row = $select->fetch(PDO::FETCH_ASSOC);
    if (is_array($row) && in_array((string)($row['status'] ?? ''), ['done', 'error'], true)) break;
  }
  if (!is_array($row)) throw new RuntimeException('Testjob ist verschwunden.');
  if (($row['status'] ?? '') !== 'done') {
    throw new RuntimeException('Workerfehler: ' . (string)($row['error'] ?? $row['stderr'] ?? 'timeout'));
  }
  if ((int)($row['exit_code'] ?? -1) !== 0 || !str_contains((string)($row['stdout'] ?? ''), $token)) {
    throw new RuntimeException('Sandbox-Ausgabe ist unvollstaendig.');
  }
  echo 'Python-Worker-Selftest OK: Queue, Worker und isolierter Sandbox-Container funktionieren.' . "\n";
} catch (Throwable $e) {
  fwrite(STDERR, 'Python-Worker-Selftest FEHLER: ' . $e->getMessage() . "\n");
  $exitCode = 1;
} finally {
  if ($jobId > 0) {
    try {
      $pdo->prepare('DELETE FROM stu_ember_py_jobs WHERE id=?')->execute([$jobId]);
    } catch (Throwable $e) {}
  }
}

exit($exitCode);
