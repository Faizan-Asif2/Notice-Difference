<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

requireRole(['instructor']);

$db  = getDB();
$uid = (int)$_SESSION['user_id'];
$utc = new DateTimeZone('UTC');
$now = new DateTime('now', $utc);
$errors = [];
$old = [];

$types = [
    'cancellation' => 'Cancellation',
    'reschedule'   => 'Reschedule to another day',
    'time_change'  => 'Time change',
    'room_change'  => 'Room change',
    'mode_change'  => 'Mode change (on campus / online)',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $old       = $_POST;
    $lectureId = (int)($_POST['lecture_id'] ?? 0);
    $type      = $_POST['change_type'] ?? '';
    $reason    = trim($_POST['reason'] ?? '');
    $just      = trim($_POST['late_justification'] ?? '');
    $pDate     = $_POST['proposed_date'] ?? '';
    $pStart    = $_POST['proposed_start'] ?? '';
    $pEnd      = $_POST['proposed_end'] ?? '';
    $pRoom     = trim($_POST['proposed_room'] ?? '');
    $pMode     = $_POST['proposed_mode'] ?? '';

    // The lecture must belong to THIS instructor and still be scheduled.
    $st = $db->prepare(
        "SELECT id, original_room, original_mode, original_start_at
         FROM lectures WHERE id = ? AND instructor_id = ? AND status = 'scheduled'"
    );
    $st->execute([$lectureId, $uid]);
    $lec = $st->fetch();

    $isLate = false;
    $deadline = null;
    $prop = ['date' => null, 'start' => null, 'end' => null, 'room' => null, 'mode' => null];

    if (!$lec) {
        $errors[] = 'Choose one of your scheduled lectures.';
    } else {
        $startAt  = new DateTime($lec['original_start_at'], $utc);
        $deadline = (clone $startAt)->modify('-24 hours');     // the 24-hour rule
        $isLate   = $now > $deadline;
        if ($startAt <= $now) {
            $errors[] = 'That lecture has already started, so it cannot be changed here.';
        }
        $dup = $db->prepare("SELECT id FROM change_requests WHERE lecture_id = ? AND status = 'pending'");
        $dup->execute([$lectureId]);
        if ($dup->fetch()) {
            $errors[] = 'There is already a pending request for this lecture.';
        }
    }

    if (!isset($types[$type])) {
        $errors[] = 'Choose a change type.';
    }
    if (strlen($reason) < 5 || strlen($reason) > 2000) {
        $errors[] = 'Enter a reason (5 to 2000 characters).';
    }

    if ($lec && isset($types[$type])) {
        if ($type === 'reschedule') {
            if (!isValidDate($pDate) || !isValidTime($pStart) || !isValidTime($pEnd) || $pEnd <= $pStart) {
                $errors[] = 'For a reschedule, enter a valid new date and times (end after start).';
            } elseif (new DateTime(localToUtc($pDate, $pStart), $utc) <= $now) {
                $errors[] = 'The new time must be in the future.';
            } else {
                $prop['date'] = $pDate; $prop['start'] = $pStart; $prop['end'] = $pEnd;
            }
        } elseif ($type === 'time_change') {
            if (!isValidTime($pStart) || !isValidTime($pEnd) || $pEnd <= $pStart) {
                $errors[] = 'For a time change, enter valid new times (end after start).';
            } else {
                $prop['start'] = $pStart; $prop['end'] = $pEnd;
            }
        } elseif ($type === 'room_change') {
            if ($pRoom === '' || strlen($pRoom) > 50) {
                $errors[] = 'Enter the new room (up to 50 characters).';
            } elseif ($pRoom === $lec['original_room']) {
                $errors[] = 'The new room is the same as the current room.';
            } else {
                $prop['room'] = $pRoom;
            }
        } elseif ($type === 'mode_change') {
            if (!in_array($pMode, ['on_campus', 'online'], true) || $pMode === $lec['original_mode']) {
                $errors[] = 'Choose a mode different from the current one.';
            } else {
                $prop['mode'] = $pMode;
            }
        }
    }

    // Rule 3: a late request is allowed only with an emergency reason.
    if ($lec && $isLate && strlen($just) < 10) {
        $errors[] = 'This request is late (under 24 hours notice). Describe the emergency (at least 10 characters).';
    }

    if (!$errors) {
        try {
            $db->beginTransaction();
            $db->prepare(
                'INSERT INTO change_requests
                   (lecture_id, requested_by, change_type, proposed_date, proposed_start, proposed_end,
                    proposed_room, proposed_mode, reason, submitted_at, deadline_at, is_late, late_justification)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $lectureId, $uid, $type, $prop['date'], $prop['start'], $prop['end'],
                $prop['room'], $prop['mode'], $reason,
                $now->format('Y-m-d H:i:s'), $deadline->format('Y-m-d H:i:s'),
                $isLate ? 1 : 0, $isLate ? $just : null,
            ]);
            $reqId = (int)$db->lastInsertId();
            $db->prepare(
                "INSERT INTO change_history (lecture_id, change_request_id, action, performed_by, details)
                 VALUES (?, ?, 'requested', ?, ?)"
            )->execute([
                $lectureId, $reqId, $uid,
                $types[$type] . ' requested. ' . ($isLate ? 'Late notice (under 24 hours).' : 'Notice given on time.'),
            ]);
            $db->commit();
            setFlash($isLate
                ? 'Request submitted and flagged as LATE notice. It is waiting for administrator approval.'
                : 'Request submitted. It meets the 24-hour notice rule and is waiting for administrator approval.');
            header('Location: ' . $_SERVER['SCRIPT_NAME']);
            exit;
        } catch (PDOException $ex) {
            if ($db->inTransaction()) $db->rollBack();
            error_log($ex->getMessage());
            $errors[] = 'Something went wrong. Please try again.';
        }
    }
}

