<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

requireRole(['admin']);

$db = getDB();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'semester') {
            $name  = trim($_POST['name'] ?? '');
            $start = $_POST['start_date'] ?? '';
            $end   = $_POST['end_date'] ?? '';
            if ($name === '' || strlen($name) > 50) {
                $errors[] = 'Enter a semester name (up to 50 characters).';
            }
            if (!isValidDate($start) || !isValidDate($end) || $end < $start) {
                $errors[] = 'Enter valid dates. The end date cannot be before the start date.';
            }
            if (!$errors) {
                $db->prepare('INSERT INTO semesters (name, start_date, end_date) VALUES (?, ?, ?)')
                   ->execute([$name, $start, $end]);
                setFlash('Semester added.');
            }

        } elseif ($action === 'section') {
            $semId = (int)($_POST['semester_id'] ?? 0);
            $name  = trim($_POST['name'] ?? '');
            if ($name === '' || strlen($name) > 50) {
                $errors[] = 'Enter a section name (up to 50 characters).';
            }
            $chk = $db->prepare('SELECT id FROM semesters WHERE id = ?');
            $chk->execute([$semId]);
            if (!$chk->fetch()) {
                $errors[] = 'Choose a semester.';
            }
            if (!$errors) {
                $db->prepare('INSERT INTO sections (semester_id, name) VALUES (?, ?)')
                   ->execute([$semId, $name]);
                setFlash('Section added.');
            }

        } elseif ($action === 'course') {
            $code   = strtoupper(trim($_POST['code'] ?? ''));
            $name   = trim($_POST['name'] ?? '');
            $semId  = (int)($_POST['semester_id'] ?? 0);
            $instId = (int)($_POST['instructor_id'] ?? 0);
            if ($code === '' || strlen($code) > 20) {
                $errors[] = 'Enter a course code (up to 20 characters).';
            }
            if ($name === '' || strlen($name) > 150) {
                $errors[] = 'Enter a course name (up to 150 characters).';
            }
            $chk = $db->prepare('SELECT id FROM semesters WHERE id = ?');
            $chk->execute([$semId]);
            if (!$chk->fetch()) {
                $errors[] = 'Choose a semester.';
            }
            $chk = $db->prepare("SELECT id FROM users WHERE id = ? AND role = 'instructor' AND is_active = 1");
            $chk->execute([$instId]);
            if (!$chk->fetch()) {
                $errors[] = 'Choose an instructor.';
            }
            if (!$errors) {
                $db->prepare('INSERT INTO courses (code, name, semester_id, instructor_id) VALUES (?, ?, ?, ?)')
                   ->execute([$code, $name, $semId, $instId]);
                setFlash('Course added.');
            }

        } elseif ($action === 'enroll') {
            $studentId = (int)($_POST['student_id'] ?? 0);
            $courseId  = (int)($_POST['course_id'] ?? 0);
            $sectionId = (int)($_POST['section_id'] ?? 0);

            $chk = $db->prepare("SELECT id FROM users WHERE id = ? AND role = 'student' AND is_active = 1");
            $chk->execute([$studentId]);
            if (!$chk->fetch()) {
                $errors[] = 'Choose a student.';
            }
            // The section must belong to the same semester as the course.
            $chk = $db->prepare(
                'SELECT c.semester_id AS course_sem, s.semester_id AS section_sem
                 FROM courses c JOIN sections s ON s.id = ? WHERE c.id = ?'
            );
            $chk->execute([$sectionId, $courseId]);
            $pair = $chk->fetch();
            if (!$pair) {
                $errors[] = 'Choose a course and a section.';
            } elseif ($pair['course_sem'] !== $pair['section_sem']) {
                $errors[] = 'That section belongs to a different semester than the course.';
            }
            if (!$errors) {
                $db->prepare('INSERT INTO enrollments (student_id, course_id, section_id) VALUES (?, ?, ?)')
                   ->execute([$studentId, $courseId, $sectionId]);
                setFlash('Student enrolled.');
            }
        }
    } catch (PDOException $ex) {
        if ($ex->getCode() === '23000') {
            $errors[] = 'That record already exists (duplicate code, section or enrollment).';
        } else {
            error_log($ex->getMessage());
            $errors[] = 'Something went wrong. Please try again.';
        }
    }

    if (!$errors) {
        header('Location: ' . $_SERVER['SCRIPT_NAME']);   // redirect so a refresh cannot re-submit
        exit;
    }
}

