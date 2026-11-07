<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

requireRole(['instructor']);

$db  = getDB();
$uid = (int)$_SESSION['user_id'];

$st = $db->prepare(
    'SELECT c.code, c.name, m.name AS semester,
            (SELECT COUNT(*) FROM enrollments e WHERE e.course_id = c.id) AS students
     FROM courses c JOIN semesters m ON m.id = c.semester_id
     WHERE c.instructor_id = ?
     ORDER BY c.code'
);
$st->execute([$uid]);
$courses = $st->fetchAll();

$st = $db->prepare(
    'SELECT l.original_date, l.original_start, l.original_end, l.original_room, l.original_mode, l.status,
            c.code, s.name AS section,
            (SELECT cr.status FROM change_requests cr WHERE cr.lecture_id = l.id ORDER BY cr.id DESC LIMIT 1) AS last_request
     FROM lectures l
     JOIN courses c ON c.id = l.course_id
     JOIN sections s ON s.id = l.section_id
     WHERE l.instructor_id = ?
     ORDER BY l.original_date DESC, l.original_start DESC
     LIMIT 60'
);
$st->execute([$uid]);
$lectures = $st->fetchAll();

$statusClass = ['scheduled' => 'ok', 'cancelled' => 'bad', 'rescheduled' => 'warn', 'completed' => 'neutral'];
$reqClass    = ['pending' => 'warn', 'approved' => 'ok', 'rejected' => 'bad'];

$pageTitle = 'My lectures';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
  <h1>My lectures</h1>
  <p class="date">Your courses and the official timetable. Original details are never overwritten.</p>
</div>

<section class="card mt">
  <h2>My courses</h2>
  <?php if (!$courses): ?>
    <div class="empty">No courses are assigned to you yet.</div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Code</th><th>Name</th><th>Semester</th><th>Students</th></tr></thead>
        <tbody>
          <?php foreach ($courses as $c): ?>
            <tr><td><?= e($c['code']) ?></td><td><?= e($c['name']) ?></td><td><?= e($c['semester']) ?></td><td><?= (int)$c['students'] ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<section class="card mt">
  <div class="toolbar">
    <h2>Lectures</h2>
    <a class="btn" href="<?= BASE_URL ?>/instructor/request.php">Request a change</a>
  </div>
  <?php if (!$lectures): ?>
    <div class="empty">No lectures yet. The administrator adds lectures to the timetable.</div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Date</th><th>Time</th><th>Course</th><th>Section</th><th>Room</th><th>Status</th><th>Latest request</th></tr></thead>
        <tbody>
          <?php foreach ($lectures as $l): ?>
            <tr>
              <td><?= e(fmtDate($l['original_date'])) ?></td>
              <td><?= e(fmtTime($l['original_start']) . ' to ' . fmtTime($l['original_end'])) ?></td>
              <td><?= e($l['code']) ?></td>
              <td><?= e($l['section']) ?></td>
              <td><?= e($l['original_room'] . ($l['original_mode'] === 'online' ? ' (online)' : '')) ?></td>
              <td><span class="badge <?= e($statusClass[$l['status']] ?? 'neutral') ?>"><?= e(ucfirst($l['status'])) ?></span></td>
              <td><?= $l['last_request'] ? '<span class="badge ' . e($reqClass[$l['last_request']] ?? 'neutral') . '">' . e(ucfirst($l['last_request'])) . '</span>' : '<span class="meta">None</span>' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>