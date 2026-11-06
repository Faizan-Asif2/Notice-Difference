<?php
// config/database.php
// Reads credentials from outside the web folder and returns a PDO connection.

// Store times in UTC; convert to Pakistan time only when displaying.
date_default_timezone_set('UTC');
define('APP_TIMEZONE', 'Asia/Karachi');

$secretsPath = '/home/faizan-asif/noticediff-secrets/db.env';
 if (!is_readable($secretsPath)) {
    $secretsPath = dirname(__DIR__) . '/.env';
 }
define('SECRETS_FILE', $secretsPath);

function loadEnv($path) {
    if (!is_readable($path)) {
        die('Configuration file is missing or not readable: ' . htmlspecialchars($path));
    }
    $values = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }
        $key = trim(substr($line, 0, $pos));
        $val = trim(substr($line, $pos + 1));
        // Strip surrounding quotes if present
        $len = strlen($val);
        if ($len >= 2) {
            $first = $val[0];
            $last  = $val[$len - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $val = substr($val, 1, -1);
            }
        }
        $values[$key] = $val;
    }
    return $values;
}

function getDB() {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    $env = loadEnv(SECRETS_FILE);
    $dsn = 'mysql:host=' . $env['DB_HOST'] . ';dbname=' . $env['DB_NAME'] . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $env['DB_USER'], $env['DB_PASS'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}
