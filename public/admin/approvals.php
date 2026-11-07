<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

requireRole(['admin']);

$db      = getDB();
$adminId = (int)$_SESSION['user_id'];
$errors  = [];

$types = [
    'cancellation' => 'Cancellation',
    'reschedule'   => 'Reschedule',
    'time_change'  => 'Time change',
    'room_change'  => 'Room change',
    'mode_change'  => 'Mode change',
];

function describeSlot($date, $start, $end, $room, $mode) {
    return fmtDate($date) . ', ' . fmtTime($start) . ' to ' . fmtTime($end) . ', ' . $room
         . ($mode === 'online' ? ' (online)' : '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $reqId    = (int)($_POST['request_id'] ?? 0);
    $decision = $_POST['decision'] ?? '';
    $note     = trim($_POST['review_note'] ?? '');

    if (!in_array($decision, ['approve', 'reject'], true)) {
        $errors[] = 'Choose approve or reject.';
    }
    if ($decision === 'reject' && strlen($note) < 5) {
        $errors[] = 'Enter a reason for rejecting (at least 5 characters).';
    }
    if (strlen($note) > 1000) {
        $errors[] = 'The note is too long (1000 characters at most).';
    }

    if (!$errors) {
        try {
            $db->beginTransaction();

            // Lock this request so two admins cannot decide it at the same time.
            $st = $db->prepare(
                'SELECT cr.id, cr.lecture_id, cr.requested_by, cr.change_type, cr.proposed_date, cr.proposed_start, cr.proposed_end,
                        cr.proposed_room, cr.proposed_mode, cr.reason, cr.submitted_at, cr.is_late, cr.status,
                        l.course_id, l.section_id, l.original_date, l.original_start, l.original_end,
                        l.original_room, l.original_mode, l.original_start_at,
                        c.code, c.name AS course_name, u.full_name AS instructor
                 FROM change_requests cr
                 JOIN lectures l ON l.id = cr.lecture_id
                 JOIN courses c ON c.id = l.course_id
                 JOIN users u ON u.id = cr.requested_by
                 WHERE cr.id = ?
                 FOR UPDATE'
            );
            $st->execute([$reqId]);
            $r = $st->fetch();

            if (!$r) {
                $db->rollBack();
                $errors[] = 'Request not found.';
            } elseif ($r['status'] !== 'pending') {
                $db->rollBack();
                $errors[] = 'This request has already been reviewed.';
            } else {
                $now   = gmdate('Y-m-d H:i:s');
                $label = $types[$r['change_type']] ?? 'Change';

                if ($decision === 'reject') {
                    $db->prepare(
                        "UPDATE change_requests SET status = 'rejected', reviewed_by = ?, reviewed_at = ?, review_note = ? WHERE id = ?"
                    )->execute([$adminId, $now, $note, $reqId]);
                    $db->prepare(
                        "INSERT INTO change_history (lecture_id, change_request_id, action, performed_by, details)
                         VALUES (?, ?, 'rejected', ?, ?)"
                    )->execute([$r['lecture_id'], $reqId, $adminId, $label . ' rejected. Reason: ' . $note]);
                    $db->prepare('INSERT INTO notifications (user_id, change_request_id, message) VALUES (?, ?, ?)')
                       ->execute([(int)$r['requested_by'], $reqId,
                           'Your ' . strtolower($label) . ' request for ' . $r['code'] . ' on ' . fmtDate($r['original_date'])
                           . ' was rejected. Reason: ' . $note]);
                    $db->commit();
                    setFlash('Request rejected. The instructor was notified. Students were not.');
                } else {
                    $db->prepare(
                        "UPDATE change_requests SET status = 'approved', reviewed_by = ?, reviewed_at = ?, review_note = ? WHERE id = ?"
                    )->execute([$adminId, $now, $note !== '' ? $note : null, $reqId]);

                    // The lecture status changes. The original_* columns are never touched.
                    $newStatus = ['cancellation' => 'cancelled', 'reschedule' => 'rescheduled'][$r['change_type']] ?? null;
                    if ($newStatus) {
                        $db->prepare('UPDATE lectures SET status = ? WHERE id = ?')->execute([$newStatus, $r['lecture_id']]);
                    }

                    // Build the notification text.
                    $original = describeSlot($r['original_date'], $r['original_start'], $r['original_end'],
                                             $r['original_room'], $r['original_mode']);
                    if ($r['change_type'] === 'cancellation') {
                        $updated = 'Lecture cancelled';
                    } else {
                        $updated = describeSlot(
                            $r['proposed_date']  ?: $r['original_date'],
                            $r['proposed_start'] ?: $r['original_start'],
                            $r['proposed_end']   ?: $r['original_end'],
                            $r['proposed_room']  ?: $r['original_room'],
                            $r['proposed_mode']  ?: $r['original_mode']
                        );
                    }
                    $hours = (int)floor(noticeHours($r['submitted_at'], $r['original_start_at']));
                    $notice = (int)$r['is_late'] === 1 ? 'Late notice (under 24 hours).' : 'Notice given ' . $hours . ' hours ahead.';
                    $message = $r['code'] . ' ' . $r['course_name'] . ' (' . $r['instructor'] . '): ' . $label
                             . '. Was: ' . $original . '. Now: ' . $updated
                             . '. Reason: ' . $r['reason'] . '. Status: Approved. ' . $notice;

                    // One in-app notification per enrolled student of this course and section.
                    $ids = $db->prepare('SELECT student_id FROM enrollments WHERE course_id = ? AND section_id = ?');
                    $ids->execute([$r['course_id'], $r['section_id']]);
                    $students = $ids->fetchAll(PDO::FETCH_COLUMN);
                    $ins = $db->prepare('INSERT INTO notifications (user_id, change_request_id, message) VALUES (?, ?, ?)');
                    foreach ($students as $sid) {
                        $ins->execute([(int)$sid, $reqId, $message]);
                    }

                    $hist = $db->prepare(
                        'INSERT INTO change_history (lecture_id, change_request_id, action, performed_by, details)
                         VALUES (?, ?, ?, ?, ?)'
                    );
                    $hist->execute([$r['lecture_id'], $reqId, 'approved', $adminId,
                        $label . ' approved. ' . ((int)$r['is_late'] === 1 ? 'Late notice.' : 'Met the 24-hour rule.')
                        . ($note !== '' ? ' Note: ' . $note : '')]);
                    $hist->execute([$r['lecture_id'], $reqId, 'notified', $adminId,
                        count($students) . ' student(s) notified in the app']);

                    $db->prepare('INSERT INTO notifications (user_id, change_request_id, message) VALUES (?, ?, ?)')
                       ->execute([(int)$r['requested_by'], $reqId,
                           'Your ' . strtolower($label) . ' request for ' . $r['code'] . ' on ' . fmtDate($r['original_date'])
                           . ' was approved. ' . count($students) . ' student(s) were notified.'
                           . ($note !== '' ? ' Note: ' . $note : '')]);
                    $db->commit();
                    setFlash('Request approved. ' . count($students) . ' student(s) and the instructor notified.');
                }
                header('Location: ' . $_SERVER['SCRIPT_NAME']);
                exit;
            }
        } catch (PDOException $ex) {
            if ($db->inTransaction()) $db->rollBack();
            error_log($ex->getMessage());
            $errors[] = 'Something went wrong. Please try again.';
        }
    }
}

$pending = $db->query(
    "SELECT cr.id, cr.change_type, cr.proposed_date, cr.proposed_start, cr.proposed_end, cr.proposed_room,
            cr.proposed_mode, cr.reason, cr.submitted_at, cr.deadline_at, cr.is_late, cr.late_justification,
            l.original_date, l.original_start, l.original_end, l.original_room, l.original_mode,
            c.code, c.name, s.name AS section, u.full_name AS instructor,
            (SELECT COUNT(*) FROM enrollments e WHERE e.course_id = l.course_id AND e.section_id = l.section_id) AS students
     FROM change_requests cr
     JOIN lectures l ON l.id = cr.lecture_id
     JOIN courses c ON c.id = l.course_id
     JOIN sections s ON s.id = l.section_id
     JOIN users u ON u.id = cr.requested_by
     WHERE cr.status = 'pending'
     ORDER BY cr.is_late DESC, cr.submitted_at"
)->fetchAll();

$reviewed = $db->query(
    "SELECT cr.change_type, cr.status, cr.is_late, cr.reviewed_at, c.code, l.original_date, rv.full_name AS reviewer
     FROM change_requests cr
     JOIN lectures l ON l.id = cr.lecture_id
     JOIN courses c ON c.id = l.course_id
     LEFT JOIN users rv ON rv.id = cr.reviewed_by
     WHERE cr.status <> 'pending'
     ORDER BY cr.reviewed_at DESC
     LIMIT 10"
)->fetchAll();
$flash = takeFlash();

$pageTitle = 'Change approvals';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
  <h1>Change approvals</h1>
  <p class="date"><?= count($pending) ?> request(s) waiting. Students are notified only when you approve.</p>
</div>

<?php if ($flash): ?><div class="success mt" role="status"><?= e($flash) ?></div><?php endif; ?>
<?php if ($errors): ?>
  <div class="error mt" role="alert"><ul>
    <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
  </ul></div>
<?php endif; ?>

<section class="card mt">
  <h2>Pending requests</h2>
  <?php if (!$pending): ?>
    <div class="empty">Nothing is waiting for review.</div>
  <?php endif; ?>

  <?php foreach ($pending as $p):
    $late = (int)$p['is_late'] === 1;
    $original = describeSlot($p['original_date'], $p['original_start'], $p['original_end'], $p['original_room'], $p['original_mode']);
    $updated = $p['change_type'] === 'cancellation' ? 'Lecture cancelled' : describeSlot(
        $p['proposed_date']  ?: $p['original_date'],
        $p['proposed_start'] ?: $p['original_start'],
        $p['proposed_end']   ?: $p['original_end'],
        $p['proposed_room']  ?: $p['original_room'],
        $p['proposed_mode']  ?: $p['original_mode']
    );
  ?>
    <article class="notice<?= $late ? ' late' : '' ?>">
      <div class="notice-head">
        <div>
          <h3><span class="code"><?= e($p['code']) ?></span><?= e($p['name']) ?> (section <?= e($p['section']) ?>)</h3>
          <p class="meta">Requested by <?= e($p['instructor']) ?>. <?= (int)$p['students'] ?> enrolled student(s).</p>
        </div>
        <div class="badges">
          <span class="badge warn">Pending</span>
          <span class="badge neutral"><?= e($types[$p['change_type']] ?? 'Change') ?></span>
          <?php if ($late): ?><span class="badge bad">! Late notice</span><?php else: ?><span class="badge ok">&#10003; On time</span><?php endif; ?>
        </div>
      </div>
      <div class="diff-row del"><span class="sign">&minus;</span><span class="text"><?= e($original) ?></span></div>
      <div class="diff-row add"><span class="sign">+</span><span><?= e($updated) ?></span></div>
      <p class="reason"><strong>Reason:</strong> <?= e($p['reason']) ?></p>
      <?php if ($late && $p['late_justification']): ?>
        <p class="reason"><strong>Emergency explanation:</strong> <?= e($p['late_justification']) ?></p>
      <?php endif; ?>
      <p class="meta">Submitted <?= e(toLocalTime($p['submitted_at'], 'D j M, g:i A')) ?>. 24-hour deadline was <?= e(toLocalTime($p['deadline_at'], 'D j M, g:i A')) ?>.</p>

      <div class="review">
        <form method="post" data-confirm="Approve this change? Enrolled students will be notified immediately.">
          <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
          <input type="hidden" name="request_id" value="<?= (int)$p['id'] ?>">
          <input type="hidden" name="decision" value="approve">
          <label for="ap<?= (int)$p['id'] ?>">Note (optional)</label>
          <textarea id="ap<?= (int)$p['id'] ?>" name="review_note"></textarea>
          <button type="submit" class="btn btn-sm">Approve</button>
        </form>
        <form method="post" data-confirm="Reject this request? Students will not be notified.">
          <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
          <input type="hidden" name="request_id" value="<?= (int)$p['id'] ?>">
          <input type="hidden" name="decision" value="reject">
          <label for="rj<?= (int)$p['id'] ?>">Reason for rejecting</label>
          <textarea id="rj<?= (int)$p['id'] ?>" name="review_note" required></textarea>
          <button type="submit" class="btn btn-sm btn-danger">Reject</button>
        </form>
      </div>
    </article>
  <?php endforeach; ?>
</section>

<section class="card mt">
  <h2>Recently reviewed</h2>
  <?php if (!$reviewed): ?>
    <div class="empty">No requests have been reviewed yet.</div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Lecture</th><th>Change</th><th>Decision</th><th>Notice</th><th>Reviewed by</th><th>When</th></tr></thead>
        <tbody>
          <?php foreach ($reviewed as $r): ?>
            <tr>
              <td><?= e($r['code'] . ', ' . fmtDate($r['original_date'])) ?></td>
              <td><?= e($types[$r['change_type']] ?? 'Change') ?></td>
              <td><span class="badge <?= $r['status'] === 'approved' ? 'ok' : 'bad' ?>"><?= e(ucfirst($r['status'])) ?></span></td>
              <td><?= (int)$r['is_late'] === 1 ? '<span class="badge bad">! Late</span>' : '<span class="badge ok">&#10003; On time</span>' ?></td>
              <td><?= e($r['reviewer'] ?? '') ?></td>
              <td><?= e(toLocalTime($r['reviewed_at'], 'D j M, g:i A')) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<script>
// Confirmation dialog before every decision. The server still checks everything.
document.querySelectorAll('form[data-confirm]').forEach(function (f) {
  f.addEventListener('submit', function (e) {
    if (!confirm(f.getAttribute('data-confirm'))) e.preventDefault();
  });
});
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>