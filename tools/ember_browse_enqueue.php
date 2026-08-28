<?php
/**
 * tools/ember_browse_enqueue.php - CLI: einen Browse-Job in die Queue legen (Phase-1-Test).
 *
 * Aufruf:
 *   php tools/ember_browse_enqueue.php --goal="wie ist das wetter in moenchengladbach" --url="https://duckduckgo.com"
 *   php tools/ember_browse_enqueue.php --goal="..." --max-steps=10
 *
 * Liest STU_DB_* aus api/config.local.php (eine Quelle der Wahrheit). Nur CLI.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Nur ueber die CLI ausfuehren.\n");
    exit(1);
}

$configPath = __DIR__ . '/../api/config.local.php';
if (!is_file($configPath)) {
    fwrite(STDERR, "config.local.php nicht gefunden unter $configPath\n");
    exit(1);
}
require_once $configPath;

$opts = getopt('', ['goal:', 'url::', 'max-steps::']);
$goal = trim((string)($opts['goal'] ?? ''));
if ($goal === '') {
    fwrite(STDERR, "Fehlt: --goal=\"<was soll Ember herausfinden>\"\n");
    exit(1);
}
$url = trim((string)($opts['url'] ?? ''));
$url = $url !== '' ? $url : null;
$maxSteps = (int)($opts['max-steps'] ?? 12);
if ($maxSteps < 1 || $maxSteps > 40) {
    $maxSteps = 12;
}

try {
    $dsn = 'mysql:host=' . STU_DB_HOST . ';dbname=' . STU_DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, STU_DB_USER, STU_DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS stu_ember_browse_jobs (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            status ENUM('queued','running','done','error') NOT NULL DEFAULT 'queued',
            goal TEXT NOT NULL,
            start_url VARCHAR(2048) NULL,
            max_steps INT NOT NULL DEFAULT 12,
            channel VARCHAR(32) NOT NULL DEFAULT 'global',
            recipient_uid INT NULL,
            session_id VARCHAR(40) NULL,
            trigger_message_id BIGINT UNSIGNED NULL,
            trigger_user_id INT NULL,
            result MEDIUMTEXT NULL,
            steps_json MEDIUMTEXT NULL,
            screenshot_path VARCHAR(512) NULL,
            error TEXT NULL,
            worker_pid INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            started_at DATETIME NULL,
            finished_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY idx_status (status, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $st = $pdo->prepare(
        "INSERT INTO stu_ember_browse_jobs (status, goal, start_url, max_steps)
         VALUES ('queued', ?, ?, ?)"
    );
    $st->execute([$goal, $url, $maxSteps]);
    $id = (int)$pdo->lastInsertId();

    echo "Job #$id eingereiht.\n";
    echo "  Ziel:      $goal\n";
    echo "  Start-URL: " . ($url ?? '(auto: duckduckgo)') . "\n";
    echo "  Max-Steps: $maxSteps\n";
    echo "Worker starten:  python3 tools/ember_browse_worker.py --once\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Fehler: " . $e->getMessage() . "\n");
    exit(1);
}
