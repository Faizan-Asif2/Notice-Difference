<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

requireRole(['student']);

$db  = getDB();
$uid = (int)$_SESSION['user_id'];

$types = [
    'cancellation' => 'Cancelled', 'reschedule' => 'Rescheduled', 'time_change' => 'Time changed',
    'room_change' => 'Room changed', 'mode_change' => 'Mode changed', 'additional' => 'Extra lecture',
];

function slotLine($date, $start, $end, $room, $mode) {
    return fmtDate($date) . ', ' . fmtTime($start) . ' to ' . fmtTime($end) . ', ' . $room
         . ($mode === 'online' ? ' (online)' : '');
}

// Only changes that were APPROVED, and only for courses this student is enrolled in.
$st = $db->prepare(
    "SELECT cr.id, cr.change_type, cr.proposed_date, cr.proposed_start, cr.proposed_end, cr.proposed_room,
            cr.proposed_mode, cr.reason, cr.submitted_at, cr.reviewed_at, cr.is_late,
            l.original_date, l.original_start, l.original_end, l.original_room, l.original_mode, l.original_start_at,
            c.code, c.name, u.full_name AS instructor, rv.full_name AS reviewer,
            (SELECT n.created_at FROM notifications n WHERE n.change_request_id = cr.id AND n.user_id = ? LIMIT 1) AS notified_at
     FROM change_requests cr
     JOIN lectures l ON l.id = cr.lecture_id
     JOIN enrollments en ON en.course_id = l.course_id AND en.section_id = l.section_id AND en.student_id = ?
     JOIN courses c ON c.id = l.course_id
     JOIN users u ON u.id = l.instructor_id
     LEFT JOIN users rv ON rv.id = cr.reviewed_by
     WHERE cr.status = 'approved'
     ORDER BY cr.reviewed_at DESC
     LIMIT 50"
);
$st->execute([$uid, $uid]);
$changes = $st->fetchAll();

$pageTitle = 'Change history';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
  <h1>Change history</h1>
  <p class="date">Every approved change to your lectures, with the original schedule kept on record.</p>
</div>

<?php if (!$changes): ?>
  <section class="card mt"><div class="empty">No approved changes yet.</div></section>
<?php endif; ?>

<?php foreach ($changes as $c):
  $late  = (int)$c['is_late'] === 1;
  $hours = (int)floor(noticeHours($c['submitted_at'], $c['original_start_at']));
  $original = slotLine($c['original_date'], $c['original_start'], $c['original_end'], $c['original_room'], $c['original_mode']);
  $updated = $c['change_type'] === 'cancellation' ? 'Lecture cancelled' : slotLine(
      $c['proposed_date']  ?: $c['original_date'],
      $c['proposed_start'] ?: $c['original_start'],
      $c['proposed_end']   ?: $c['original_end'],
      $c['proposed_room']  ?: $c['original_room'],
      $c['proposed_mode']  ?: $c['original_mode']
  );
?>
  <section class="card mt">
    <div class="notice-head">
      <div>
        <h2><span class="code"><?= e($c['code']) ?></span><?= e($c['name']) ?></h2>
        <p class="meta"><?= e($c['instructor']) ?></p>
      </div>
      <div class="badges">
        <span class="badge <?= $c['change_type'] === 'cancellation' ? 'bad' : 'warn' ?>"><?= e($types[$c['change_type']] ?? 'Changed') ?></span>
        <span class="badge ok">&#10003; Approved</span>
      </div>
    </div>

    <div class="diff-row del"><span class="sign">&minus;</span><span class="text"><?= e($original) ?></span></div>
    <div class="diff-row add"><span class="sign">+</span><span><?= e($updated) ?></span></div>
    <p class="reason"><strong>Reason:</strong> <?= e($c['reason']) ?></p>

    <ol class="timeline">
      <li><strong>Requested</strong> by <?= e($c['instructor']) ?><span><?= e(toLocalTime($c['submitted_at'], 'D j M, g:i A')) ?></span></li>
      <li><strong>Approved</strong> by <?= e($c['reviewer'] ?? 'an administrator') ?><span><?= e(toLocalTime($c['reviewed_at'], 'D j M, g:i A')) ?></span></li>
      <li><strong>You were notified</strong><span><?= $c['notified_at'] ? e(toLocalTime($c['notified_at'], 'D j M, g:i A')) : 'No record' ?></span></li>
    </ol>

    <?php if ($late): ?>
      <span class="badge bad">! Late notice: less than 24 hours before the lecture</span>
    <?php else: ?>
      <span class="badge ok">&#10003; Notice provided <?= $hours ?> hours in advance</span>
    <?php endif; ?>
  </section>
<?php endforeach; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>