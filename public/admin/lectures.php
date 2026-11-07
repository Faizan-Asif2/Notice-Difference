<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

requireRole(['admin']);

$db = getDB();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $courseId  = (int)($_POST['course_id'] ?? 0);
    $sectionId = (int)($_POST['section_id'] ?? 0);
    $date      = $_POST['lecture_date'] ?? '';
    $start     = $_POST['start_time'] ?? '';
    $end       = $_POST['end_time'] ?? '';
    $room      = trim($_POST['room'] ?? '');
    $mode      = $_POST['mode'] ?? 'on_campus';

    if (!isValidDate($date)) $errors[] = 'Enter a valid date.';
    if (!isValidTime($start) || !isValidTime($end) || $end <= $start) {
        $errors[] = 'Enter valid times. The end must be after the start.';
    }
    if ($room === '' || strlen($room) > 50) $errors[] = 'Enter a room (up to 50 characters).';
    if (!in_array($mode, ['on_campus', 'online'], true)) $errors[] = 'Choose a mode.';

    $info = null;
    if (!$errors) {
        $q = $db->prepare(
            'SELECT c.instructor_id, c.semester_id AS course_sem, s.semester_id AS section_sem,
                    m.start_date, m.end_date
             FROM courses c
             JOIN sections s ON s.id = ?
             JOIN semesters m ON m.id = c.semester_id
             WHERE c.id = ?'
        );
        $q->execute([$sectionId, $courseId]);
        $info = $q->fetch();
        if (!$info) {
            $errors[] = 'Choose a course and a section.';
        } elseif ($info['course_sem'] !== $info['section_sem']) {
            $errors[] = 'That section belongs to a different semester than the course.';
        } elseif ($date < $info['start_date'] || $date > $info['end_date']) {
            $errors[] = 'The date is outside the semester (' . $info['start_date'] . ' to ' . $info['end_date'] . ').';
        }
    }

    if (!$errors) {
        try {
            $db->beginTransaction();
            $db->prepare(
                'INSERT INTO lectures
                   (course_id, instructor_id, section_id, original_date, original_start, original_end,
                    original_room, original_mode, original_start_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $courseId, (int)$info['instructor_id'], $sectionId, $date, $start, $end,
                $room, $mode, localToUtc($date, $start),
            ]);
            $lectureId = (int)$db->lastInsertId();
            $db->prepare(
                "INSERT INTO change_history (lecture_id, action, performed_by, details) VALUES (?, 'created', ?, ?)"
            )->execute([$lectureId, (int)$_SESSION['user_id'], 'Lecture added to the official timetable']);
            $db->commit();
            setFlash('Lecture added to the timetable.');
            header('Location: ' . $_SERVER['SCRIPT_NAME']);
            exit;
        } catch (PDOException $ex) {
            if ($db->inTransaction()) $db->rollBack();
            error_log($ex->getMessage());
            $errors[] = 'Something went wrong. Please try again.';
        }
    }
}

$courses = $db->query(
    'SELECT c.id, c.code, c.name, m.name AS semester, u.full_name AS instructor
     FROM courses c
     JOIN semesters m ON m.id = c.semester_id
     JOIN users u ON u.id = c.instructor_id
     ORDER BY c.code'
)->fetchAll();
$sections = $db->query(
    'SELECT s.id, s.name, m.name AS semester
     FROM sections s JOIN semesters m ON m.id = s.semester_id
     ORDER BY m.start_date DESC, s.name'
)->fetchAll();
$lectures = $db->query(
    'SELECT l.original_date, l.original_start, l.original_end, l.original_room, l.original_mode, l.status,
            c.code, c.name, s.name AS section
     FROM lectures l
     JOIN courses c ON c.id = l.course_id
     JOIN sections s ON s.id = l.section_id
     ORDER BY l.original_date DESC, l.original_start DESC
     LIMIT 30'
)->fetchAll();
$flash = takeFlash();

$pageTitle = 'Timetable management';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
  <h1>Timetable management</h1>
  <p class="date">The official semester timetable. Original details are never overwritten.</p>
</div>

<?php if ($flash): ?><div class="success mt" role="status"><?= e($flash) ?></div><?php endif; ?>
<?php if ($errors): ?>
  <div class="error mt" role="alert"><ul>
    <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
  </ul></div>
<?php endif; ?>

<section class="card form-card mt">
  <h2>Add a lecture</h2>
  <p class="hint">Times are in Pakistan time. The instructor comes from the course.</p>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
    <label for="l_course">Course</label>
    <select id="l_course" name="course_id" required>
      <option value="">Choose...</option>
      <?php foreach ($courses as $c): ?>
        <option value="<?= (int)$c['id'] ?>"><?= e($c['code'] . ' ' . $c['name'] . ', ' . $c['instructor'] . ' (' . $c['semester'] . ')') ?></option>
      <?php endforeach; ?>
    </select>
    <label for="l_section">Section</label>
    <select id="l_section" name="section_id" required>
      <option value="">Choose...</option>
      <?php foreach ($sections as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['name'] . ' (' . $s['semester'] . ')') ?></option><?php endforeach; ?>
    </select>
    <label for="l_date">Date</label>
    <input type="date" id="l_date" name="lecture_date" required>
    <div class="row2">
      <div><label for="l_start">Starts</label><input type="time" id="l_start" name="start_time" required></div>
      <div><label for="l_end">Ends</label><input type="time" id="l_end" name="end_time" required></div>
    </div>
    <div class="row2">
      <div><label for="l_room">Room</label><input type="text" id="l_room" name="room" placeholder="Room 12" required></div>
      <div>
        <label for="l_mode">Mode</label>
        <select id="l_mode" name="mode"><option value="on_campus">On campus</option><option value="online">Online</option></select>
      </div>
    </div>
    <button type="submit" class="btn btn-sm">Add lecture</button>
  </form>
</section>

<section class="card mt">
  <h2>Latest lectures</h2>
  <?php if (!$lectures): ?>
    <div class="empty">No lectures yet. Add a course first, then add lectures here.</div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Date</th><th>Time</th><th>Course</th><th>Section</th><th>Room</th><th>Status</th></tr></thead>
        <tbody>
          <?php foreach ($lectures as $l): ?>
            <tr>
              <td><?= e(fmtDate($l['original_date'])) ?></td>
              <td><?= e(fmtTime($l['original_start']) . ' to ' . fmtTime($l['original_end'])) ?></td>
              <td><?= e($l['code'] . ' ' . $l['name']) ?></td>
              <td><?= e($l['section']) ?></td>
              <td><?= e($l['original_room'] . ($l['original_mode'] === 'online' ? ' (online)' : '')) ?></td>
              <td><span class="badge neutral"><?= e(ucfirst($l['status'])) ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>