// Future scheduled lectures of this instructor.
$st = $db->prepare(
    "SELECT l.id, l.original_date, l.original_start, l.original_end, l.original_room, l.original_start_at,
            c.code, c.name, s.name AS section
     FROM lectures l
     JOIN courses c ON c.id = l.course_id
     JOIN sections s ON s.id = l.section_id
     WHERE l.instructor_id = ? AND l.status = 'scheduled' AND l.original_start_at > ?
     ORDER BY l.original_start_at"
);
$st->execute([$uid, $now->format('Y-m-d H:i:s')]);
$lectures = $st->fetchAll();

$st = $db->prepare(
    'SELECT cr.change_type, cr.status, cr.is_late, cr.submitted_at, c.code, l.original_date, l.original_start
     FROM change_requests cr
     JOIN lectures l ON l.id = cr.lecture_id
     JOIN courses c ON c.id = l.course_id
     WHERE cr.requested_by = ?
     ORDER BY cr.submitted_at DESC
     LIMIT 20'
);
$st->execute([$uid]);
$myRequests = $st->fetchAll();
$statusClass = ['pending' => 'warn', 'approved' => 'ok', 'rejected' => 'bad'];
$flash = takeFlash();

$pageTitle = 'Submit change request';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
  <h1>Submit a change request</h1>
  <p class="date">Requests should be sent at least 24 hours before the lecture. Students are told only after approval.</p>
</div>

<?php if ($flash): ?><div class="success mt" role="status"><?= e($flash) ?></div><?php endif; ?>
<?php if ($errors): ?>
  <div class="error mt" role="alert"><ul>
    <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
  </ul></div>
<?php endif; ?>

