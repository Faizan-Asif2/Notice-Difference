<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

requireRole(['instructor']);

$db     = getDB();
$uid    = (int)$_SESSION['user_id'];
$utcNow = gmdate('Y-m-d H:i:s');

// Only courses taught by THIS instructor. The id comes from the session, never from the URL.
$st = $db->prepare(
    "SELECT c.id, c.code, c.name, m.name AS semester,
            (SELECT COUNT(*) FROM enrollments e WHERE e.course_id = c.id) AS students,
            (SELECT COUNT(*) FROM lectures l
              WHERE l.course_id = c.id AND l.instructor_id = ? AND l.status = 'scheduled' AND l.original_start_at > ?) AS upcoming,
            (SELECT COUNT(*) FROM change_requests cr JOIN lectures l ON l.id = cr.lecture_id
              WHERE l.course_id = c.id AND cr.requested_by = ? AND cr.status = 'pending') AS pending
     FROM courses c
     JOIN semesters m ON m.id = c.semester_id
     WHERE c.instructor_id = ?
     ORDER BY m.start_date DESC, c.code"
);
$st->execute([$uid, $utcNow, $uid, $uid]);
$courses = $st->fetchAll();

// Students per section, for all of this instructor's courses.
$st = $db->prepare(
    'SELECT e.course_id, s.name AS section, COUNT(*) AS students
     FROM enrollments e
     JOIN sections s ON s.id = e.section_id
     JOIN courses c ON c.id = e.course_id
     WHERE c.instructor_id = ?
     GROUP BY e.course_id, s.id, s.name
     ORDER BY s.name'
);
$st->execute([$uid]);
$sections = [];
foreach ($st->fetchAll() as $row) {
    $sections[$row['course_id']][] = $row;
}

// The next scheduled lecture of each course.
$next = $db->prepare(
    "SELECT original_date, original_start, original_room
     FROM lectures
     WHERE course_id = ? AND instructor_id = ? AND status = 'scheduled' AND original_start_at > ?
     ORDER BY original_start_at LIMIT 1"
);

$pageTitle = 'My courses';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
  <h1>My courses</h1>
  <p class="date"><?= count($courses) ?> course(s) assigned to you.</p>
</div>

<?php if (!$courses): ?>
  <section class="card mt"><div class="empty">No courses are assigned to you yet. The administrator assigns courses.</div></section>
<?php else: ?>
  <div class="form-grid">
    <?php foreach ($courses as $c):
      $next->execute([(int)$c['id'], $uid, $utcNow]);
      $n = $next->fetch();
    ?>
      <section class="card form-card">
        <h2><span class="code"><?= e($c['code']) ?></span><?= e($c['name']) ?></h2>
        <p class="meta"><?= e($c['semester']) ?></p>

        <p class="mt"><strong><?= (int)$c['students'] ?></strong> enrolled student(s)</p>
        <?php if (!empty($sections[$c['id']])): ?>
          <div class="badges">
            <?php foreach ($sections[$c['id']] as $s): ?>
              <span class="badge neutral">Section <?= e($s['section']) ?>: <?= (int)$s['students'] ?></span>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <p class="mt"><strong><?= (int)$c['upcoming'] ?></strong> upcoming scheduled lecture(s)</p>
        <p class="meta">
          <?php if ($n): ?>
            Next: <?= e(fmtDate($n['original_date']) . ', ' . fmtTime($n['original_start']) . ', ' . $n['original_room']) ?>
          <?php else: ?>
            No upcoming lecture is scheduled.
          <?php endif; ?>
        </p>

        <?php if ((int)$c['pending'] > 0): ?>
          <p class="mt"><span class="badge warn"><?= (int)$c['pending'] ?> request(s) waiting for the administrator</span></p>
        <?php endif; ?>

        <a class="btn btn-sm" href="<?= BASE_URL ?>/instructor/request.php">Request a change</a>
      </section>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>