<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

// Permission check FIRST, before any query or output.
requireRole(['admin']);

$db      = getDB();
$adminId = (int)$_SESSION['user_id'];
$errors  = [];
$roles   = ['student', 'instructor', 'admin'];
$new     = ['full_name' => '', 'email' => '', 'role' => 'instructor'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $new['full_name'] = trim($_POST['full_name'] ?? '');
        $new['email']     = strtolower(trim($_POST['email'] ?? ''));
        $new['role']      = $_POST['role'] ?? '';
        $pw               = $_POST['password'] ?? '';

        if (strlen($new['full_name']) < 2 || strlen($new['full_name']) > 100) $errors[] = 'Enter a full name (2 to 100 characters).';
        if (!filter_var($new['email'], FILTER_VALIDATE_EMAIL) || strlen($new['email']) > 150) $errors[] = 'Enter a valid email address.';
        if (strlen($pw) < 8) $errors[] = 'Password must be at least 8 characters.';
        if (!in_array($new['role'], $roles, true)) $errors[] = 'Choose a role.';

        if (!$errors) {
            try {
                $db->prepare('INSERT INTO users (full_name, email, password_hash, role) VALUES (?, ?, ?, ?)')
                   ->execute([$new['full_name'], $new['email'], password_hash($pw, PASSWORD_DEFAULT), $new['role']]);
                setFlash('Account created for ' . $new['full_name'] . ' as ' . $new['role'] . '.');
                header('Location: ' . $_SERVER['SCRIPT_NAME']);
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

    } elseif ($action === 'role' || $action === 'active') {
        $targetId = (int)($_POST['user_id'] ?? 0);
        $st = $db->prepare('SELECT id, role FROM users WHERE id = ?');
        $st->execute([$targetId]);
        $target = $st->fetch();

        if (!$target) {
            $errors[] = 'User not found.';
        } elseif ($targetId === $adminId) {
            $errors[] = 'You cannot change your own role or status.';
        } elseif ($action === 'role') {
            $role = $_POST['role'] ?? '';
            if (!in_array($role, $roles, true)) {
                $errors[] = 'Choose a valid role.';
            } else {
                if ($target['role'] === 'instructor' && $role !== 'instructor') {
                    $cnt = $db->prepare('SELECT COUNT(*) FROM courses WHERE instructor_id = ?');
                    $cnt->execute([$targetId]);
                    if ((int)$cnt->fetchColumn() > 0) {
                        $errors[] = 'This instructor still has courses. Reassign them before changing the role.';
                    }
                }
                if (!$errors) {
                    $db->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $targetId]);
                    setFlash('Role updated. It takes effect the next time that user logs in.');
                    header('Location: ' . $_SERVER['SCRIPT_NAME']);
                    exit;
                }
            }
        } else {
            $value = ($_POST['value'] ?? '') === '1' ? 1 : 0;
            $db->prepare('UPDATE users SET is_active = ? WHERE id = ?')->execute([$value, $targetId]);
            setFlash($value ? 'Account enabled.' : 'Account disabled. The user can no longer log in.');
            header('Location: ' . $_SERVER['SCRIPT_NAME']);
            exit;
        }
    }
}

$users = $db->query(
    'SELECT id, full_name, email, role, is_active, created_at FROM users ORDER BY id DESC'
)->fetchAll();
$flash = takeFlash();

$pageTitle = 'User management';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
  <h1>User management</h1>
  <p class="date"><?= count($users) ?> registered accounts</p>
</div>

<?php if ($flash): ?><div class="success mt" role="status"><?= e($flash) ?></div><?php endif; ?>
<?php if ($errors): ?>
  <div class="error mt" role="alert"><ul>
    <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
  </ul></div>
<?php endif; ?>

<section class="card form-card mt">
  <h2>Create an account</h2>
  <p class="hint">Use this for instructors and administrators. Students can also register themselves.</p>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <input type="hidden" name="action" value="create">
    <div class="row2">
      <div><label for="n_name">Full name</label><input type="text" id="n_name" name="full_name" value="<?= e($new['full_name']) ?>" required></div>
      <div><label for="n_email">Email</label><input type="email" id="n_email" name="email" value="<?= e($new['email']) ?>" required></div>
    </div>
    <div class="row2">
      <div><label for="n_pw">Temporary password</label><input type="password" id="n_pw" name="password" autocomplete="new-password" required></div>
      <div>
        <label for="n_role">Role</label>
        <select id="n_role" name="role">
          <?php foreach ($roles as $r): ?><option value="<?= e($r) ?>"<?= $new['role'] === $r ? ' selected' : '' ?>><?= e(ucfirst($r)) ?></option><?php endforeach; ?>
        </select>
      </div>
    </div>
    <button type="submit" class="btn btn-sm">Create account</button>
  </form>
</section>

<section class="card mt">
  <h2>All users</h2>
  <?php if (!$users): ?>
    <div class="empty">No users found.</div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>ID</th><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Joined</th></tr></thead>
        <tbody>
          <?php foreach ($users as $u): $self = (int)$u['id'] === $adminId; ?>
            <tr>
              <td><?= (int)$u['id'] ?></td>
              <td><?= e($u['full_name']) ?><?= $self ? ' <span class="badge neutral">You</span>' : '' ?></td>
              <td><?= e($u['email']) ?></td>
              <td>
                <?php if ($self): ?>
                  <span class="badge warn"><?= e(ucfirst($u['role'])) ?></span>
                <?php else: ?>
                  <form method="post" class="inline-form" data-confirm="Change this user's role?">
                    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="action" value="role">
                    <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                    <select name="role" aria-label="Role for <?= e($u['full_name']) ?>">
                      <?php foreach ($roles as $r): ?><option value="<?= e($r) ?>"<?= $u['role'] === $r ? ' selected' : '' ?>><?= e(ucfirst($r)) ?></option><?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-outline btn-xs">Save</button>
                  </form>
                <?php endif; ?>
              </td>
              <td>
                <?php if ((int)$u['is_active'] === 1): ?><span class="badge ok">&#10003; Active</span><?php else: ?><span class="badge bad">&#10005; Disabled</span><?php endif; ?>
                <?php if (!$self): ?>
                  <form method="post" class="inline-form" data-confirm="<?= (int)$u['is_active'] === 1 ? 'Disable this account? The user will not be able to log in.' : 'Enable this account?' ?>">
                    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="action" value="active">
                    <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                    <input type="hidden" name="value" value="<?= (int)$u['is_active'] === 1 ? '0' : '1' ?>">
                    <button type="submit" class="btn btn-outline btn-xs"><?= (int)$u['is_active'] === 1 ? 'Disable' : 'Enable' ?></button>
                  </form>
                <?php endif; ?>
              </td>
              <td><?= e(toLocalTime($u['created_at'], 'j M Y')) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<script>
// Confirmation before changing a role or status. The server still enforces every rule.
document.querySelectorAll('form[data-confirm]').forEach(function (f) {
  f.addEventListener('submit', function (e) {
    if (!confirm(f.getAttribute('data-confirm'))) e.preventDefault();
  });
});
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>