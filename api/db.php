<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function stu_pdo(): PDO {
  static $pdo = null;
  if ($pdo instanceof PDO) return $pdo;

  $dsn = 'mysql:host=' . STU_DB_HOST
    . ';port=' . (int)STU_DB_PORT
    . ';dbname=' . STU_DB_NAME
    . ';charset=utf8mb4';

  $pdo = new PDO($dsn, STU_DB_USER, STU_DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::ATTR_TIMEOUT => 5,
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
  ]);

  return $pdo;
}

function db(): PDO {
  return stu_pdo();
}