<section class="card form-card mt">
  <?php if (!$lectures): ?>
    <div class="empty">You have no upcoming scheduled lectures. The administrator adds lectures to the timetable.</div>
  <?php else: ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">

    <label for="lecture_id">Lecture</label>
    <select id="lecture_id" name="lecture_id" required>
      <option value="">Choose...</option>
      <?php foreach ($lectures as $l):
        $dl = (new DateTime($l['original_start_at'], $utc))->modify('-24 hours');
        $late = $now > $dl;
        $dlText = $dl->setTimezone(new DateTimeZone(APP_TIMEZONE))->format('D j M, g:i A');
        $label = $l['code'] . ' ' . $l['name'] . ', ' . fmtDate($l['original_date']) . ' ' . fmtTime($l['original_start'])
               . ' (section ' . $l['section'] . ') - ' . ($late ? 'LATE, deadline passed' : 'deadline ' . $dlText);
      ?>
        <option value="<?= (int)$l['id'] ?>" data-late="<?= $late ? '1' : '0' ?>"<?= (int)($old['lecture_id'] ?? 0) === (int)$l['id'] ? ' selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>

    <div id="late-box" class="error mt" style="display:none">
      The 24-hour deadline for this lecture has passed. You can still submit an emergency request, but it will be flagged
      as late and you must explain the emergency below.
    </div>

    <label for="change_type">Type of change</label>
    <select id="change_type" name="change_type" required>
      <option value="">Choose...</option>
      <?php foreach ($types as $k => $v): ?>
        <option value="<?= e($k) ?>"<?= ($old['change_type'] ?? '') === $k ? ' selected' : '' ?>><?= e($v) ?></option>
      <?php endforeach; ?>
    </select>

    <div data-types="reschedule">
      <label for="proposed_date">New date</label>
      <input type="date" id="proposed_date" name="proposed_date" value="<?= e($old['proposed_date'] ?? '') ?>">
    </div>
    <div class="row2" data-types="reschedule time_change">
      <div><label for="proposed_start">New start time</label><input type="time" id="proposed_start" name="proposed_start" value="<?= e($old['proposed_start'] ?? '') ?>"></div>
      <div><label for="proposed_end">New end time</label><input type="time" id="proposed_end" name="proposed_end" value="<?= e($old['proposed_end'] ?? '') ?>"></div>
    </div>
    <div data-types="room_change">
      <label for="proposed_room">New room</label>
      <input type="text" id="proposed_room" name="proposed_room" value="<?= e($old['proposed_room'] ?? '') ?>">
    </div>
    <div data-types="mode_change">
      <label for="proposed_mode">New mode</label>
      <select id="proposed_mode" name="proposed_mode">
        <option value="on_campus">On campus</option>
        <option value="online">Online</option>
      </select>
    </div>

    <label for="reason">Reason</label>
    <textarea id="reason" name="reason" required><?= e($old['reason'] ?? '') ?></textarea>

    <div id="late-field" style="display:none">
      <label for="late_justification">Emergency explanation (required for late requests)</label>
      <textarea id="late_justification" name="late_justification"><?= e($old['late_justification'] ?? '') ?></textarea>
    </div>

    <button type="submit" class="btn btn-sm">Submit request</button>
  </form>
  <?php endif; ?>
</section>

<section class="card mt">
  <h2>My requests</h2>
  <?php if (!$myRequests): ?>
    <div class="empty">You have not submitted any requests yet.</div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Lecture</th><th>Change</th><th>Status</th><th>Notice</th><th>Submitted</th></tr></thead>
        <tbody>
          <?php foreach ($myRequests as $r): ?>
            <tr>
              <td><?= e($r['code'] . ', ' . fmtDate($r['original_date']) . ' ' . fmtTime($r['original_start'])) ?></td>
              <td><?= e($types[$r['change_type']] ?? ucfirst(str_replace('_', ' ', $r['change_type']))) ?></td>
              <td><span class="badge <?= e($statusClass[$r['status']] ?? 'neutral') ?>"><?= e(ucfirst($r['status'])) ?></span></td>
              <td><?= (int)$r['is_late'] === 1 ? '<span class="badge bad">! Late</span>' : '<span class="badge ok">&#10003; On time</span>' ?></td>
              <td><?= e(toLocalTime($r['submitted_at'], 'D j M, g:i A')) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<script>
(function () {
  var type = document.getElementById('change_type');
  var lecture = document.getElementById('lecture_id');
  if (!type || !lecture) return;
  function sync() {
    document.querySelectorAll('[data-types]').forEach(function (el) {
      el.style.display = el.getAttribute('data-types').split(' ').indexOf(type.value) > -1 ? '' : 'none';
    });
    var opt = lecture.options[lecture.selectedIndex];
    var late = opt && opt.getAttribute('data-late') === '1';
    document.getElementById('late-box').style.display = late ? '' : 'none';
    document.getElementById('late-field').style.display = late ? '' : 'none';
  }
  type.addEventListener('change', sync);
  lecture.addEventListener('change', sync);
  sync();
})();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>