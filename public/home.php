<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
requireLogin();

$role = $_SESSION['role'];
$uid  = (int)$_SESSION['user_id'];
$now  = appNow();
$hour = (int)$now->format('G');
$greeting  = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$firstName = explode(' ', trim($_SESSION['full_name']))[0];

$typeLabels = [
    'cancellation' => 'Cancelled', 'reschedule' => 'Rescheduled', 'time_change' => 'Time changed',
    'room_change'  => 'Room changed', 'mode_change' => 'Mode changed', 'additional' => 'Extra lecture',
];
$statusLabels = [
    'scheduled' => ['On schedule', 'ok'], 'cancelled' => ['Cancelled', 'bad'],
    'rescheduled' => ['Rescheduled', 'warn'], 'completed' => ['Completed', 'neutral'],
];

function slotText($date, $start, $end, $room, $mode) {
    $text = fmtDate($date) . ', ' . fmtTime($start) . ' to ' . fmtTime($end) . ', ' . $room;
    return $mode === 'online' ? $text . ' (online)' : $text;
}

$todayLectures = [];
$notices  = [];
$activity = [];
$unread = 0;
$upcoming = 0;
$lateCount = 0;

if ($role === 'student') {
    $db = getDB();
    $today = $now->format('Y-m-d');

    // Lectures today, for the courses and sections this student is enrolled in.
    $stmt = $db->prepare(
        "SELECT l.original_start, l.original_end, l.original_room, l.status, c.code, c.name, u.full_name AS instructor
         FROM enrollments en
         JOIN lectures l ON l.course_id = en.course_id AND l.section_id = en.section_id
         JOIN courses c ON c.id = l.course_id
         JOIN users u ON u.id = l.instructor_id
         WHERE en.student_id = ? AND l.original_date = ?
         ORDER BY l.original_start"
    );
    $stmt->execute([$uid, $today]);
    $todayLectures = $stmt->fetchAll();

    // Approved changes only. Pending or rejected requests are never shown to students.
    $stmt = $db->prepare(
        "SELECT cr.change_type, cr.proposed_date, cr.proposed_start, cr.proposed_end, cr.proposed_room, cr.proposed_mode,
                cr.reason, cr.submitted_at, cr.reviewed_at, cr.is_late,
                l.original_date, l.original_start, l.original_end, l.original_room, l.original_mode, l.original_start_at,
                c.code, c.name, u.full_name AS instructor
         FROM change_requests cr
         JOIN lectures l ON l.id = cr.lecture_id
         JOIN enrollments en ON en.course_id = l.course_id AND en.section_id = l.section_id AND en.student_id = ?
         JOIN courses c ON c.id = l.course_id
         JOIN users u ON u.id = l.instructor_id
         WHERE cr.status = 'approved'
         ORDER BY cr.reviewed_at DESC
         LIMIT 5"
    );
    $stmt->execute([$uid]);
    $notices = $stmt->fetchAll();

    $stmt = $db->prepare(
        "SELECT COUNT(*) AS total, COALESCE(SUM(cr.is_late), 0) AS late
         FROM change_requests cr
         JOIN lectures l ON l.id = cr.lecture_id
         JOIN enrollments en ON en.course_id = l.course_id AND en.section_id = l.section_id AND en.student_id = ?
         WHERE cr.status = 'approved' AND l.original_date >= ?"
    );
    $stmt->execute([$uid, $today]);
    $row = $stmt->fetch();
    $upcoming  = (int)$row['total'];
    $lateCount = (int)$row['late'];

    $stmt = $db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $stmt->execute([$uid]);
    $unread = (int)$stmt->fetchColumn();

    $stmt = $db->prepare('SELECT message, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5');
    $stmt->execute([$uid]);
    $activity = $stmt->fetchAll();
}

$pageTitle = 'Dashboard';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
  <h1><?= e($greeting) ?>, <?= e($firstName) ?></h1>
  <p class="date"><?= e($now->format('l, j F Y')) ?></p>
  <p class="tagline">Stay informed. Plan your day.</p>
</div>

