<?php
// Last-resort recovery for a locked-out account. Run on the server only:
//   sudo -u www-data php database/reset_password.php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../config/database.php';

function ask($label) { echo $label; return trim((string)fgets(STDIN)); }

$email = strtolower(ask('Account email: '));
$pw    = ask('New temporary password (min 8 chars, visible as you type): ');

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pw) < 8) {
    exit("Invalid input. Nothing was changed.\n");
}
$st = getDB()->prepare('UPDATE users SET password_hash = ?, must_change_password = 1 WHERE email = ?');
$st->execute([password_hash($pw, PASSWORD_DEFAULT), $email]);
echo $st->rowCount() ? "Password reset. The user must change it at the next login.\n" : "No account with that email.\n";