<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

requireRole(['instructor']);

$db  = getDB();
$uid = (int)$_SESSION['user_id'];

// Whitelist: the filter name picks a fixed SQL condition, user input never enters the query.
$filters = [
    'all'      => '1 = 1',
    'pending'  => "cr.status = 'pending'",
    'approved' => "cr.status = 'approved'",
    'rejected' => "cr.status = 'rejected'",
];
$f = $_GET['f'] ?? 'all';
if (!isset($filters[$f])) {
    $f = 'all';
}

$types = [
    'cancellation' => 'Cancellation', 'reschedule' => 'Reschedule', 'time_change' => 'Time change',
    'room_change' => 'Room change', 'mode_change' => 'Mode change', 'additional' => 'Extra lecture',
];
$statusClass = ['pending' => 'warn', 'approved' => 'ok', 'rejected' => 'bad'];

$st = $db->prepare(
    'SELECT cr.id, cr.change_type, cr.status, cr.is_late, cr.reason, cr.submitted_at, cr.deadline_at,
            cr.reviewed_at, cr.review_note, c.code, l.original_date, l.original_start, rv.full_name AS reviewer
     FROM change_requests cr
     JOIN lectures l ON l.id = cr.lecture_id
     JOIN courses c ON c.id = l.course_id
     LEFT JOIN users rv ON rv.id = cr.reviewed_by
     WHERE cr.requested_by = ? AND ' . $filters[$f] . '
     ORDER BY cr.submitted_at DESC
     LIMIT 100'
);
$st->execute([$uid]);
$rows = $st->fetchAll();

$pageTitle = 'Request history';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
  <h1>My change requests</h1>
  <p class="date">The status of every request you submitted, and the administrator's decision.</p>
</div>

<section class="card mt">
  <div class="toolbar">
    <div class="tabs">
      <?php foreach (['all' => 'All', 'pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'] as $k => $label): ?>
        <a class="tab<?= $f === $k ? ' on' : '' ?>" href="?f=<?= e($k) ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
    </div>
    <a class="btn" href="<?= BASE_URL ?>/instructor/request.php">Request a change</a>
  </div>

  <?php if (!$rows): ?>
    <div class="empty">No requests match this filter.</div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Lecture</th><th>Change</th><th>Status</th><th>Notice</th><th>Submitted</th><th>Decision</th></tr></thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?= e($r['code'] . ', ' . fmtDate($r['original_date']) . ' ' . fmtTime($r['original_start'])) ?>
                <div class="meta"><?= e($r['reason']) ?></div></td>
              <td><?= e($types[$r['change_type']] ?? 'Change') ?></td>
              <td><span class="badge <?= e($statusClass[$r['status']] ?? 'neutral') ?>"><?= e(ucfirst($r['status'])) ?></span></td>
              <td><?= (int)$r['is_late'] === 1 ? '<span class="badge bad">! Late</span>' : '<span class="badge ok">&#10003; On time</span>' ?></td>
              <td><?= e(toLocalTime($r['submitted_at'], 'D j M, g:i A')) ?></td>
              <td>
                <?php if ($r['status'] === 'pending'): ?>
                  <span class="meta">Waiting for the administrator</span>
                <?php else: ?>
                  <?= e($r['reviewer'] ?? '') ?>
                  <div class="meta"><?= e(toLocalTime($r['reviewed_at'], 'D j M, g:i A')) ?></div>
                  <?php if ($r['review_note']): ?><div class="meta"><strong>Note:</strong> <?= e($r['review_note']) ?></div><?php endif; ?>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>