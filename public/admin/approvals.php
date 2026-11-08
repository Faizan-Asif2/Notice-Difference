<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

requireRole(['admin']);

$db      = getDB();
$adminId = (int)$_SESSION['user_id'];
$utc     = new DateTimeZone('UTC');
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
                'SELECT cr.id, cr.lecture_id, cr.requested_by, cr.change_type, cr.proposed_date, cr.proposed_start,
                        cr.proposed_end, cr.proposed_room, cr.proposed_mode, cr.reason, cr.submitted_at,
                        cr.deadline_at, cr.is_late, cr.status,
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
                $nowDt   = new DateTime('now', $utc);
                $lateNow = ((int)$r['is_late'] === 1) || ($nowDt > new DateTime($r['deadline_at'], $utc));
                $problem = '';

                // The rules are enforced here on the server, not only by the buttons.
                if ($decision === 'approve') {
                    if (new DateTime($r['original_start_at'], $utc) <= $nowDt) {
                        $problem = 'This lecture has already started, so the request cannot be approved. Reject it.';
                    } elseif ($lateNow && $r['change_type'] !== 'cancellation') {
                        $problem = 'The 24-hour deadline has passed. Only an emergency cancellation can be approved now. Reject this request.';
                    } elseif ($slot = proposedSlot($r)) {
                        $conf = slotConflicts($db, (int)$r['section_id'], $slot[0], $slot[1], $slot[2], (int)$r['lecture_id']);
                        if ($conf) {
                            $problem = 'The section is not free at the proposed time: ' . implode('; ', conflictLines($conf)) . '.';
                        }
                    }
                }

                if ($problem !== '') {
                    $db->rollBack();
                    $errors[] = $problem;
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
                        // Students are notified now. If that is after the deadline, they got late notice.
                        $db->prepare(
                            "UPDATE change_requests SET status = 'approved', reviewed_by = ?, reviewed_at = ?, review_note = ?, is_late = ? WHERE id = ?"
                        )->execute([$adminId, $now, $note !== '' ? $note : null, $lateNow ? 1 : 0, $reqId]);

                        $newStatus = ['cancellation' => 'cancelled', 'reschedule' => 'rescheduled'][$r['change_type']] ?? null;
                        if ($newStatus) {
                            $db->prepare('UPDATE lectures SET status = ? WHERE id = ?')->execute([$newStatus, $r['lecture_id']]);
                        }

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
                        $hours  = (int)floor(noticeHours($now, $r['original_start_at']));
                        $notice = $lateNow ? 'Late notice (under 24 hours).' : 'Notice given ' . $hours . ' hours ahead.';
                        $message = $r['code'] . ' ' . $r['course_name'] . ' (' . $r['instructor'] . '): ' . $label
                                 . '. Was: ' . $original . '. Now: ' . $updated
                                 . '. Reason: ' . $r['reason'] . '. Status: Approved. ' . $notice;

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
                            $label . ' approved. ' . ($lateNow ? 'Late notice.' : 'Met the 24-hour rule.')
                            . ($lateNow && (int)$r['is_late'] === 0 ? ' Approved after the 24-hour deadline.' : '')
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
            l.id AS lecture_id, l.course_id, l.section_id, l.original_date, l.original_start, l.original_end,
            l.original_room, l.original_mode, l.original_start_at,
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
$flash  = takeFlash();
$nowUtc = new DateTime('now', $utc);

$pageTitle = 'Change approvals';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
  <h1>Change approvals</h1>
  <p class="date"><?= count($pending) ?> request(s) waiting. Decide before the 24-hour deadline so students get proper notice.</p>
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
    $type      = $p['change_type'];
    $instrLate = (int)$p['is_late'] === 1;
    $deadline  = new DateTime($p['deadline_at'], $utc);
    $lateNow   = $instrLate || $nowUtc > $deadline;
    $started   = new DateTime($p['original_start_at'], $utc) <= $nowUtc;
    $slot      = proposedSlot($p);
    $conf      = $slot ? slotConflicts($db, (int)$p['section_id'], $slot[0], $slot[1], $slot[2], (int)$p['lecture_id']) : [];
    $bus       = busImpact($db, (int)$p['course_id'], (int)$p['section_id'], $slot ? $slot[1] : null, $slot ? $slot[2] : null);

    $blocked = '';
    if ($started) {
        $blocked = 'The lecture has already started.';
    } elseif ($lateNow && $type !== 'cancellation') {
        $blocked = 'Past the 24-hour deadline: only an emergency cancellation can be approved. Reject this request.';
    } elseif ($conf) {
        $blocked = 'The section already has a lecture at the proposed time.';
    }

    $original = describeSlot($p['original_date'], $p['original_start'], $p['original_end'], $p['original_room'], $p['original_mode']);
    $updated = $type === 'cancellation' ? 'Lecture cancelled' : describeSlot(
        $p['proposed_date']  ?: $p['original_date'],
        $p['proposed_start'] ?: $p['original_start'],
        $p['proposed_end']   ?: $p['original_end'],
        $p['proposed_room']  ?: $p['original_room'],
        $p['proposed_mode']  ?: $p['original_mode']
    );
    $viewDate = $slot ? $slot[0] : $p['original_date'];
  ?>
    <article class="notice<?= $lateNow ? ' late' : '' ?>">
      <div class="notice-head">
        <div>
          <h3><span class="code"><?= e($p['code']) ?></span><?= e($p['name']) ?> (section <?= e($p['section']) ?>)</h3>
          <p class="meta">Requested by <?= e($p['instructor']) ?>. <?= (int)$p['students'] ?> enrolled student(s).</p>
        </div>
        <div class="badges">
          <span class="badge warn">Pending</span>
          <span class="badge neutral"><?= e($types[$type] ?? 'Change') ?></span>
          <?php if ($instrLate): ?><span class="badge bad">! Late request</span><?php else: ?><span class="badge ok">&#10003; Submitted on time</span><?php endif; ?>
        </div>
      </div>
      <div class="diff-row del"><span class="sign">&minus;</span><span class="text"><?= e($original) ?></span></div>
      <div class="diff-row add"><span class="sign">+</span><span><?= e($updated) ?></span></div>
      <p class="reason"><strong>Reason:</strong> <?= e($p['reason']) ?></p>
      <?php if ($instrLate && $p['late_justification']): ?>
        <p class="reason"><strong>Emergency explanation:</strong> <?= e($p['late_justification']) ?></p>
      <?php endif; ?>
      <p class="meta">Submitted <?= e(toLocalTime($p['submitted_at'], 'D j M, g:i A')) ?>.</p>

      <?php if ($lateNow): ?>
        <div class="error">24-hour check: the deadline (<?= e(toLocalTime($p['deadline_at'], 'D j M, g:i A')) ?>) has passed or the request was late. Students would get under 24 hours notice. Only a cancellation can be approved.</div>
      <?php else: ?>
        <div class="success">24-hour check: approve before <?= e(toLocalTime($p['deadline_at'], 'D j M, g:i A')) ?> so students get at least 24 hours notice.</div>
      <?php endif; ?>

      <?php if ($slot): ?>
        <?php if ($conf): ?>
          <div class="error">Section check: section <?= e($p['section']) ?> is NOT free at the proposed time. Already scheduled:
            <ul><?php foreach (conflictLines($conf) as $line): ?><li><?= e($line) ?></li><?php endforeach; ?></ul>
          </div>
        <?php else: ?>
          <div class="success">Section check: section <?= e($p['section']) ?> is free at the proposed time.</div>
        <?php endif; ?>
        <p class="meta"><a href="<?= BASE_URL ?>/admin/sections.php?section=<?= (int)$p['section_id'] ?>&amp;d=<?= e($viewDate) ?>">View this section's schedule for that week</a></p>
      <?php endif; ?>

      <?php $affected = array_values(array_unique(array_merge($bus['early'], $bus['late']))); ?>
      <?php if ($bus['total'] === 0): ?>
        <p class="hint">Transport check: no students are enrolled in this section.</p>
      <?php else: ?>
        <div class="<?= $affected ? 'error' : 'success' ?>">
          Transport check: <?= (int)$bus['bus'] ?> of <?= (int)$bus['total'] ?> enrolled student(s) use the university bus
          (<?= (int)$bus['with_times'] ?> entered bus times).
          <?php if ($slot): ?>
            <?php if ($affected): ?>
              <strong><?= count($affected) ?> could be affected:</strong>
              <?= count($bus['early']) ?> would start before their morning bus and <?= count($bus['late']) ?> would finish after their return bus.
              <details>
                <summary>Show names</summary>
                <ul>
                  <?php foreach ($bus['early'] as $n): ?><li><?= e($n) ?>: lecture starts before the morning bus</li><?php endforeach; ?>
                  <?php foreach ($bus['late'] as $n): ?><li><?= e($n) ?>: lecture ends after the return bus</li><?php endforeach; ?>
                </ul>
              </details>
            <?php elseif ($bus['with_times'] > 0): ?>
              No clash with the bus times they entered.
            <?php else: ?>
              No bus times entered, so a clash cannot be checked.
            <?php endif; ?>
          <?php endif; ?>
        </div>
        <p class="hint">Advisory only. Students enter their own bus times, and NoticeDiff does not control or coordinate the buses.</p>
      <?php endif; ?>

      <div class="review">
        <form method="post" data-confirm="Approve this change? Enrolled students will be notified immediately.">
          <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
          <input type="hidden" name="request_id" value="<?= (int)$p['id'] ?>">
          <input type="hidden" name="decision" value="approve">
          <label for="ap<?= (int)$p['id'] ?>">Note (optional)</label>
          <textarea id="ap<?= (int)$p['id'] ?>" name="review_note"></textarea>
          <button type="submit" class="btn btn-sm"<?= $blocked !== '' ? ' disabled' : '' ?>>Approve</button>
          <?php if ($blocked !== ''): ?><p class="hint"><?= e($blocked) ?></p><?php endif; ?>
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
// Confirmation dialog before every decision. The server still checks every rule.
document.querySelectorAll('form[data-confirm]').forEach(function (f) {
  f.addEventListener('submit', function (e) {
    if (!confirm(f.getAttribute('data-confirm'))) e.preventDefault();
  });
});
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>