$semesters   = $db->query('SELECT id, name FROM semesters ORDER BY start_date DESC')->fetchAll();
$sections    = $db->query(
    'SELECT s.id, s.name, m.name AS semester
     FROM sections s JOIN semesters m ON m.id = s.semester_id
     ORDER BY m.start_date DESC, s.name'
)->fetchAll();
$instructors = $db->query("SELECT id, full_name FROM users WHERE role = 'instructor' AND is_active = 1 ORDER BY full_name")->fetchAll();
$students    = $db->query("SELECT id, full_name FROM users WHERE role = 'student' AND is_active = 1 ORDER BY full_name")->fetchAll();
$courses     = $db->query(
    'SELECT c.id, c.code, c.name, m.name AS semester, u.full_name AS instructor,
            (SELECT COUNT(*) FROM enrollments e WHERE e.course_id = c.id) AS enrolled
     FROM courses c
     JOIN semesters m ON m.id = c.semester_id
     JOIN users u ON u.id = c.instructor_id
     ORDER BY c.code'
)->fetchAll();
$flash = takeFlash();

$pageTitle = 'Course management';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
  <h1>Course management</h1>
  <p class="date">Semesters, sections, courses and student enrollments.</p>
</div>

<?php if ($flash): ?><div class="success mt" role="status"><?= e($flash) ?></div><?php endif; ?>
<?php if ($errors): ?>
  <div class="error mt" role="alert"><ul>
    <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
  </ul></div>
<?php endif; ?>

<div class="form-grid">
  <section class="card form-card">
    <h2>1. Add a semester</h2>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
      <input type="hidden" name="action" value="semester">
      <label for="sem_name">Name</label>
      <input type="text" id="sem_name" name="name" placeholder="Fall 2026" required>
      <div class="row2">
        <div><label for="sem_start">Starts</label><input type="date" id="sem_start" name="start_date" required></div>
        <div><label for="sem_end">Ends</label><input type="date" id="sem_end" name="end_date" required></div>
      </div>
      <button type="submit" class="btn btn-sm">Add semester</button>
    </form>
  </section>

  <section class="card form-card">
    <h2>2. Add a section</h2>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
      <input type="hidden" name="action" value="section">
      <label for="sec_sem">Semester</label>
      <select id="sec_sem" name="semester_id" required>
        <option value="">Choose...</option>
        <?php foreach ($semesters as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?>
      </select>
      <label for="sec_name">Section name</label>
      <input type="text" id="sec_name" name="name" placeholder="A" required>
      <button type="submit" class="btn btn-sm">Add section</button>
    </form>
  </section>

  <section class="card form-card">
    <h2>3. Add a course</h2>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
      <input type="hidden" name="action" value="course">
      <div class="row2">
        <div><label for="c_code">Code</label><input type="text" id="c_code" name="code" placeholder="CS-301" required></div>
        <div><label for="c_name">Name</label><input type="text" id="c_name" name="name" placeholder="Automata Theory" required></div>
      </div>
      <label for="c_sem">Semester</label>
      <select id="c_sem" name="semester_id" required>
        <option value="">Choose...</option>
        <?php foreach ($semesters as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?>
      </select>
      <label for="c_inst">Instructor</label>
      <select id="c_inst" name="instructor_id" required>
        <option value="">Choose...</option>
        <?php foreach ($instructors as $i): ?><option value="<?= (int)$i['id'] ?>"><?= e($i['full_name']) ?></option><?php endforeach; ?>
      </select>
      <?php if (!$instructors): ?><p class="hint">No instructor accounts yet. Register an account, then promote it to instructor.</p><?php endif; ?>
      <button type="submit" class="btn btn-sm">Add course</button>
    </form>
  </section>

  <section class="card form-card">
    <h2>4. Enrol a student</h2>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
      <input type="hidden" name="action" value="enroll">
      <label for="e_student">Student</label>
      <select id="e_student" name="student_id" required>
        <option value="">Choose...</option>
        <?php foreach ($students as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['full_name']) ?></option><?php endforeach; ?>
      </select>
      <label for="e_course">Course</label>
      <select id="e_course" name="course_id" required>
        <option value="">Choose...</option>
        <?php foreach ($courses as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['code'] . ' ' . $c['name'] . ' (' . $c['semester'] . ')') ?></option><?php endforeach; ?>
      </select>
      <label for="e_section">Section</label>
      <select id="e_section" name="section_id" required>
        <option value="">Choose...</option>
        <?php foreach ($sections as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['name'] . ' (' . $s['semester'] . ')') ?></option><?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-sm">Enrol student</button>
    </form>
  </section>
</div>

<section class="card mt">
  <h2>Courses</h2>
  <?php if (!$courses): ?>
    <div class="empty">No courses yet. Add a semester, a section and an instructor first.</div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Code</th><th>Name</th><th>Semester</th><th>Instructor</th><th>Students</th></tr></thead>
        <tbody>
          <?php foreach ($courses as $c): ?>
            <tr>
              <td><?= e($c['code']) ?></td>
              <td><?= e($c['name']) ?></td>
              <td><?= e($c['semester']) ?></td>
              <td><?= e($c['instructor']) ?></td>
              <td><?= (int)$c['enrolled'] ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>