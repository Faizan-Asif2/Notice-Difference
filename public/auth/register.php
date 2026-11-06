<?php
require_once __DIR__ . '/../includes/auth.php';

if (isLoggedIn()) {
    header('Location: ' . BASE_URL . '/home.php');
    exit;
}

$errors = [];
$name = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $name  = trim($_POST['full_name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $pw    = $_POST['password'] ?? '';
    $pw2   = $_POST['confirm_password'] ?? '';

    if (strlen($name) < 2 || strlen($name) > 100) {
        $errors[] = 'Enter your full name (2 to 100 characters).';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
        $errors[] = 'Enter a valid email address.';
    }
    if (strlen($pw) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }
    if ($pw !== $pw2) {
        $errors[] = 'Passwords do not match.';
    }

    if (!$errors) {
        try {
            // The role is fixed to 'student' here, never taken from the form.
            $stmt = getDB()->prepare(
                "INSERT INTO users (full_name, email, password_hash, role) VALUES (?, ?, ?, 'student')"
            );
            
            $stmt->execute([$name, $email, password_hash($pw, PASSWORD_DEFAULT)]);
            $_SESSION['flash'] = 'Account created. Please log in.';
            header('Location: ' . BASE_URL . '/auth/login.php');
            exit;
        } catch (PDOException $ex) {
            if ($ex->getCode() === '23000') {
                $errors[] = 'That email is already registered.';
            } else {
                error_log($ex->getMessage());
                $errors[] = 'Something went wrong. Please try again.';
            }
        }
    }
}

$pageTitle = 'Sign up';
$bodyClass = 'auth-page';
require __DIR__ . '/../includes/header.php';
?>
<div class="auth">
  <?php require __DIR__ . '/../includes/auth_panel.php'; ?>
  <section class="auth-main">
    <div class="auth-box">
      <h1>Create your account</h1>
      <p class="lead">Student sign-up. Instructor and administrator accounts are created by the timetable coordinator.</p>

      <div id="js-errors" class="error" role="alert" style="display:none"></div>
      <?php if ($errors): ?>
        <div class="error" role="alert"><ul>
          <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
        </ul></div>
      <?php endif; ?>

      <form method="post" data-validate="register" data-loading="Creating account..." novalidate>
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
        <label for="full_name">Full name</label>
        <input type="text" id="full_name" name="full_name" value="<?= e($name) ?>" autocomplete="name" required>
        <label for="email">University email</label>
        <input type="email" id="email" name="email" value="<?= e($email) ?>" autocomplete="email" required>
        <label for="password">Password</label>
        <div class="pw">
          <input type="password" id="password" name="password" autocomplete="new-password" required>
          <button type="button" class="pw-toggle" data-toggle="password" aria-label="Show password">Show</button>
        </div>
        <label for="confirm_password">Confirm password</label>
        <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" required>
        <ul class="checks" aria-live="polite">
          <li data-rule="len">At least 8 characters</li>
          <li data-rule="match">Both passwords match</li>
        </ul>
        <button type="submit" class="btn btn-block">Create account</button>
      </form>
      <p class="auth-alt">Already registered? <a href="<?= BASE_URL ?>/auth/login.php">Log in</a></p>
    </div>
  </section>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>