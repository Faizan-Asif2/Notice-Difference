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
        "SELECT l.id, l.section_id, l.original_date, l.original_room, l.original_mode, l.original_start_at, c.code
         FROM lectures l JOIN courses c ON c.id = l.course_id
         WHERE l.id = ? AND l.instructor_id = ? AND l.status = 'scheduled'"
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

    // Students come first (additional rule beyond the client document): for a reschedule or
    // time change, notice is measured against the EARLIER of the original start and the new start.
    if ($lec && !$errors && $prop['start'] !== null) {
        $newStartAt = new DateTime(localToUtc($prop['date'] ?: $lec['original_date'], $prop['start']), $utc);
        if ($newStartAt <= $now) {
            $errors[] = 'The new start time must be in the future.';
        } else {
            $earliest = ($newStartAt < $startAt) ? $newStartAt : $startAt;
            $deadline = (clone $earliest)->modify('-24 hours');
            $isLate   = $now > $deadline;
        }
    }

    // Emergency rule: a late request can only be a cancellation. Students are never given a new time
    // at short notice.
    if ($lec && $isLate && isset($types[$type]) && $type !== 'cancellation') {
        $errors[] = 'This request is under 24 hours notice. In an emergency the lecture can only be cancelled. '
                  . 'Choose Cancellation, or send a request at least 24 hours before the lecture (and before the new time).';
    }

    // The section must be free at the proposed time.
    if ($lec && $prop['start'] !== null) {
        $cDate = $prop['date'] ?: $lec['original_date'];
        $conf  = slotConflicts($db, (int)$lec['section_id'], $cDate, $prop['start'], $prop['end'], $lectureId);
        if ($conf) {
            $errors[] = 'The section already has a lecture at that time: ' . implode('; ', conflictLines($conf))
                      . '. Choose a time when the section is free.';
        }
    }

    // A late request needs an emergency explanation.
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

            // Tell every active administrator, inside the same transaction.
            $admins = $db->query("SELECT id FROM users WHERE role = 'admin' AND is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
            $msg = $_SESSION['full_name'] . ' requested: ' . strtolower($types[$type]) . ' for ' . $lec['code']
                 . ' on ' . fmtDate($lec['original_date']) . '. '
                 . ($isLate ? 'LATE notice (emergency cancellation). Decide as soon as possible.'
                            : 'Notice given on time.')
                 . ' Approve before ' . toLocalTime($deadline->format('Y-m-d H:i:s'), 'D j M, g:i A')
                 . ' so students get 24 hours notice.';
            $ins = $db->prepare('INSERT INTO notifications (user_id, change_request_id, message) VALUES (?, ?, ?)');
            foreach ($admins as $aid) {
                $ins->execute([(int)$aid, $reqId, $msg]);
            }
            $db->commit();
            setFlash($isLate
                ? 'Emergency cancellation submitted and flagged as LATE notice. The administrator has been notified.'
                : 'Request submitted. It meets the 24-hour notice rule. The administrator has been notified.');
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
  <p class="date">Send requests at least 24 hours before the lecture. In an emergency (under 24 hours) the lecture can only be cancelled.</p>
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
        <option value="<?= (int)$l['id'] ?>" data-date="<?= e($l['original_date']) ?>" data-late="<?= $late ? '1' : '0' ?>"<?= (int)($old['lecture_id'] ?? 0) === (int)$l['id'] ? ' selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>

    <div id="late-box" class="error mt" style="display:none">
      The 24-hour deadline for this lecture has passed. Under the policy only an emergency cancellation can be submitted now.
      It will be flagged as late and you must explain the emergency below.
    </div>
    <div id="soon-box" class="error mt" style="display:none">
      The new start time is less than 24 hours away. Students must get at least 24 hours notice of a new time,
      so choose a later time, or cancel the lecture instead.
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
  // Only a convenience. The server calculates the real result and enforces every rule.
  function sync() {
    var opt = lecture.options[lecture.selectedIndex];
    var lateOrig = !!opt && opt.getAttribute('data-late') === '1';

    // Original deadline passed: only a cancellation can be chosen.
    Array.prototype.forEach.call(type.options, function (o) {
      if (o.value && o.value !== 'cancellation') o.disabled = lateOrig;
    });
    if (lateOrig && type.value && type.value !== 'cancellation') type.value = 'cancellation';

    document.querySelectorAll('[data-types]').forEach(function (el) {
      el.style.display = el.getAttribute('data-types').split(' ').indexOf(type.value) > -1 ? '' : 'none';
    });

    // New start less than 24 hours away.
    var soon = false;
    if (!lateOrig && opt && (type.value === 'reschedule' || type.value === 'time_change')) {
      var d = type.value === 'reschedule' ? document.getElementById('proposed_date').value : opt.getAttribute('data-date');
      var t = document.getElementById('proposed_start').value;
      if (d && t) {
        var nt = new Date(d + 'T' + t);
        if (!isNaN(nt) && nt.getTime() - Date.now() < 24 * 3600 * 1000) soon = true;
      }
    }
    document.getElementById('late-box').style.display = lateOrig ? '' : 'none';
    document.getElementById('late-field').style.display = lateOrig ? '' : 'none';
    document.getElementById('soon-box').style.display = soon ? '' : 'none';
  }
  type.addEventListener('change', sync);
  lecture.addEventListener('change', sync);
  ['proposed_date', 'proposed_start'].forEach(function (id) {
    document.getElementById(id).addEventListener('input', sync);
  });
  sync();
})();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>