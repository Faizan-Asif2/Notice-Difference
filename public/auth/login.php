<?php
require_once __DIR__ . '/../includes/auth.php';

if (isLoggedIn()) {
    header('Location: ' . BASE_URL . '/home.php');
    exit;
}

$error = '';
$email = '';
$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $email = strtolower(trim($_POST['email'] ?? ''));
    $pw    = $_POST['password'] ?? '';

    try {
        $stmt = getDB()->prepare(
            'SELECT id, full_name, password_hash, role, is_active FROM users WHERE email = ?'
        );
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && $user['is_active'] && password_verify($pw, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id']   = (int)$user['id'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role']      = $user['role'];
            header('Location: ' . BASE_URL . '/home.php');
            exit;
        }
        $error = 'Invalid email or password.';
    } catch (PDOException $ex) {
        error_log($ex->getMessage());
        $error = 'Database connection error. Please try again later.';
    }
}

$pageTitle = 'Log in';
$bodyClass = 'auth-page';
require __DIR__ . '/../includes/header.php';
?>
<div class="auth">
  <?php require __DIR__ . '/../includes/auth_panel.php'; ?>
  <section class="auth-main">
    <div class="auth-box">
      <h1>Welcome back</h1>
      <p class="lead">Log in to see your timetable and recent changes.</p>

      <?php if ($flash): ?><div class="success" role="status"><?= e($flash) ?></div><?php endif; ?>
      <?php if ($error): ?><div class="error" role="alert"><?= e($error) ?></div><?php endif; ?>

      <form method="post" data-loading="Logging in...">
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
        <label for="email">Email address</label>
        <input type="email" id="email" name="email" value="<?= e($email) ?>" autocomplete="email" required>
        <label for="password">Password</label>
        <div class="pw">
          <input type="password" id="password" name="password" autocomplete="current-password" required>
          <button type="button" class="pw-toggle" data-toggle="password" aria-label="Show password">Show</button>
        </div>
        <p class="hint">Forgot your password? Ask your timetable coordinator to reset it.</p>
        <button type="submit" class="btn btn-block">Log in</button>
      </form>
      <p class="auth-alt">New student? <a href="<?= BASE_URL ?>/auth/register.php">Create an account</a></p>
    </div>
  </section>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>