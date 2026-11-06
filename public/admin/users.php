<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

// Permission check FIRST, before any query or output.
requireRole(['admin']);

$users = getDB()->query(
    'SELECT id, full_name, email, role, is_active, created_at
     FROM users
     ORDER BY id DESC'
)->fetchAll();

$pageTitle = 'User management';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
  <h1>User management</h1>
  <p class="date"><?= count($users) ?> registered accounts</p>
</div>

<section class="card" style="margin-top:28px">
  <?php if (!$users): ?>
    <div class="empty">No users found.</div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr><th>ID</th><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Joined</th></tr>
        </thead>
        <tbody>
          <?php foreach ($users as $u): ?>
            <tr>
              <td><?= (int)$u['id'] ?></td>
              <td><?= e($u['full_name']) ?></td>
              <td><?= e($u['email']) ?></td>
              <td><span class="badge <?= $u['role'] === 'admin' ? 'warn' : 'neutral' ?>"><?= e(ucfirst($u['role'])) ?></span></td>
              <td>
                <?php if ((int)$u['is_active'] === 1): ?>
                  <span class="badge ok">&#10003; Active</span>
                <?php else: ?>
                  <span class="badge bad">&#10005; Disabled</span>
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
<?php require __DIR__ . '/../includes/footer.php'; ?>