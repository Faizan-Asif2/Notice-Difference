<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

requireRole(['student']);

$db  = getDB();
$uid = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $now = gmdate('Y-m-d H:i:s');
    if (($_POST['action'] ?? '') === 'read_one') {
        // user_id in the WHERE clause: a student can only mark their OWN notifications.
        $db->prepare('UPDATE notifications SET is_read = 1, read_at = ? WHERE id = ? AND user_id = ? AND is_read = 0')
           ->execute([$now, (int)($_POST['id'] ?? 0), $uid]);
    } elseif (($_POST['action'] ?? '') === 'read_all') {
        $db->prepare('UPDATE notifications SET is_read = 1, read_at = ? WHERE user_id = ? AND is_read = 0')
           ->execute([$now, $uid]);
    }
    $back = (($_POST['filter'] ?? '') === 'unread') ? '?filter=unread' : '';
    header('Location: ' . $_SERVER['SCRIPT_NAME'] . $back);
    exit;
}

$filter = (($_GET['filter'] ?? '') === 'unread') ? 'unread' : 'all';

$sql = 'SELECT n.id, n.message, n.created_at, n.is_read, cr.is_late, cr.change_type
        FROM notifications n
        JOIN change_requests cr ON cr.id = n.change_request_id
        WHERE n.user_id = ?';
if ($filter === 'unread') {
    $sql .= ' AND n.is_read = 0';
}
$sql .= ' ORDER BY n.created_at DESC, n.id DESC LIMIT 50';
$st = $db->prepare($sql);
$st->execute([$uid]);
$items = $st->fetchAll();

$st = $db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
$st->execute([$uid]);
$unread = (int)$st->fetchColumn();

$typeLabels = [
    'cancellation' => 'Cancelled', 'reschedule' => 'Rescheduled', 'time_change' => 'Time changed',
    'room_change' => 'Room changed', 'mode_change' => 'Mode changed', 'additional' => 'Extra lecture',
];

$pageTitle = 'Notifications';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
  <h1>Notifications</h1>
  <p class="date"><?= $unread ?> unread. You are notified only after a change is approved.</p>
</div>

<section class="card mt">
  <div class="toolbar">
    <div class="tabs" role="tablist">
      <a class="tab<?= $filter === 'all' ? ' on' : '' ?>" href="<?= e($_SERVER['SCRIPT_NAME']) ?>">All</a>
      <a class="tab<?= $filter === 'unread' ? ' on' : '' ?>" href="<?= e($_SERVER['SCRIPT_NAME']) ?>?filter=unread">Unread</a>
    </div>
    <?php if ($unread > 0): ?>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="action" value="read_all">
        <input type="hidden" name="filter" value="<?= e($filter) ?>">
        <button type="submit" class="btn btn-outline">Mark all as read</button>
      </form>
    <?php endif; ?>
  </div>

  <?php if (!$items): ?>
    <div class="empty"><?= $filter === 'unread' ? 'You have no unread notifications.' : 'No notifications yet. Approved changes to your lectures will appear here.' ?></div>
  <?php endif; ?>

  <?php foreach ($items as $n): ?>
    <article class="notice<?= $n['is_read'] ? '' : ' unread' ?><?= (int)$n['is_late'] === 1 ? ' late' : '' ?>">
      <div class="notice-head">
        <div class="badges">
          <?php if (!$n['is_read']): ?><span class="badge ok">New</span><?php endif; ?>
          <span class="badge <?= $n['change_type'] === 'cancellation' ? 'bad' : 'warn' ?>"><?= e($typeLabels[$n['change_type']] ?? 'Changed') ?></span>
          <?php if ((int)$n['is_late'] === 1): ?><span class="badge bad">! Late notice</span><?php endif; ?>
        </div>
        <span class="meta"><?= e(toLocalTime($n['created_at'], 'D j M, g:i A')) ?></span>
      </div>
      <p class="reason"><?= e($n['message']) ?></p>
      <?php if (!$n['is_read']): ?>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
          <input type="hidden" name="action" value="read_one">
          <input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
          <input type="hidden" name="filter" value="<?= e($filter) ?>">
          <button type="submit" class="btn btn-outline">Mark as read</button>
        </form>
      <?php endif; ?>
    </article>
  <?php endforeach; ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>