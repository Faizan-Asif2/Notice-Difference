<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

requireLogin();

$db     = getDB();
$uid    = (int)$_SESSION['user_id'];
$forced = !empty($_SESSION['must_change']);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $cur  = $_POST['current_password'] ?? '';
    $new  = $_POST['new_password'] ?? '';
    $new2 = $_POST['confirm_password'] ?? '';

    $st = $db->prepare('SELECT password_hash FROM users WHERE id = ? AND is_active = 1');
    $st->execute([$uid]);
    $row = $st->fetch();

    // The current (or temporary) password must be proven again before it can be replaced.
    if (!$row || !password_verify($cur, $row['password_hash'])) {
        $errors[] = 'Your current password is incorrect.';
    }
    if (strlen($new) < 8) {
        $errors[] = 'The new password must be at least 8 characters.';
    }
    if ($new !== $new2) {
        $errors[] = 'The new passwords do not match.';
    }
    if ($cur !== '' && $cur === $new) {
        $errors[] = 'The new password must be different from the current one.';
    }

    if (!$errors) {
        $db->prepare('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?')
           ->execute([password_hash($new, PASSWORD_DEFAULT), $uid]);
        unset($_SESSION['must_change']);
        session_regenerate_id(true);
        header('Location: ' . $_SERVER['SCRIPT_NAME'] . '?done=1');
        exit;
    }
}

$done = isset($_GET['done']);
$pageTitle = 'Change password';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
  <h1>Change password</h1>
  <p class="date"><?= $forced ? 'An administrator gave you a temporary password. Choose your own password to continue.' : 'Choose a new password for your account.' ?></p>
</div>

<?php if ($done): ?>
  <div class="success mt" role="status">Password changed. <a href="<?= BASE_URL ?>/home.php">Go to your dashboard</a>.</div>
<?php endif; ?>
<?php if ($errors): ?>
  <div class="error mt" role="alert"><ul>
    <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
  </ul></div>
<?php endif; ?>

<section class="card form-card mt">
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <label for="current_password"><?= $forced ? 'Temporary password' : 'Current password' ?></label>
    <input type="password" id="current_password" name="current_password" autocomplete="current-password" required>
    <label for="new_password">New password</label>
    <input type="password" id="new_password" name="new_password" autocomplete="new-password" required>
    <p class="hint">At least 8 characters.</p>
    <label for="confirm_password">Confirm new password</label>
    <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" required>
    <button type="submit" class="btn btn-sm">Change password</button>
  </form>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>