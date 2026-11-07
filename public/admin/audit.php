<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

requireRole(['admin']);

$db = getDB();

// Whitelist: the filter name picks a fixed SQL condition, user input never enters the query.
$filters = [
    'all'      => '1 = 1',
    'late'     => 'cr.is_late = 1',
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

$sum = $db->query(
    "SELECT COUNT(*) AS total,
            COALESCE(SUM(is_late = 0), 0) AS on_time,
            COALESCE(SUM(is_late = 1), 0) AS late,
            COALESCE(SUM(status = 'approved'), 0) AS approved,
            COALESCE(SUM(status = 'rejected'), 0) AS rejected,
            COALESCE(SUM(status = 'pending'), 0) AS pending
     FROM change_requests"
)->fetch();

$rows = $db->query(
    "SELECT cr.id, cr.change_type, cr.status, cr.is_late, cr.submitted_at, cr.deadline_at, cr.reviewed_at,
            cr.review_note, cr.late_justification,
            c.code, l.original_date, l.original_start, l.original_start_at,
            req.full_name AS requester, rv.full_name AS reviewer,
            (SELECT COUNT(*) FROM notifications n WHERE n.change_request_id = cr.id) AS notified,
            (SELECT MIN(n.created_at) FROM notifications n WHERE n.change_request_id = cr.id) AS first_notified
     FROM change_requests cr
     JOIN lectures l ON l.id = cr.lecture_id
     JOIN courses c ON c.id = l.course_id
     JOIN users req ON req.id = cr.requested_by
     LEFT JOIN users rv ON rv.id = cr.reviewed_by
     WHERE " . $filters[$f] . "
     ORDER BY cr.submitted_at DESC
     LIMIT 100"
)->fetchAll();

$log = $db->query(
    'SELECT h.action, h.details, h.created_at, c.code, u.full_name AS who
     FROM change_history h
     JOIN lectures l ON l.id = h.lecture_id
     JOIN courses c ON c.id = l.course_id
     LEFT JOIN users u ON u.id = h.performed_by
     ORDER BY h.id DESC
     LIMIT 30'
)->fetchAll();

$pageTitle = 'Audit history';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
  <h1>Change audit report</h1>
  <p class="date">Who requested what, when, whether notice was on time, who decided, and who was notified. Records cannot be edited.</p>
</div>

<div class="stats">
  <div class="card stat"><span class="stat-icon"><?= icon('file') ?></span><div><div class="stat-num"><?= (int)$sum['total'] ?></div><div class="stat-label">Total requests</div></div></div>
  <div class="card stat"><span class="stat-icon"><?= icon('check') ?></span><div><div class="stat-num"><?= (int)$sum['on_time'] ?></div><div class="stat-label">Met 24-hour notice</div></div></div>
  <div class="card stat"><span class="stat-icon warn"><?= icon('clock') ?></span><div><div class="stat-num"><?= (int)$sum['late'] ?></div><div class="stat-label">Late notice</div></div></div>
  <div class="card stat"><span class="stat-icon"><?= icon('shield') ?></span><div><div class="stat-num"><?= (int)$sum['approved'] ?> / <?= (int)$sum['rejected'] ?></div><div class="stat-label">Approved / rejected</div></div></div>
</div>

<section class="card">
  <div class="toolbar">
    <h2>Requests</h2>
    <div class="tabs">
      <?php foreach (['all' => 'All', 'late' => 'Late', 'pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'] as $k => $label): ?>
        <a class="tab<?= $f === $k ? ' on' : '' ?>" href="?f=<?= e($k) ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if (!$rows): ?>
    <div class="empty">No requests match this filter.</div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr><th>#</th><th>Lecture</th><th>Change</th><th>Requested by</th><th>Submitted</th><th>Notice</th><th>Decision</th><th>Reviewed by</th><th>Notified</th></tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r):
            $hours = (int)floor(noticeHours($r['submitted_at'], $r['original_start_at']));
          ?>
            <tr>
              <td><?= (int)$r['id'] ?></td>
              <td><?= e($r['code'] . ', ' . fmtDate($r['original_date']) . ' ' . fmtTime($r['original_start'])) ?></td>
              <td><?= e($types[$r['change_type']] ?? 'Change') ?></td>
              <td><?= e($r['requester']) ?></td>
              <td><?= e(toLocalTime($r['submitted_at'], 'D j M, g:i A')) ?></td>
              <td>
                <?php if ((int)$r['is_late'] === 1): ?>
                  <span class="badge bad">! Late</span>
                  <div class="meta"><?= e($r['late_justification'] ?? '') ?></div>
                <?php else: ?>
                  <span class="badge ok">&#10003; <?= $hours ?> h ahead</span>
                <?php endif; ?>
              </td>
              <td>
                <span class="badge <?= e($statusClass[$r['status']] ?? 'neutral') ?>"><?= e(ucfirst($r['status'])) ?></span>
                <?php if ($r['reviewed_at']): ?><div class="meta"><?= e(toLocalTime($r['reviewed_at'], 'D j M, g:i A')) ?></div><?php endif; ?>
                <?php if ($r['review_note']): ?><div class="meta"><?= e($r['review_note']) ?></div><?php endif; ?>
              </td>
              <td><?= e($r['reviewer'] ?? '') ?></td>
              <td>
                <?php if ((int)$r['notified'] > 0): ?>
                  <?= (int)$r['notified'] ?> student(s)<div class="meta"><?= e(toLocalTime($r['first_notified'], 'D j M, g:i A')) ?></div>
                <?php else: ?>
                  <span class="meta">None</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<section class="card mt">
  <h2>Permanent history log</h2>
  <p class="hint">Latest 30 entries. The database refuses to edit or delete these rows.</p>
  <?php if (!$log): ?>
    <div class="empty">No history yet.</div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>When</th><th>Lecture</th><th>Action</th><th>By</th><th>Details</th></tr></thead>
        <tbody>
          <?php foreach ($log as $h): ?>
            <tr>
              <td><?= e(toLocalTime($h['created_at'], 'D j M, g:i A')) ?></td>
              <td><?= e($h['code']) ?></td>
              <td><span class="badge neutral"><?= e(ucfirst($h['action'])) ?></span></td>
              <td><?= e($h['who'] ?? 'System') ?></td>
              <td><?= e($h['details'] ?? '') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>