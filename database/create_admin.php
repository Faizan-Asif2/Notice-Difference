<?php
// Run from the terminal only: php database/create_admin.php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../config/database.php';

function ask($label) { echo $label; return trim(fgets(STDIN)); }

$name  = ask('Full name: ');
$email = strtolower(ask('Email: '));
$pw    = ask('Password (min 8 chars; visible as you type): ');

if (strlen($name) < 2 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pw) < 8) {
    exit("Invalid input. Nothing was created.\n");
}
$stmt = getDB()->prepare("INSERT INTO users (full_name, email, password_hash, role) VALUES (?, ?, ?, 'admin')");
$stmt->execute([$name, $email, password_hash($pw, PASSWORD_DEFAULT)]);
echo "Admin created.\n";
