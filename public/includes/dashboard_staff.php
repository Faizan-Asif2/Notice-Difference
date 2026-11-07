<?php
// Included by home.php for the instructor and admin roles.
$db = getDB();
$count = function ($sql, $params = []) use ($db) {
    $s = $db->prepare($sql);
    $s->execute($params);
    return (int)$s->fetchColumn();
};
$types = [
    'cancellation' => 'Cancellation', 'reschedule' => 'Reschedule', 'time_change' => 'Time change',
    'room_change' => 'Room change', 'mode_change' => 'Mode change',
];
$utcNow = gmdate('Y-m-d H:i:s');
?>

<?php if ($role === 'admin'):
  $students    = $count("SELECT COUNT(*) FROM users WHERE role = 'student' AND is_active = 1");
  $instructors = $count("SELECT COUNT(*) FROM users WHERE role = 'instructor' AND is_active = 1");
  $courses     = $count('SELECT COUNT(*) FROM courses');
  $pendingN    = $count("SELECT COUNT(*) FROM change_requests WHERE status = 'pending'");
  $approvedN   = $count("SELECT COUNT(*) FROM change_requests WHERE status = 'approved'");
  $lateN       = $count('SELECT COUNT(*) FROM change_requests WHERE is_late = 1');
  $pending = $db->query(
      "SELECT cr.change_type, cr.is_late, cr.submitted_at, c.code, u.full_name AS instructor
       FROM change_requests cr
       JOIN lectures l ON l.id = cr.lecture_id
       JOIN courses c ON c.id = l.course_id
       JOIN users u ON u.id = cr.requested_by
       WHERE cr.status = 'pending'
       ORDER BY cr.is_late DESC, cr.submitted_at LIMIT 5"
  )->fetchAll();
?>
  <div class="stats">
    <div class="card stat"><span class="stat-icon"><?= icon('users') ?></span><div><div class="stat-num"><?= $students ?></div><div class="stat-label">Students</div></div></div>
    <div class="card stat"><span class="stat-icon"><?= icon('users') ?></span><div><div class="stat-num"><?= $instructors ?></div><div class="stat-label">Instructors</div></div></div>
    <div class="card stat"><span class="stat-icon"><?= icon('book') ?></span><div><div class="stat-num"><?= $courses ?></div><div class="stat-label">Courses</div></div></div>
    <div class="card stat"><span class="stat-icon warn"><?= icon('clock') ?></span><div><div class="stat-num"><?= $pendingN ?></div><div class="stat-label">Pending requests</div></div></div>
    <div class="card stat"><span class="stat-icon"><?= icon('check') ?></span><div><div class="stat-num"><?= $approvedN ?></div><div class="stat-label">Approved changes</div></div></div>
    <div class="card stat"><span class="stat-icon warn"><?= icon('shield') ?></span><div><div class="stat-num"><?= $lateN ?></div><div class="stat-label">Late requests</div></div></div>
  </div>

  <section class="card">
    <div class="toolbar">
      <h2>Waiting for review</h2>
      <a class="btn btn-outline" href="<?= BASE_URL ?>/admin/approvals.php">Open change approvals</a>
    </div>
    <?php if (!$pending): ?>
      <div class="empty">Nothing is waiting for review.</div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th>Course</th><th>Instructor</th><th>Change</th><th>Notice</th><th>Submitted</th></tr></thead>
          <tbody>
            <?php foreach ($pending as $p): ?>
              <tr>
                <td><?= e($p['code']) ?></td>
                <td><?= e($p['instructor']) ?></td>
                <td><?= e($types[$p['change_type']] ?? 'Change') ?></td>
                <td><?= (int)$p['is_late'] === 1 ? '<span class="badge bad">! Late</span>' : '<span class="badge ok">&#10003; On time</span>' ?></td>
                <td><?= e(toLocalTime($p['submitted_at'], 'D j M, g:i A')) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <div class="quick">
    <a class="btn btn-outline" href="<?= BASE_URL ?>/admin/users.php">Users</a>
    <a class="btn btn-outline" href="<?= BASE_URL ?>/admin/setup.php">Courses and enrollments</a>
    <a class="btn btn-outline" href="<?= BASE_URL ?>/admin/lectures.php">Timetable</a>
  </div>

<?php else:
  $courses   = $count('SELECT COUNT(*) FROM courses WHERE instructor_id = ?', [$uid]);
  $upcoming  = $count("SELECT COUNT(*) FROM lectures WHERE instructor_id = ? AND status = 'scheduled' AND original_start_at > ?", [$uid, $utcNow]);
  $pendingN  = $count("SELECT COUNT(*) FROM change_requests WHERE requested_by = ? AND status = 'pending'", [$uid]);
  $st = $db->prepare(
      "SELECT l.original_date, l.original_start, l.original_start_at, c.code, c.name
       FROM lectures l JOIN courses c ON c.id = l.course_id
       WHERE l.instructor_id = ? AND l.status = 'scheduled' AND l.original_start_at > ?
       ORDER BY l.original_start_at LIMIT 5"
  );
  $st->execute([$uid, $utcNow]);
  $next = $st->fetchAll();
?>
  <div class="stats">
    <div class="card stat"><span class="stat-icon"><?= icon('book') ?></span><div><div class="stat-num"><?= $courses ?></div><div class="stat-label">Assigned courses</div></div></div>
    <div class="card stat"><span class="stat-icon"><?= icon('calendar') ?></span><div><div class="stat-num"><?= $upcoming ?></div><div class="stat-label">Upcoming lectures</div></div></div>
    <div class="card stat"><span class="stat-icon warn"><?= icon('clock') ?></span><div><div class="stat-num"><?= $pendingN ?></div><div class="stat-label">Pending requests</div></div></div>
  </div>

  <section class="card">
    <div class="toolbar">
      <h2>Upcoming lectures and notice deadlines</h2>
      <a class="btn" href="<?= BASE_URL ?>/instructor/request.php">Request a change</a>
    </div>
    <?php if (!$next): ?>
      <div class="empty">No upcoming lectures. The administrator adds lectures to the timetable.</div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th>Lecture</th><th>When</th><th>24-hour deadline</th></tr></thead>
          <tbody>
            <?php foreach ($next as $n):
              $dl = (new DateTime($n['original_start_at'], new DateTimeZone('UTC')))->modify('-24 hours');
              $passed = $dl <= new DateTime('now', new DateTimeZone('UTC'));
            ?>
              <tr>
                <td><?= e($n['code'] . ' ' . $n['name']) ?></td>
                <td><?= e(fmtDate($n['original_date']) . ', ' . fmtTime($n['original_start'])) ?></td>
                <td>
                  <?= e(toLocalTime($dl->format('Y-m-d H:i:s'), 'D j M, g:i A')) ?>
                  <?= $passed ? '<span class="badge bad">! Passed</span>' : '<span class="badge ok">&#10003; Open</span>' ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
<?php endif; ?>