<?php if ($role === 'student'): ?>
  <div class="stats">
    <div class="card stat"><span class="stat-icon"><?= icon('calendar') ?></span>
      <div><div class="stat-num"><?= count($todayLectures) ?></div><div class="stat-label">Today's lectures</div></div></div>
    <div class="card stat"><span class="stat-icon"><?= icon('swap') ?></span>
      <div><div class="stat-num"><?= $upcoming ?></div><div class="stat-label">Upcoming changes</div></div></div>
    <div class="card stat"><span class="stat-icon"><?= icon('bell') ?></span>
      <div><div class="stat-num"><?= $unread ?></div><div class="stat-label">Unread notifications</div></div></div>
    <div class="card stat"><span class="stat-icon warn"><?= icon('clock') ?></span>
      <div><div class="stat-num"><?= $lateCount ?></div><div class="stat-label">Late notices</div></div></div>
  </div>

  <div class="cols">
    <section class="card" aria-labelledby="h-notices">
      <h2 id="h-notices">Important announcements</h2>
      <?php if (!$notices): ?>
        <div class="empty">No schedule changes yet. Approved changes to your lectures will appear here.</div>
      <?php endif; ?>
      <?php foreach ($notices as $n):
        $isLate = (int)$n['is_late'] === 1;
        $hours  = (int)floor(noticeHours($n['submitted_at'], $n['original_start_at']));
        $original = slotText($n['original_date'], $n['original_start'], $n['original_end'], $n['original_room'], $n['original_mode']);
        if ($n['change_type'] === 'cancellation') {
            $updated = 'Lecture cancelled';
        } else {
            $updated = slotText(
                $n['proposed_date'] ?: $n['original_date'],
                $n['proposed_start'] ?: $n['original_start'],
                $n['proposed_end'] ?: $n['original_end'],
                $n['proposed_room'] ?: $n['original_room'],
                $n['proposed_mode'] ?: $n['original_mode']
            );
        }
      ?>
        <article class="notice<?= $isLate ? ' late' : '' ?>">
          <div class="notice-head">
            <div>
              <h3><span class="code"><?= e($n['code']) ?></span><?= e($n['name']) ?></h3>
              <p class="meta"><?= e($n['instructor']) ?></p>
            </div>
            <div class="badges">
              <span class="badge <?= $n['change_type'] === 'cancellation' ? 'bad' : 'warn' ?>"><?= e($typeLabels[$n['change_type']] ?? 'Changed') ?></span>
              <span class="badge ok">&#10003; Approved</span>
            </div>
          </div>
          <div class="diff-row del"><span class="sign">&minus;</span><span class="text"><?= e($original) ?></span></div>
          <div class="diff-row add"><span class="sign">+</span><span><?= e($updated) ?></span></div>
          <p class="reason"><strong>Reason:</strong> <?= e($n['reason']) ?></p>
          <div class="notice-foot">
            <span>Approved <?= e(toLocalTime($n['reviewed_at'], 'g:i A, D j M')) ?></span>
            <?php if ($isLate): ?>
              <span class="badge bad">! Late notice: under 24 hours</span>
            <?php else: ?>
              <span class="badge ok">&#10003; Notice given <?= $hours ?> hours ahead</span>
            <?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
    </section>

    <div class="stack">
      <section class="card" aria-labelledby="h-today">
        <h2 id="h-today">Today's timetable</h2>
        <?php if (!$todayLectures): ?>
          <div class="empty">No lectures scheduled today.</div>
        <?php else: ?>
          <ul class="slots">
            <?php foreach ($todayLectures as $l): $st = $statusLabels[$l['status']] ?? ['Scheduled', 'neutral']; ?>
              <li class="slot">
                <div class="slot-time"><?= e(fmtTime($l['original_start'])) ?><span><?= e(fmtTime($l['original_end'])) ?></span></div>
                <div>
                  <div class="slot-title"><?= e($l['code']) ?> <?= e($l['name']) ?></div>
                  <div class="meta"><?= e($l['instructor']) ?>, <?= e($l['original_room']) ?></div>
                </div>
                <span class="badge <?= e($st[1]) ?>"><?= e($st[0]) ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </section>

      <section class="card" aria-labelledby="h-activity">
        <h2 id="h-activity">Recent activity</h2>
        <?php if (!$activity): ?>
          <div class="empty">No schedule changes today.</div>
        <?php else: ?>
          <ul class="activity">
            <?php foreach ($activity as $a): ?>
              <li><?= e($a['message']) ?><time><?= e(toLocalTime($a['created_at'], 'D j M, g:i A')) ?></time></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </section>
    </div>
  </div>

<?php else: ?>
  <?php
    $panels = $role === 'instructor'
      ? [['Your lectures', 'No lectures assigned yet. They appear here once the timetable is published.'],
         ['Change requests', 'You have not submitted any requests. Send changes at least 24 hours before the lecture.']]
      : [['Pending approvals', 'Nothing is waiting for review.'],
         ['Timetable setup', 'Add courses, instructors and students, then publish the semester timetable.']];
  ?>
  <div class="cols" style="margin-top:28px">
    <?php foreach ($panels as $p): ?>
      <section class="card"><h2><?= e($p[0]) ?></h2><div class="empty"><?= e($p[1]) ?></div></section>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>