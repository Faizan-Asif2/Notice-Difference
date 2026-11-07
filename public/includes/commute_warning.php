<?php
// Included by home.php for students. Uses $uid and $today from home.php.
$db = getDB();

$st = $db->prepare('SELECT transport_type, bus_departure, bus_return FROM transport_settings WHERE user_id = ?');
$st->execute([$uid]);
$tr = $st->fetch() ?: ['transport_type' => 'other', 'bus_departure' => null, 'bus_return' => null];

// Latest approved change per lecture, for changes that can affect travel.
$st = $db->prepare(
    "SELECT cr.change_type, cr.proposed_date, cr.proposed_start, cr.proposed_end, cr.proposed_mode,
            l.original_date, l.original_start, l.original_end, c.code
     FROM change_requests cr
     JOIN lectures l ON l.id = cr.lecture_id
     JOIN enrollments en ON en.course_id = l.course_id AND en.section_id = l.section_id AND en.student_id = ?
     JOIN courses c ON c.id = l.course_id
     WHERE cr.status = 'approved'
       AND cr.change_type IN ('cancellation', 'reschedule', 'time_change', 'mode_change')
       AND cr.id = (SELECT MAX(x.id) FROM change_requests x WHERE x.lecture_id = l.id AND x.status = 'approved')
       AND COALESCE(cr.proposed_date, l.original_date) >= ?
     ORDER BY COALESCE(cr.proposed_date, l.original_date), COALESCE(cr.proposed_start, l.original_start)
     LIMIT 5"
);
$st->execute([$uid, $today]);
$impacts = [];

foreach ($st->fetchAll() as $r) {
    $date  = $r['proposed_date']  ?: $r['original_date'];
    $start = $r['proposed_start'] ?: $r['original_start'];
    $end   = $r['proposed_end']   ?: $r['original_end'];
    $was   = fmtDate($r['original_date']) . ', ' . fmtTime($r['original_start']);
    $conflicts = [];

    if ($r['change_type'] === 'cancellation') {
        $text = $r['code'] . ' on ' . $was . ' is cancelled. You may not need to travel for it.';
    } elseif ($r['change_type'] === 'mode_change') {
        $text = $r['code'] . ' on ' . $was . ' moves ' . ($r['proposed_mode'] === 'online' ? 'online' : 'on campus') . '.';
    } else {
        $text = $r['code'] . ' now runs ' . fmtDate($date) . ', ' . fmtTime($start) . ' to ' . fmtTime($end)
              . ' (originally ' . $was . ').';
        if ($tr['transport_type'] === 'university_bus') {
            if ($tr['bus_return'] && $end > $tr['bus_return']) {
                $conflicts[] = 'Possible conflict: the lecture ends at ' . fmtTime($end) . ', after your return bus at ' . fmtTime($tr['bus_return']) . '.';
            }
            if ($tr['bus_departure'] && $start < $tr['bus_departure']) {
                $conflicts[] = 'Possible conflict: the lecture starts at ' . fmtTime($start) . ', before your morning bus departs at ' . fmtTime($tr['bus_departure']) . '.';
            }
        }
    }
    $impacts[] = ['text' => $text, 'conflicts' => $conflicts];
}
?>
<?php if ($impacts): ?>
  <section class="card impact" aria-labelledby="h-impact">
    <h2 id="h-impact">Schedule impact</h2>
    <p class="hint">Some lectures have moved outside the original timetable. Please review your commute arrangements.</p>
    <ul>
      <?php foreach ($impacts as $i): ?>
        <li>
          <?= e($i['text']) ?>
          <?php foreach ($i['conflicts'] as $c): ?><div class="conflict">! <?= e($c) ?></div><?php endforeach; ?>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php if ($tr['transport_type'] === 'university_bus' && !$tr['bus_departure'] && !$tr['bus_return']): ?>
      <p class="hint"><a href="<?= BASE_URL ?>/student/commute.php">Add your bus times</a> to check for clashes.</p>
    <?php endif; ?>
  </section>
<?php endif; ?>